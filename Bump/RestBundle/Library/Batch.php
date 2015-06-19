<?php
namespace Bump\RestBundle\Library;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception;
use Bump\RestBundle\Library\Utils;
use Peekmo\JsonPath\JsonStore;

class Batch
{
    protected $kernel;
	protected $jsonStore;
    protected $jsonStoreCache=array();
    protected $jsonPathPattern = "/\{result=([^\:]+)\:([^\}]+)\}/";
    protected $headersWhiteList = array(
    	'HTTP_HOST',
    	'HTTP_CONNECTION',
    	'HTTP_USER_AGENT',
    	'HTTP_CACHE_CONTROL',
    	'HTTP_ORIGIN',
    	'HTTP_ACCEPT',
    	'HTTP_ACCEPT_ENCODING',
    	'HTTP_ACCEPT_LANGUAGE'
    );

    public function __construct(\AppKernel $kernel)
    {
    	$this->kernel = $kernel;
    }

	public function handle(Request $request, $maxRequest=10)
	{
        if (!$request->isMethod('POST')) {
            throw new Exception\MethodNotAllowedHttpException(array('POST'));
        }

        if (!($operations = $request->get('operations'))) {
            if (!($operations = $request->getContent())) {
                throw new Exception\BadRequestHttpException();
            }
        }

        try {
            $operations = Utils::parseJSON($operations, true, true);
        } catch (\Exception $e) {
            throw new Exception\BadRequestHttpException($e->getMessage());
        }

        if (!is_array($operations) || empty($operations)) {
            throw new Exception\BadRequestHttpException('Expected operations to be not empty array');
        }

        if ($maxRequest>0 && count($operations)>$maxRequest) {
            throw new Exception\BadRequestHttpException('Max operations requests count exceeded.');
        }

        $responses = array();
        foreach ($operations as $index => $operation) {
            $requestName = $index;
            $method = 'GET';
            $body = '';
            $includeHeaders = true;
            $headers = array();

            try {
                if (isset($operation['name'])) {
                    $requestName = $operation['name'];
                }

                if (isset($operation['method'])) {
                    $method = strtoupper($operation['method']);
                }

                if (isset($operation['headers'])) {
                    $headers = $operation['headers'];
                }

                if (isset($operation['include_headers'])) {
                    $includeHeaders = (bool)$operation['include_headers'];
                }

                if (empty($operation['relative_url'])) {
                    throw new Exception\BadRequestHttpException("Expected required parameter relative_url");
                }

                $relativeUrl = $operation['relative_url'];
                $relativeUrl = '/' . ltrim($relativeUrl, '/');
                if (preg_match_all($this->jsonPathPattern, $relativeUrl, $match)) {
                    for($i=0; $i<count($match[0]); $i++) {
                        $action = $match[1][$i];
                        $path = $match[2][$i];

                        if (!array_key_exists($action, $responses)) {
                            throw new Exception\BadRequestHttpException("Request with name {$action} not found or bad requests ordering.");
                        }

                        if ($responses[$action]['status']!=200 || empty($responses[$action]['body'])) {
                            throw new Exception\ConflictHttpException("Parent request is failure or body missing");
                        }

                        $jsonResult = $this->jsonStoreGet($responses[$action]['body'], $path);
                        if (empty($jsonResult)) {
                            throw new Exception\BadRequestHttpException("Can't fetch data by requested JsonPath.");
                        }
                        $relativeUrl = str_replace($match[0][$i], $jsonResult[0], $relativeUrl);
                    }
                }

                if (isset($operation['body'])) {
                    $body = $operation['body'];
                    if (preg_match_all($this->jsonPathPattern, $body, $match)) {
                        for($i=0; $i<count($match[0]); $i++) {
                            $action = $match[1][$i];
                            $path = $match[2][$i];

                            if (!array_key_exists($action, $responses)) {
                                throw new Exception\BadRequestHttpException("Request with name {$action} not found or bad requests ordering.");
                            }

                            if ($responses[$action]['status']!=200 || empty($responses[$action]['body'])) {
                                throw new Exception\ConflictHttpException("Parent request is failure or body missing");
                            }

                            $jsonResult = $this->jsonStoreGet($responses[$action]['body'], $path);
                            if (empty($jsonResult)) {
                                throw new Exception\BadRequestHttpException("Can't fetch data by requested JsonPath.");
                            }
                            $body = str_replace($match[0][$i], $jsonResult[0], $body);
                        }
                    }
                }


                $server = array();
                foreach ($_SERVER as $key => $value) {
                    if (substr($key, 0, 5) == 'HTTP_' && !in_array($key, $this->headersWhiteList)) {
                        continue;
                    }

                    $server[$key] = $value;
                }

                if (!empty($headers)) {
                    foreach ($headers as $header) {
                        if (!isset($header['name']) || !isset($header['value'])) {
                            continue;
                        }

                        $name = $header['name'];
                        if (false === strpos($name, 'HTTP_')) {
                            $name = 'HTTP_' . $name;
                        }

                        $server[$name] = $header['value'];
                    }
                }

		    	$kernel = clone $this->kernel;
                //we can use kernel without clone but it not will garantee correct work, but work faster
                // $kernel = $this->kernel;
                $parameters = array();
                if (!empty($operation['body'])) {
                    parse_str($operation['body'], $parameters);
                }

		        $req = Request::create($relativeUrl, $method, $parameters, array(), array(), $server);
		        try {
                    $response = $kernel->handle($req);

    		        $body = $response->getContent();
    		        //decoding json response
    		        /* if ($response->headers->get('Content-Type')=='application/json') {
    		        	$body = Utils::parseJSON($body, true, true);
    		        }*/

    		        $responses[$requestName] = array('status'=>$response->getStatusCode(), 'message'=>Response::$statusTexts[$response->getStatusCode()]);
    		        if ($includeHeaders) {
    		            $responses[$requestName]['headers'] = array();
    		            foreach ($response->headers->all() as $name => $values) {
    		                foreach ($values as $value) {
    		                    $responses[$requestName]['headers'][]=array('name'=>$name, 'value'=>$value);
    		                }
    		            }
    		        }

                    $responses[$requestName]['body']=$body;
                } catch (\Exception $e) {
                    $kernel->terminate($req, $response);
                    throw $e;
                }

                
				$kernel->terminate($req, $response);
                //clear entity manager, should be used if kernel not clonned.
                // $manager = $kernel->getContainer()->get('doctrine')->getManager();
                // $manager->clear();
            } catch(\Exception $e) {
                if ($e instanceof Exception\HttpExceptionInterface) {
                    $code = $e->getStatusCode();
                } else {
                    $code = $e->getCode();
                }

                $code = ($code>=400 && $code<600)?$code:500;
                $message = null;
                if ($code<500) {
                    $message = $e->getMessage();
                } else {
                    $message = Response::$statusTexts[$code];
                }

                $responses[$requestName] = array('status'=>$code, 'message'=>$message);
            }
		}

		$response = new Response(json_encode(array_values($responses)), 200, array('Content-Type'=>'application/json'));

		return $response;
	}

	protected function getJsonStore()
    {
        if (empty($this->jsonStore)) {
            $this->jsonStore = new JsonStore();
        }

        return $this->jsonStore;
    }

    protected function jsonStoreGet($json, $path)
    {
        $key = md5($path . $json);
        if (!isset($this->jsonStoreCache[$key])) {
            $data = json_decode($json, true);
            $this->jsonStoreCache[$key] = $this->getJsonStore()->get($data, $path);
        }

        return $this->jsonStoreCache[$key];
    }

    /**
     * Sets the value of headersWhiteList.
     *
     * @param mixed $headersWhiteList the headers white list
     *
     * @return self
     */
    public function setHeadersWhiteList(array $headersWhiteList)
    {
        $this->headersWhiteList = $headersWhiteList;

        return $this;
    }

    /**
     * Gets the value of headersWhiteList.
     *
     * @return mixed
     */
    public function getHeadersWhiteList()
    {
        return $this->headersWhiteList;
    }

    /**
     * Gets the value of jsonPathPattern.
     *
     * @return mixed
     */
    public function getJsonPathPattern()
    {
        return $this->jsonPathPattern;
    }

    /**
     * Sets the value of jsonPathPattern.
     *
     * @param mixed $jsonPathPattern the json path pattern
     *
     * @return self
     */
    public function setJsonPathPattern($jsonPathPattern)
    {
        $this->jsonPathPattern = $jsonPathPattern;

        return $this;
    }
}
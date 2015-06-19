<?php
namespace Bump\RestBundle\Test;

use Liip\FunctionalTestBundle\Test\WebTestCase as Base;
use Doctrine\ORM\EntityRepository;
use Bump\RestBundle\Library\Query\Processor;
use Doctrine\Common\Inflector\Inflector;
use FOS\RestBundle\View\View;

abstract class WebTestCase extends Base
{
    protected $testData = array();
    protected $filesData = array();

    protected $authentication = true;

    protected $fixtures = array();

    protected $ignoreLinks = array('remove');

    public function setUp()
    {
        $this->client = $this->makeClient(
            $this->authentication,
            array('HTTP_ACCEPT' => 'application/json')
        );
    }

    protected function assertJsonResponse($response, $statusCode = 200, $message)
    {
        $this->assertEquals(
                $statusCode, $response->getStatusCode(),
                $response->getContent()
            );

        $this->assertTrue(
                $response->headers->contains('Content-Type', 'application/json'),
                $response->headers,
                $message
            );
    }

    protected function getDoctrine()
    {
        $container = $this->getContainer();

        return $container->get('doctrine');
    }

    protected function getTestData(array $merge = null, array $data = null)
    {
        if (is_null($data)) {
            $data = $this->testData;
        }

        return is_null($merge) ? $data : array_merge($data, $merge);
    }

    protected function getFilesData()
    {
        return $this->filesData;
    }

    protected function isAuthenticationRequired($routeName, array $params = array(), $method = 'GET')
    {
        $this->assertAuthentication($routeName, $params, $method, true);
    }

    protected function isAuthenticationNotRequired($routeName, array $params = array(), $method = 'GET')
    {
        $this->assertAuthentication($routeName, $params, $method, false);
    }

    protected function assertAuthentication($routeName, array $params = array(), $method = 'GET', $required = true)
    {
        $client = $this->makeClient(false, array('HTTP_ACCEPT' => 'application/json'));
        $client->request($method, $this->getUrl($routeName, $params));
        if ($required) {
            $this->assertEquals(401, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        } else {
            $this->assertNotEquals(401, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        }
    }

    protected function handleBadRequest($url, array $badData, $method = 'POST')
    {
        $data = $this->getTestData($badData);
        $fieldNames = array_keys($badData);

        $this->client->request($method, $url, $data);
        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode(), $response->getContent());

        if (!empty($fieldNames)) {
            $data = json_decode($response->getContent(), true);
            $this->assertArrayHasKey('code', $data);
            $this->assertEquals(400, $data['code'], $response->getContent());
            $this->assertArrayHasKey('message', $data, $response->getContent());
            $this->assertArrayHasKey('errors', $data, $response->getContent());
            $this->assertArrayHasKey('children', $data['errors'], $response->getContent());
            foreach ($fieldNames as $name) {
                $this->assertArrayHasKey($name, $data['errors']['children'], $response->getContent());
                $this->assertArrayHasKey('errors', $data['errors']['children'][$name], "Field: '{$name}'".PHP_EOL.json_encode($data['errors']['children']).PHP_EOL.$response->getContent());
                $this->assertNotEmpty($data['errors']['children'][$name]['errors'], json_encode($data['errors']['children'][$name]['errors']).PHP_EOL.$response->getContent());
            }
        }
    }

    protected function handleGetSuggesion($query, $routeName, array $routeParams = array(), $limit = 10, array $fixtures = null)
    {
        if (is_null($fixtures)) {
            $fixtures = $this->fixtures;
        }

        if (!empty($fixtures)) {
            $this->loadFixtures($fixtures);
        }

        $this->client->request('GET', $this->getUrl($routeName, array_merge($routeParams, array('query' => $query, 'limit' => $limit))));

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode(), $response->getContent());

        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('suggestions', $content, $response->getContent());
        $this->assertLessThan($limit, count($content['suggestions']));
    }

    protected function handleExportData(array $supportedFormats = array(), $routeName, array $routeParams = array(), array $fixtures = null)
    {
        if (is_null($fixtures)) {
            $fixtures = $this->fixtures;
        }

        if (!empty($fixtures)) {
            $this->loadFixtures($fixtures);
        }

        foreach ($supportedFormats as $format) {
            ob_start();
            $this->client->request('GET', $this->getUrl($routeName, array_merge($routeParams, array('format' => $format))));
            $content = ob_get_clean();
            $response = $this->client->getResponse();
            $this->assertEquals(200, $response->getStatusCode(), $response->getContent());
            $this->assertArrayHasKey('content-type', $response->headers->all());
            $this->assertArrayHasKey('content-disposition', $response->headers->all());
            $this->assertEquals('application/force-download', $response->headers->get('content-type'));
            $disposition = $response->headers->get('Content-Disposition');

            /*if (preg_match('/filename="([^"]+)"/', $disposition, $match)) {
                $tmp = sys_get_temp_dir() . '/' . $match[1];
                file_put_contents($tmp, $content);

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $tmp);
            }*/
        }
    }

    protected function handleGetCollection(EntityRepository $repository, $routeName, $routeParams = array(), array $fixtures = null, $entities = null)
    {
        if (is_null($fixtures)) {
            $fixtures = $this->fixtures;
        }

        if (!empty($fixtures)) {
            $this->loadFixtures($fixtures);
        }

        if (is_null($entities)) {
            $entities = $repository->findAll();
        }

        $routeParams = array_merge(['limit' => 0], $routeParams);

        $this->client->request('GET', $this->getUrl($routeName, $routeParams));
        $response = $this->client->getResponse();

        $this->assertJsonResponse($response, 200, $response->getContent());

        $content = json_decode($response->getContent(), true);
        foreach (array('page', 'limit', 'pages', 'order_by', 'order', 'total_count', '_links', '_embedded') as $key) {
            $this->assertArrayHasKey($key, $content);
        }

        $this->assertEquals(count($entities), $content['total_count']);
        if (!empty($entities)) {
            $this->assertTrue(!empty($content['_embedded']));
            $data = reset($content['_embedded']);
            $this->assertEquals(count($data), count($entities));
        }

        //test links
        if (isset($content['_links'])) {
            $client = $this->makeClient($this->authentication, array('HTTP_ACCEPT' => 'application/json'));
            foreach ($content['_links'] as $rel => $link) {
                if (in_array($rel, $this->ignoreLinks)) {
                    continue;
                }

                $this->assertArrayHasKey('href', $link);
                $client->request('GET', $link['href']);
                $response = $client->getResponse();
                $this->assertNotEquals(404, $response->getStatusCode(), "Test link {$rel}".PHP_EOL.$response->getContent());
            }
        }

        //test pages
        $order = $content['order'] == 'asc' ? 'desc' : 'asc';
        $this->client->request('GET', $this->getUrl($routeName, array_merge($routeParams, array('limit' => 1, 'order' => $order))));
        $response = $this->client->getResponse();
        $this->assertJsonResponse($response, 200, $response->getContent());
        $content = json_decode($response->getContent(), true);

        foreach (array('page', 'limit', 'pages', 'order_by', 'order', 'total_count', '_links', '_embedded') as $key) {
            $this->assertArrayHasKey($key, $content);
        }

        $this->assertEquals(count($entities), $content['total_count']);
        $this->assertEquals(count($entities), $content['pages']);
        $this->assertEquals($order, $content['order']);

        //test filters
        if (!empty($entities)) {
            $entity = reset($entities);
            $field = $content['order_by'];
            $method = 'get'.Inflector::camelize($field);
            if (method_exists($entity, $method)) {
                $processor = new Processor($repository);
                $value = call_user_func(array($entity, $method));

                if (!is_scalar($value)) {
                    if ($value instanceof \DateTime) {
                        $value = $value->format(DATE_RFC2822);
                    } else {
                        return $response;
                    }
                }

                $filters = array(
                    'f' => $field,
                    'o' => 'eq',
                    'v' => $value,
                );

                $this->client->request('GET', $this->getUrl($routeName, array_merge($routeParams, array('limit' => 0, 'filters' => json_encode($filters)))));
                $response = $this->client->getResponse();
                $this->assertJsonResponse($response, 200, $response->getContent());

                $content = json_decode($response->getContent(), true);
                $data = reset($content['_embedded']);
                foreach ($data as $item) {
                    if (isset($item[$field])) {
                        $this->assertEquals($value, $item[$field]);
                    }
                }

                $filterGroups = $processor->normalizeFilters(json_encode($filters));
                foreach ($filterGroups as $group) {
                    $processor->applyFilters($group['filters'], $group['in'], $group['out']);
                }

                $filtered = $processor->getQuery()->getQuery()->getResult();
                $this->assertEquals(count($filtered), count($data));
            }

            //test search
            $field = null;
            $processor = new Processor($repository);
            $metadata = $processor->getMetadata();
            $mapping = $metadata->fieldMappings;
            foreach ($mapping as $name => $meta) {
                if ($meta['type'] == 'string') {
                    $field = $meta['fieldName'];
                    break;
                }
            }

            if ($field) {
                $method = 'get'.ucfirst($field);
                if (method_exists($entity, $method)) {
                    $value = call_user_func(array($entity, $method));
                    if (!empty($value) && strlen($value)>1) {
                        $value = strtolower(substr($value, 0, strlen($value)-1));

                        $this->client->request('GET', $this->getUrl($routeName, array_merge($routeParams, array('limit' => 0, 'query' => $value))));
                        $response = $this->client->getResponse();
                        $this->assertJsonResponse($response, 200, $response->getContent());
                        $content = json_decode($response->getContent(), true);
                        $data = reset($content['_embedded']);

                        $processor->search($value);
                        $searched = $processor->getQuery()->getQuery()->getResult();
                        $this->assertEquals(count($searched), count($data));
                    }
                }
            }
        }

        return $response;
    }

    protected function handleGetSingle(EntityRepository $repository, $routeName, $routeParams = array(), $max = 10, array $fixtures = null)
    {
        if (is_null($fixtures)) {
            $fixtures = $this->fixtures;
        }

        if (!empty($fixtures)) {
            $this->loadFixtures($fixtures);
        }

        $entities = $repository->findAll();

        $container = $this->getContainer();

        $viewHandler = $container->get('fos_rest.view_handler');
        $serializer = $container->get('fos_rest.serializer');

        for ($i = 0; $i<$max && $i<count($entities); $i++) {
            $entity = $entities[$i];
            $route =  $this->getUrl($routeName, array_merge(array('id' => $entity->getId(), '_format' => 'json'), $this->normalizeRouteParams($entity, $routeParams)));

            $this->client->request('GET', $route);

            $response = $this->client->getResponse();
            $content = $response->getContent();

            $view = View::create($entity);
            $res = $viewHandler->createResponse($view, $this->client->getRequest(), 'json');

            $this->assertJsonResponse($response, 200, $response->getContent());
            $this->assertEquals($res->getContent(), $content);

            //test links
            $content = json_decode($content, true);
            if (isset($content['_links'])) {
                $client = $this->makeClient($this->authentication, array('HTTP_ACCEPT' => 'application/json'));
                foreach ($content['_links'] as $rel => $link) {
                    if (in_array($rel, $this->ignoreLinks)) {
                        continue;
                    }

                    $this->assertArrayHasKey('href', $link);
                    $client->request('GET', $link['href']);
                    $response = $client->getResponse();
                    $this->assertNotEquals(404, $response->getStatusCode(), "Test link {$rel}".PHP_EOL.$response->getContent());
                }
            }
        }
    }

    protected function normalizeRouteParams($entity, array $routeParams)
    {
        $normalized = array();
        foreach ($routeParams as $name => $param) {
            $value = $param;
            if ($param instanceof \Closure) {
                $value = call_user_func($param, $entity);
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }

    protected function handlePost(EntityRepository $repository, $routeName, $routeParams = array(), array $data = array(), array $fixtures = null)
    {
        if (is_null($fixtures)) {
            $fixtures = $this->fixtures;
        }

        if (!empty($fixtures)) {
            $this->loadFixtures($fixtures);
        }

        $this->client->request('POST', $this->getUrl($routeName, $routeParams), $data, $this->getFilesData());

        $response = $this->client->getResponse();
        $this->assertEquals(201, $response->getStatusCode(), $response->getContent());
        $this->assertArrayHasKey('location', $response->headers->all());

        $location = $response->headers->get('location');
        $this->client->request('GET', $location);
        $response = $this->client->getResponse();
        $this->assertJsonResponse($response, 200, $response->getContent());
        $content = $response->getContent();
        $data = json_decode($content, true);
        if (isset($data['id'])) {
            $entity = $repository->find($data['id']);
            $this->assertNotNull($entity);
        }

        return $data;
    }

    protected function handlePut(EntityRepository $repository, $postRouteName, $postRouteParams = array(), $putRouteName, $putRouteParams = array(), array $data, array $fixtures = null)
    {
        return $this->handlePPD('PUT', $repository, $postRouteName, $postRouteParams, $putRouteName, $putRouteParams, $data, $fixtures);
    }

    protected function handlePatch(EntityRepository $repository, $postRouteName, $postRouteParams = array(), $putRouteName, $putRouteParams = array(), array $data, array $fixtures = null)
    {
        return $this->handlePPD('PATCH', $repository, $postRouteName, $postRouteParams, $putRouteName, $putRouteParams, $data, $fixtures);
    }

    protected function handleDelete(EntityRepository $repository, $postRouteName, $postRouteParams = array(), $putRouteName, $putRouteParams = array(), array $data, array $fixtures = null)
    {
        return $this->handlePPD('DELETE', $repository, $postRouteName, $postRouteParams, $putRouteName, $putRouteParams, $data, $fixtures);
    }

    protected function handlePPD($method, EntityRepository $repository, $postRouteName, $postRouteParams = array(), $routeName, $routeParams = array(), array $data = array(), array $fixtures = null)
    {
        if (is_null($fixtures)) {
            $fixtures = $this->fixtures;
        }

        $method = strtoupper($method);
        $this->assertContains($method, array('PUT', 'PATCH', 'DELETE'));

        if (!empty($fixtures)) {
            $this->loadFixtures($fixtures);
        }

        $created = $this->handlePost($repository, $postRouteName, $postRouteParams, $data, $fixtures);
        if ($created && isset($created['id'])) {
            $entity = $repository->find($created['id']);
            $this->assertNotNull($entity);
            $this->client->request($method, $this->getUrl($routeName, array_merge(array('id' => $entity->getId()), $routeParams)), $data);
            $response = $this->client->getResponse();
            $this->assertEquals(204, $response->getStatusCode(), $response->getContent());

            $this->assertArrayHasKey('location', $response->headers->all());

            $location = $response->headers->get('location');
            $this->client->request('GET', $location);
            $response = $this->client->getResponse();
            $this->assertJsonResponse($response, 200, $response->getContent());
        }

        $this->client->request($method, $this->getUrl($routeName, array_merge(array('id' => rand(100000, 5000000)), $routeParams)), $data);
        $this->assertEquals(404, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        return $created;
    }

    public function __call($name, $args)
    {
        if (preg_match('/^isauthentication(get|post|put|patch|delete|link|unlink)(required|notrequired)$/', strtolower($name), $match)) {
            $method = strtoupper($match[1]);
            if (!in_array($method, array('GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'LINK', 'UNLINK'))) {
                throw new \RuntimeException("Invalid HTTP method: {$method}");
            }

            if (count($args) == 2) {
                $args[] = $method;
            } elseif (count($args) == 1) {
                $args[] = array();
                $args[] = $method;
            } else {
                throw new \InvalidArgumentException("Invalid method parameters count");
            }

            $method = 'isAuthentication';
            if ($match[2] == 'required') {
                $method .= 'Required';
            } else {
                $method .= 'NotRequired';
            }

            return call_user_func_array(array($this, $method), $args);
        }
    }

    protected function getRepo($entitityId)
    {
        return $this->getDoctrine()->getRepository($entitityId);
    }
}

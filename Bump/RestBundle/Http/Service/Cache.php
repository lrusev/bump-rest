<?php
namespace Bump\RestBundle\Http\Service;

use FOS\RestBundle\View\View;
use Symfony\Component\HttpFoundation\Response;
use DateTime;
use InvalidArgumentException;

class Cache
{
    protected $container;
    protected $handler;
    protected $defaultFormat = 'json';

    public function __construct($container, $handler, $defaultFormat = null)
    {
        $this->container = $container;
        $this->handler = $handler;

        if (!is_null($defaultFormat)) {
            $this->defaultFormat = $defaultFormat;
        }
    }

    public function handle(View $view, $public = false)
    {
        $request = $this->container->get('request');
        $format = $view->getFormat() ? $view->getFormat() : $request->getRequestFormat();

        if ($format == 'html' && !$view->getTemplate()) {
            //in case if template not specified and called from browser set json format by default
            $view->setFormat($this->defaultFormat);
        }

        $response = $this->handler->handle($view);

        return $this->handleResponse($response, $public);
    }

    public function handleResponse(Response $response, $public = false, $lastModified = null, $maxAge = 0)
    {
        if (!$response->isSuccessful()) {
            return $response;
        }

        $request = $this->container->get('request');
        $content = $response->getContent();
        $etag = md5($content);
        $etags = $request->getEtags();

        if (!empty($etags)) {
            $filtered = $this->filterEtags($etags);
            if ((false !== ($original = array_search($etag, $filtered)) || in_array('*', $etags))) {
                $response->setNotModified();
                if ($original) {
                    $response->setEtag($original);
                } else {
                    $response->setEtag($etag);
                }
            } else {
                $response->setEtag($etag);
            }
        } else {
            $response->setETag($etag);
        }

        if ($public) {
            $response->setPublic();
        } else {
            $response->setPrivate();
        }
        
        $current = $response->getMaxAge();
        if (empty($current)) {
            $response->setMaxAge($maxAge);   
        }

        if (is_null($lastModified)) {
            $lastModified = new DateTime();
            $lastModified->modify("-1 hour");
        } else {
            if (is_string($lastModified)) {
                $date = new DateTime();
                if (false !== ($timestamp = strtotime($lastModified))) {
                    $date->setTimestamp($timestamp);
                } else {
                    $date->modify($lastModified);
                }
                $lastModified = $date;
            } elseif (!$lastModified instanceof DateTime) {
                throw new InvalidArgumentException("Invalid lastModified argument");
            }

            if (empty($etags)) {
                $modifiedSince = $request->headers->get('If-Modified-Since');
                if (strtotime($modifiedSince) >= $lastModified->getTimestamp()) {
                    $response->setNotModified();
                }
            }
        }

        $response->setLastModified($lastModified);

        return $response;
    }

    public function filterEtags(array $etags)
    {
        $filtered = array();
        foreach ($etags as $original) {
            $tag = $original;
            if (($pos = strpos($original, '-gzip'))) {
                $tag = substr($original, 0, $pos);
            }

            $filtered[$original] = trim($tag, '" ');
        }

        return $filtered;
    }
}

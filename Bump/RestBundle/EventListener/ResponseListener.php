<?php

namespace Bump\RestBundle\EventListener;

use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpFoundation\Response;
use FOS\RestBundle\Util\Codes;

class ResponseListener
{
    private $container;

    public function __construct($container)
    {
        $this->container = $container;
    }

    public function onKernelResponse(FilterResponseEvent $event)
    {
        $response  = $event->getResponse();
        if ($this->container->get('request')->getRequestFormat() != 'html' && $response->getStatusCode() == Codes::HTTP_UNAUTHORIZED && $response->headers->has('www-authenticate')) {
            $response->headers->set('WWW-Authenticate', sprintf('Custom realm="Secured API"'));
        }
    }
}

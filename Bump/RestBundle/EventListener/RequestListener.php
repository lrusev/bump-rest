<?php
namespace Bump\RestBundle\EventListener;

use Symfony\Component\HttpKernel\Event\GetResponseEvent;

class RequestListener
{
    public function __construct($container)
    {
        $this->container = $container;
    }

    public function onKernelRequest(GetResponseEvent $event)
    {
        $this->setBaseUri();
    }

    private function setBaseUri()
    {
        //setup base url
        if ($this->container->hasParameter('bump_rest.base_uri')) {
            $baseUrl = trim($this->container->getParameter('bump_rest.base_uri', '/'));
            if ($baseUrl !== '/') {
                $context = $this->container->get('router')->getContext();
                $context->setBaseUrl($baseUrl.$context->getBaseUrl());
            }
        }
    }
}

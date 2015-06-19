<?php

namespace Bump\RestBundle\View;

use FOS\RestBundle\View\ViewHandler;
use FOS\RestBundle\View\View;
use Symfony\Component\HttpFoundation\Request;
use FOS\RestBundle\View\JsonpHandler as Base;

class JsonpHandler extends Base
{
    /**
     * Handles wrapping a JSON response into a JSONP response
     *
     * @param ViewHandler $handler
     * @param View    $view
     * @param Request $request
     * @param string  $format
     *
     * @return Response
     */
    public function createResponse(ViewHandler $handler, View $view, Request $request, $format)
    {
        $response = parent::createResponse($handler, $view, $request, $format);
        if ($response->isSuccessful()) {
            //override response content type to be readable in browser
            $response->headers->set('Content-Type', 'application/javascript');
        }

        return $response;
    }
}

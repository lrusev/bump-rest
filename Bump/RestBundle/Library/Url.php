<?php

namespace Bump\RestBundle\Library;

use Symfony\Component\Routing\RequestContext as Context;

trait Url
{
    private static $baseUrl;

    public function getUrl(Context $context, $path = null)
    {
        if (is_null(self::$baseUrl)) {
            $scheme = '';
            $schemeAuthority = '';
            if ($host = $context->getHost()) {
                $scheme = $context->getScheme();
            }

            $port = '';
            if ('http' === $scheme && 80 != $context->getHttpPort()) {
                $port = ':'.$context->getHttpPort();
            } elseif ('https' === $scheme && 443 != $context->getHttpsPort()) {
                $port = ':'.$context->getHttpsPort();
            }

            $baseUrl = $context->getBaseUrl();
            if (!empty(pathinfo($baseUrl, PATHINFO_EXTENSION))) {
                $baseUrl = dirname($baseUrl);
            }
            $schemeAuthority .= "{$scheme}://".$host.$port.rtrim($baseUrl, '/');

            self::$baseUrl = $schemeAuthority;
        }

        if (is_null($path)) {
            return self::$baseUrl;
        }

        return self::$baseUrl.'/'.ltrim($path, '/');
    }
}

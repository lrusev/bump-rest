<?php

namespace Bump\RestBundle\Library;


trait PathNormalizer
{
    protected function normalizePath($path, $checkExists = true, array $alternateParams = array())
    {
        if (preg_match_all("/(%[^%]+%)/", $path, $matches)) {
            foreach ($matches[1] as $placeholder) {
                $param = trim($placeholder, '%');
                if (isset($alternateParams[$param])) {
                    $path = str_replace($placeholder, $alternateParams[$param], $path);
                }

                if ($this->container->hasParameter($param)) {
                    $path = str_replace($placeholder, $this->container->getParameter($param), $path);
                }
            }
        }

        if ($checkExists && !file_exists($path)) {
            throw new \RuntimeException("File '{$path}' not found.");
        }

        return $path;
    }
}

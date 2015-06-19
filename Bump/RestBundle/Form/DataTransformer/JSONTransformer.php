<?php

namespace Bump\RestBundle\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Doctrine\Common\Persistence\ObjectManager;
use Bump\RestBundle\Library\Utils;

class JSONTransformer implements DataTransformerInterface
{
    /**
     * @var ObjectManager
     */
    private $om;

    public function transform($data)
    {
        if (null === $data) {
            return "";
        }

        return json_encode($data);
    }

    public function reverseTransform($json)
    {
        if (!$json) {
            return;
        }

        if (!is_string($json)) {
            return $json;
        }

        try {
            $data = Utils::parseJSON($json, true, true);
        } catch (\Exception $e) {
            throw new TransformationFailedException($e->getMessage());
        }

        return $data;
    }
}

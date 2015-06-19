<?php

namespace Bump\RestBundle\Form\DataTransformer;

use Symfony\Component\Form\CallbackTransformer;

class BooleanTransformer extends CallbackTransformer
{
    public function __construct($defaultValue = null)
    {
        parent::__construct(
            function ($value) {
                return intval($value);
            },
            function ($value) use ($defaultValue) {
                if (is_null($value)) {
                    return $defaultValue;
                }

                $value = strtolower($value);

                if ($value == 1 || $value == 'on' || $value == 'true') {
                    $value = true;
                } else {
                    $value = false;
                }

                return $value;
            }
        );
    }
}

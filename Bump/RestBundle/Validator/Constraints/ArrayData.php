<?php

namespace Bump\RestBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class ArrayData extends Constraint
{
    public $message = 'Invalid Array format: %string%';
    public $multiple = false;
    public $keys = array();
    public $strict = true;
}
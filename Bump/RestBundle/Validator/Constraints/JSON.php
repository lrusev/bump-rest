<?php

namespace Bump\RestBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class JSON extends Constraint
{
    public $message = 'Invalid JSON string: %string%';
    public $jsonArray = false;
    public $requiredKeys = array();

    public function __construct($options = null)
    {
        if (is_array($options) && 1 === count($options) && isset($options['value'])) {
            $options = $options['value'];
        }

        if (null !== $options && !is_array($options)) {
            $options = array(
                'jsonArray' => $options,
            );
        }

        parent::__construct($options);
    }
}
<?php

namespace Bump\RestBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Bump\RestBundle\Library\Utils;

class ArrayDataValidator extends ConstraintValidator
{

    public function validate($value, Constraint $constraint)
    {
        if (null === $value) {
            return;
        }
        
        $strict = $constraint->strict;
        $keys = $constraint->keys;
        $multiple = $constraint->multiple;

        if (!is_array($value)) {
            $this->context->buildViolation($constraint->message)
                                 ->setParameter('%string%', sprintf("Expected array type, instead %s taken", gettype($value)))
                                 ->addViolation();
            return;
        }

        if (!empty($value)) {
            if ($multiple && Utils::isAssoc($value)) {
                $this->context->buildViolation($constraint->message)
                              ->setParameter('%string%', "Expected array of items, instead single assoc array taken")
                              ->addViolation();
                return;
            } else if (!$multiple && !Utils::isAssoc($value)) {
                $this->context->buildViolation($constraint->message)
                              ->setParameter('%string%', "Expected single array, instead multiple assoc array taken")
                              ->addViolation();
                return;
            }

            if (!empty($keys)) {
                if ($multiple) {
                    foreach ($value as $key => $item) {
                        foreach ($keys as $name) {
                            if (!array_key_exists($name, $item)) {
                                $this->context->buildViolation($constraint->message)
                                              ->setParameter('%string%', "Expected for required key: '{$name}'")
                                              ->addViolation();
                                return;
                            }
                        }
                        if ($strict && count($item)!=count($keys)) {
                            $this->context->buildViolation($constraint->message)
                                              ->setParameter('%string%', sprintf("Array shouldn't contain extra key, only [%s] instead", join(", ", $keys)))
                                              ->addViolation();
                            return;
                        }
                    }
                } else {
                   foreach ($keys as $name) {
                        if (!array_key_exists($name, $value)) {
                            $this->context->buildViolation($constraint->message)
                                          ->setParameter('%string%', "Expected for required key {$name}")
                                          ->addViolation();
                            return;
                        }
                    }
                    if ($strict && count($value)!=count($keys)) {
                        $this->context->buildViolation($constraint->message)
                                          ->setParameter('%string%', sprintf("Array shouldn't contain extra key, only [%s] instead", join(", ", $keys)))
                                          ->addViolation();
                        return;
                    } 
                }
            }
        }
    }
}
<?php

namespace Bump\RestBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Bump\RestBundle\Library\Utils;

class JSONValidator extends ConstraintValidator
{

    public function validate($value, Constraint $constraint)
    {
        try {
            $data = Utils::parseJSON($value, true, true);
            if (!empty($data)) {
                if ($constraint->jsonArray && Utils::isAssoc($data)) {
                    $this->context->buildViolation($constraint->message)
                         ->setParameter('%string%', "Expected array object taken.")
                         ->addViolation();
                } else if ($constraint->jsonArray && !empty($constraint->requiredKeys)) {
                    foreach($data as $item) {
                        $valid = true;
                        if (count($constraint->requiredKeys) != count($item)) {
                            $valid = false;
                        } else {
                            foreach($constraint->requiredKeys as $key) {
                                if (!array_key_exists($key, $item)) {
                                    $valid = false;
                                    break;
                                }
                            }
                        }
                        
                        if (!$valid) {
                            $this->context->buildViolation($constraint->message)
                                 ->setParameter('%string%', "Invalid array format expected keys: " . join(', ', $constraint->requiredKeys))
                                 ->addViolation();
                            break;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('%string%', $e->getMessage())
                ->addViolation();
        }
    }
}
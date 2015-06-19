<?php

namespace Bump\RestBundle\Form;

use Symfony\Component\Form\FormTypeInterface;

interface CustomParserSupport extends FormTypeInterface
{
    public function getDefaults();
    public function getRequired();
    public function getAllowedTypes();
}
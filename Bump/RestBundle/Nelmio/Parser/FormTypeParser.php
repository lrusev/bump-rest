<?php
namespace Bump\RestBundle\Nelmio\Parser;

use Nelmio\ApiDocBundle\DataTypes;
use Nelmio\ApiDocBundle\Parser\FormTypeParser as Base;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\FormFactoryInterface;
use Bump\RestBundle\Form\CustomParserSupport;
use Symfony\Component\Form\Exception\UnexpectedTypeException;
use Symfony\Component\Form\Exception\InvalidArgumentException;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\ResolvedFormTypeInterface;

class FormTypeParser extends Base
{
    protected $container;

    public function __construct(FormFactoryInterface $formFactory, Container $container)
    {
        $this->container = $container;
        parent::__construct($formFactory, false);
    }

    /**
     * {@inheritdoc}
     */
    public function supports(array $item)
    {
        $className = $item['class'];

        try {
            if ($this->createForm($className)) {
                return true;
            }
        } catch (FormException $e) {
            return false;
        } catch (MissingOptionsException $e) {
            return false;
        }

        return false;
    }

    private function createForm($item)
    {
        if ($this->implementsType($item)) {
            $type = $this->getTypeInstance($item);
            return $this->factory($type);
        }

        try {
            return $this->formFactory->create($item, null, $this->bumpGetOptions($item));
        } catch (UnexpectedTypeException $e) {
            // nothing
        } catch (InvalidArgumentException $e) {
            // nothing
        } catch(\Exception $e) {
            // nothing
        }
    }

    private function factory(\Symfony\Component\Form\AbstractType $type)
    {
        $options = array();
        if ($type instanceof CustomParserSupport) {
            $required = $type->getRequired();
            if (!empty($required)) {
                $allowed = $type->getAllowedTypes();
                foreach($required as $name) {
                    $reqType = $allowed[$name];
                    switch($reqType) {
                        case 'Doctrine\Common\Persistence\ObjectManager':
                            $value = $this->container->get('Doctrine')->getManager();
                        break;
                        default:
                            throw new UnexpectedTypeException("Can't resolve {$reqType}");
                    }
                    $options[$name] = $value;
                }
            }
        }

        return $this->formFactory->create($type, null, empty($options)?$this->bumpGetOptions(get_class($type)):$options);
    }

    protected function bumpGetOptions($item)
    {
        $options = array();
        if (is_string($item)) {
            $className = $item;
        } else {
            $className = $item['class'];
        }

        if (property_exists($className, '_options_required')) {
            $_options_required = $className::$_options_required;
            if (is_array($_options_required)) {
                foreach ($_options_required as $k=>$option) {
                    @list($service, $call) = explode('->', $option, 2);
                    if ($service && $call) {
                        $options[$k] = $this->container->get($service)->$call();
                    } elseif (!$call) {
                        $options[$k] = $this->container->getParameter($service);
                    }
                }
            }
        }

        if (isset($item['groups']) && !empty($item['groups'])) {
            $options['validation_groups'] = $item['groups'];
        }

        return $options;
    }

    private function getTypeInstance($type)
    {
        $refl = new \ReflectionClass($type);
        $constructor = $refl->getConstructor();

        // this fallback may lead to runtime exception, but try hard to generate the docs
        if ($constructor && $constructor->getNumberOfRequiredParameters() > 0) {
            return $refl->newInstanceWithoutConstructor();
        }

        return $refl->newInstance();
    }

    private function implementsType($item)
    {
        if (!class_exists($item)) {
            return false;
        }

        $refl = new \ReflectionClass($item);
        return $refl->implementsInterface('Symfony\Component\Form\FormTypeInterface') || $refl->implementsInterface('Symfony\Component\Form\ResolvedFormTypeInterface');
    }

    public function parse(array $item)
    {
        $type = $item['class'];

        if ($this->implementsType($type)) {
            $type = $this->getTypeInstance($type);
        }

        $form = $this->factory($type);

        $dataClass = $form->getConfig()->getOption('data_class');
        $dataParameters = array();
        if (!empty($dataClass)) {
            try {

                $parsers = array(
                    'nelmio_api_doc.parser.validation_parser',
                    'nelmio_api_doc.parser.jms_metadata_parser'
                );

                foreach($parsers as $id) {
                    $parser = $this->container->get($id);
                    $dataParameters = $this->mergeParameters($dataParameters, $parser->parse(array('class'=>$dataClass, 'groups'=>isset($item['groups'])?$item['groups']:null)));
                }

            } catch (\Exception $e) {
                //silence
            }
        }

        $name = array_key_exists('name', $item) ? $item['name'] : $form->getName();

        $options = null;

        if (empty($name)) {
            return $this->parseForm($form, $dataParameters);
        }

        $subType = is_object($type) ? get_class($type) : $type;

        if (class_exists($subType)) {
            $parts = explode('\\', $subType);
            $dataType = sprintf('object (%s)', end($parts));
        } else {
            $dataType = sprintf('object (%s)', $subType);
        }

        return array(
            $name => array(
                'required'    => true,
                'readonly'    => false,
                'description' => '',
                'default'     => null,
                'dataType'    => $dataType,
                'actualType'  => DataTypes::MODEL,
                'subType'     => $subType,
                'children'    => $this->parseForm($form, $dataParameters),
            ),
        );
    }

    private function parseForm($form, array $dataParameters=array())
    {
        $parameters = array();
        foreach ($form as $name => $child) {
            $config     = $child->getConfig();
            $bestType   = '';
            $actualType = null;
            $subType    = null;
            $children   = null;

            for ($type = $config->getType();
                 $type instanceof FormInterface || $type instanceof ResolvedFormTypeInterface;
                 $type = $type->getParent()
            ) {
                if (isset($this->mapTypes[$type->getName()])) {
                    $bestType   = $this->mapTypes[$type->getName()];
                    $actualType = $bestType;
                } elseif ('collection' === $type->getName()) {
                    if (is_string($config->getOption('type')) && isset($this->mapTypes[$config->getOption('type')])) {
                        $subType    = $this->mapTypes[$config->getOption('type')];
                        $actualType = DataTypes::COLLECTION;
                        $bestType   = sprintf('array of %ss', $subType);
                    } else {
                        // Embedded form collection
                        $embbededType       = $config->getOption('type');
                        $subForm    = $this->formFactory->create($embbededType, null, $config->getOption('options', array()));
                        $children   = $this->parseForm($subForm);
                        $actualType = DataTypes::COLLECTION;
                        $subType    = is_object($embbededType) ? get_class($embbededType) : $embbededType;

                        if (class_exists($subType)) {
                            $parts = explode('\\', $subType);
                            $bestType = sprintf('array of objects (%s)', end($parts));
                        } else {
                            $bestType = sprintf('array of objects (%s)', $subType);
                        }
                    }
                }
            }

            if ('' === $bestType) {
                if ($type = $config->getType()) {
                    if ($type = $type->getInnerType()) {
                        /**
                         * TODO: Implement a better handling of unsupported types
                         * This is just a temporary workaround for don't breaking docs page in case of unsupported types
                         * like the entity type https://github.com/nelmio/NelmioApiDocBundle/issues/94
                         */
                        $addDefault = false;
                        try {
                            $subForm       = $this->formFactory->create($type);
                            $subParameters = $this->parseForm($subForm, $dataParameters);

                            if (!empty($subParameters)) {
                                $children = $subParameters;
                                $config   = $subForm->getConfig();
                                $subType  = get_class($type);
                                $parts    = explode('\\', $subType);
                                $bestType = sprintf('object (%s)', end($parts));

                                $parameters[$name] = array(
                                    'propertyPath'=> ($config->getPropertyPath())?(string)$config->getPropertyPath():$name,
                                    'dataType'    => $bestType,
                                    'actualType'  => DataTypes::MODEL,
                                    'default'     => null,
                                    'subType'     => $subType,
                                    'required'    => $config->getRequired(),
                                    'description' => ($config->getOption('description')) ? $config->getOption('description'):$config->getOption('label'),
                                    'readonly'    => $config->getDisabled(),
                                    'children'    => $children,
                                );

                            } else {
                                $addDefault = true;
                            }
                        } catch (\Exception $e) {
                            $addDefault = true;
                        }

                        if ($addDefault) {
                            $parameters[$name] = array(
                                'propertyPath'=> ($config->getPropertyPath())?(string)$config->getPropertyPath():$name,
                                'dataType'    => 'string',
                                'actualType'  => 'string',
                                'default'     => $config->getData(),
                                'required'    => $config->getRequired(),
                                'description' => ($config->getOption('description')) ? $config->getOption('description'):$config->getOption('label'),
                                'readonly'    => $config->getDisabled(),
                            );
                        }
                        continue;
                    }
                }
            }

            $parameters[$name] = array(
                'propertyPath'=> ($config->getPropertyPath())?(string)$config->getPropertyPath():$name,
                'dataType'    => $bestType,
                'actualType'  => $actualType,
                'subType'     => $subType,
                'default'     => $config->getData(),
                'required'    => $config->getRequired(),
                'description' => ($config->getOption('description')) ? $config->getOption('description'):$config->getOption('label'),
                'readonly'    => $config->getDisabled(),
            );

            if (null !== $children) {
                $parameters[$name]['children'] = $children;
            }

            switch ($bestType) {
                case 'datetime':
                    if (($format = $config->getOption('date_format')) && is_string($format)) {
                        $parameters[$name]['format'] = $format;
                    } elseif ('single_text' == $config->getOption('widget') && $format = $config->getOption('format')) {
                        $parameters[$name]['format'] = $format;
                    }
                    break;

                case 'date':
                    if (($format = $config->getOption('format')) && is_string($format)) {
                        $parameters[$name]['format'] = $format;
                    }
                    break;

                case 'choice':
                    if ($config->getOption('multiple')) {
                        $parameters[$name]['dataType'] = sprintf('array of %ss', $parameters[$name]['dataType']);
                        $parameters[$name]['actualType'] = DataTypes::COLLECTION;
                        $parameters[$name]['subType'] = DataTypes::ENUM;
                    }

                    if (($choices = $config->getOption('choices')) && is_array($choices) && count($choices)) {
                        $parameters[$name]['format'] = json_encode($choices);
                    } elseif (($choiceList = $config->getOption('choice_list')) && $choiceList instanceof ChoiceListInterface) {
                        $choices = $this->handleChoiceListValues($choiceList);
                        if (is_array($choices) && count($choices)) {
                            $parameters[$name]['format'] = json_encode($choices);
                        }
                    }
                    break;
            }
        }

        foreach ($parameters as $name => $params) {
            $paramsKey = $name;
            if (!isset($dataParameters[$paramsKey])) {
                $paramsKey = isset($params['propertyPath'])?$params['propertyPath']:$name;
            }

            if (isset($dataParameters[$paramsKey])) {
                // var_dump($paramsKey, $dataParameters[$paramsKey], $params);
                foreach ($dataParameters[$paramsKey] as $key => $value) {
                   if (in_array($key, array('children'))) {
                       continue;
                   }

                   if ($key == 'dataType' && (false!==strpos($value, 'object') || $value!=$parameters[$name]['actualType'])) {
                       continue;
                   }

                   if ($key == 'description' && !empty($value) && !empty($params[$key])) {
                       $parameters[$name][$key] = rtrim($value, '.') . '.' . PHP_EOL . rtrim($params[$key], '.'). '.' ;
                       continue;
                   }

                   if ($key == 'required' && $params[$key] === false) {
                       continue;
                   }

                   $parameters[$name][$key] = (!empty($value) || !isset($params[$key]))?$value:$params[$key];
                }
            }
        }

        return $parameters;
    }

    protected function mergeParameters($p1, $p2)
    {
        $params = $p1;

        foreach ($p2 as $propname => $propvalue) {
            if (!isset($p1[$propname])) {
                $params[$propname] = $propvalue;
            } else {
                $v1 = $p1[$propname];

                foreach ($propvalue as $name => $value) {
                    if (is_array($value)) {
                        if (isset($v1[$name]) && is_array($v1[$name])) {
                            $v1[$name] = $this->mergeParameters($v1[$name], $value);
                        } else {
                            $v1[$name] = $value;
                        }
                    } elseif (!is_null($value)) {
                        if (in_array($name, array('required', 'readonly'))) {
                            $v1[$name] = $v1[$name] || $value;
                        } elseif (in_array($name, array('requirement'))) {
                            if (isset($v1[$name])) {
                                $v1[$name] .= ', ' . $value;
                            } else {
                                $v1[$name] = $value;
                            }
                        } elseif ($name == 'default') {
                            $v1[$name] = $value ?: $v1[$name];
                        } else {
                            $v1[$name] = $value;
                        }
                    }
                }

                $params[$propname] = $v1;
            }
        }

        return $params;
    }
}

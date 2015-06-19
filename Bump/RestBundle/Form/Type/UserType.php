<?php
namespace Bump\RestBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Form\CallbackTransformer;
use Bump\KeywordsBundle\DBAL\Types\CallbackMethod;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;


class UserType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array                $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('username', 'text', array(
            'description'=>'Username unique value',
            'required'=>true
        ))->add('email', 'email', array(
            'description'=>'User unique email address',
            'required'=>true
        ))->add($builder->create('is_active', 'text', array(
            'required'=>false,
            'data'=>true,
            'property_path'=>'isActive'
        ))->addModelTransformer(
            new CallbackTransformer(
                    function($value) {
                        return intval($value);
                    },
                    function($value) {
                        if (is_null($value)) {
                            return false;
                        }

                        $value = strtolower($value);

                        if ($value == 1 || $value == 'on' || $value == 'true') {
                            $value = true;
                        } else {
                            $value = false;
                        }

                        return $value;
                    }
                )
            )
        );
    }

    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'csrf_protection'   => false
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return '';
    }
}

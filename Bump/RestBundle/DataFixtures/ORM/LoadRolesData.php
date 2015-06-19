<?php

/*
Example of role fixture
To use copy file to your dir and rename namespaces accordingly
-----------------------------------------------------
namespace Bump\KeywordsBundle\DataFixtures\ORM;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Bump\KeywordsBundle\Entity\Role;


class LoadRolesData extends AbstractFixture implements OrderedFixtureInterface
{
    public static $roles = array(
        'Admin'=>'ROLE_ADMIN',
        'User'=>'ROLE_USER',
        'Super Admin'=>'ROLE_SUPER_ADMIN',
        'Switch Allow'=>'ROLE_ALLOWED_TO_SWITCH'
    );

    public function load(ObjectManager $manager)
    {
        foreach(self::$roles as $name=>$roleName) {
            $role = new Role();
            $role->setName($name)
                 ->setRole($roleName);
            $manager->persist($role);

            $this->setReference($name, $role);
        }

        $manager->flush();
    }

    public function getOrder()
    {
        return 1;
    }
}*/
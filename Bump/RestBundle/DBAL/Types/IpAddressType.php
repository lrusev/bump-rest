<?php

namespace Bump\RestBundle\DBAL\Types;

use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Platforms\AbstractPlatform;

class IpAddressType extends Type 
{
    const IPADDRESS = 'ipaddress';
    
    public function getName()
    {
        return self::IPADDRESS;
    }    

    public function getSqlDeclaration(array $fieldDeclaration, AbstractPlatform $platform)
    {
        if ($platform->getName()=='postgresql') {
            return 'INET';
        }

        if ($platform->getName() == 'sqlite') {
            return 'TEXT';
        }

        return $platform->getIntegerTypeDeclarationSQL($fieldDeclaration);
    }

    public function canRequireSQLConversion()
    {
        return true;
    }

    public function convertToPHPValueSQL($sqlExpr, $platform)
    {
        if ($platform->getName()=='postgresql') {
            return sprintf('TEXT(%s)', $sqlExpr);
        }

        if ($platform->getName() == 'sqlite') {
            return sprintf('%s', $sqlExpr);
        }

        return sprintf('INET_NTOA(%s)', $sqlExpr);
    }

    public function convertToDatabaseValueSQL($sqlExpr, AbstractPlatform $platform)
    {   
        if ($platform->getName()=='postgresql') {
            return sprintf('%s', $sqlExpr);
        }

        if ($platform->getName() == 'sqlite') {
            return sprintf('%s', $sqlExpr);
        }

        return sprintf('INET_ATON(%s)', $sqlExpr);
    }
}

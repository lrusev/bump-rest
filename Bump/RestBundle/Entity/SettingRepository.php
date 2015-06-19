<?php

namespace Bump\RestBundle\Entity;

use Doctrine\ORM\EntityRepository;

class SettingRepository extends EntityRepository
{
    public function findOneByNameOrSlug($nameOrAlias)
    {
        return $this->createQueryBuilder('s')
            ->select('s')
            ->where('s.name = :na OR s.slug = :na')
            ->setParameter('na', $nameOrAlias)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findAllByNameOrSlug(array $namesOrAliases)
    {
        return $this->createQueryBuilder('s')
                    ->select('s')
                    ->where('s.name IN (:na) OR s.slug IN (:na)')
                    ->setParameter('na', $namesOrAliases)
                    ->getQuery()
                    ->getResult();
    }

    public function getCacheIds($entity)
    {
        return array();
    }
}

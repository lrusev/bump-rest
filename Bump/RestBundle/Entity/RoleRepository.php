<?php

namespace Bump\RestBundle\Entity;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NoResultException;


class RoleRepository extends EntityRepository
{
    public function findWhereRolesIn(array $roles, $indexBy=null)
    {
        $qb = $this->createQueryBuilder('r');
        $qb->add('where', $qb->expr()->in('r.role', '?1'))
           ->setParameter(1, $roles);

        $result =  $qb->getQuery()->getResult();
        if (is_null($indexBy)) {
            return $result;
        }

        $indexed = array();
        $method = 'get' . ucfirst($indexBy);
        foreach($result as $role) {
            $value = call_user_func(array($role, $method));
            $indexed[$value] = $role;
        }

        return $indexed;
    }
}

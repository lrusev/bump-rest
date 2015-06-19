<?php

namespace Bump\RestBundle\EventListener\Doctrine;
use Doctrine\ORM\Event\OnFlushEventArgs;
use \Exception;

class OnFlushListener {

    public function onFlush(OnFlushEventArgs $eventArgs)
    {
        $em = $eventArgs->getEntityManager();
        $uow = $em->getUnitOfWork();


        $scheduledEntityChanges = array(
            'insert' => $uow->getScheduledEntityInsertions(),
            'update' => $uow->getScheduledEntityUpdates(),
            'delete' => $uow->getScheduledEntityDeletions()
        );

        $cacheIds = array();
        var_dump(get_class_methods($em->getConfiguration()->getResultCacheImpl()), $em->getConfiguration()->getResultCacheImpl()->getNamespace(), $em->getConfiguration()->getResultCacheImpl()->delete('test'));
        foreach ($scheduledEntityChanges as $change => $entities) {
            foreach($entities as $entity) {
                var_dump($entity);exit;
                // $cacheIds = array_merge($cacheIds, $this->getCacheIdsForEntity($entity, $change));
            }
        }

        if (count($cacheIds) == 0) {
            return;
        }

        $cacheIds = array_unique($cacheIds);

        $resultCache = $em->getConfiguration()->getResultCacheImpl();
        array_map(array($resultCache, 'delete'), $cacheIds);


        var_dump('on Flush');
        exit;
    }
}
<?php
namespace Bump\RestBundle\Library;

use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;

class Paginator
{
    private $count;
    private $currentPage;
    private $totalPages;
    private $items;


    /**
    * paginate results
    *
    * @param $query - naming is a bit off as it can be a NativeQuery OR QueryBuilder, we'll survive eventually
    * @param int $page
    * @param $limit
    * @return array
    */
    public function paginate($query, $limit, $page = 1)
    {
        $page = (int)$page;
        $limit = (int)$limit;

        if ($page<=0 || $limit===0) {
            $page = 1;
        }

        // setting current page
        $this->currentPage = $page;
        // set the limit
        // this covers the NativeQuery case
        if (is_a($query, '\Doctrine\ORM\NativeQuery')) {
            // do a count for all query, create a separate NativeQuery only for that
            $sqlInitial = $query->getSQL();

            $rsm = new ResultSetMappingBuilder($query->getEntityManager());
            $rsm->addScalarResult('count', 'count');

            $sqlCount = 'select count(*) as count from (' . $sqlInitial . ') as item';
            $qCount = $query->getEntityManager()->createNativeQuery($sqlCount, $rsm);
            $qCount->setParameters($query->getParameters());

            $resultCount = (int)$qCount->getSingleScalarResult();
            $this->count = $resultCount;

            // then, add the limit - paginate for current page
            if ($limit>0) {
                $query->setSQL($query->getSQL() . ' limit ' . (($page - 1) * $limit) . ', ' . $limit);
            } else {
                $this->totalPages = 1;
            }
        } else if (is_a($query, 'Doctrine\ORM\Query')) {
            // do a count for all query, create a separate NativeQuery only for that
            $sqlInitial = $query->getSQL();

            $rsm = new ResultSetMappingBuilder($query->getEntityManager());
            $rsm->addScalarResult('count', 'count');

            $sqlCount = 'select count(*) as count from (' . $sqlInitial . ') as item';
            $qCount = $query->getEntityManager()->createNativeQuery($sqlCount, $rsm);
            $qCount->setParameters($query->getParameters());

            $resultCount = (int)$qCount->getSingleScalarResult();
            $this->count = $resultCount;

            if ($limit>0) {
                $query = $query->setFirstResult(($page -1) * $limit)->setMaxResults($limit);
            } else {
                $this->totalPages = 1;
            }
        } else if(is_a($query, '\Doctrine\ORM\QueryBuilder')) {
            // this covers the QueryBuilder case, turning it into Query
            $cloneQuery = clone $query;
            $selectParts = $query->getDQLPart('select');
            $selectParts = $selectParts[0];
            $selectParts = $selectParts->getParts();
            $cloneQuery->select('count (' . $selectParts[0] . ')')
                       ->setFirstResult(null)
                       ->setMaxResults(null);

            $cloneQuery->resetDQLPart('orderBy');
            $this->count = (int)$cloneQuery->getQuery()->getSingleScalarResult();

            // set limit and offset, getting the query out of queryBuilder
            if ($limit>0) {
                $query = $query->setFirstResult(($page -1) * $limit)->setMaxResults($limit);
            }

            $query = $query->getQuery();
        } else {
            throw new \InvalidArgumentException("Unsupported query instance");
        }

        // set total pages
        if ($limit>0) {
            $this->totalPages = ceil($this->count / $limit);
        } else {
            $this->totalPages = 1;
        }

        return $query->getResult();
    }

    /**
    * get current page
    *
    * @return int
    */
    public function getCurrentPage()
    {
        return $this->currentPage;
    }

    /**
    * get total pages
    *
    * @return int
    */
    public function getTotalPages()
   {
       return $this->totalPages;
   }

   /**
   * get total result count
   *
   * @return int
   */
   public function getCount()
   {
   return $this->count;
   }
}
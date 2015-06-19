<?php
namespace Bump\RestBundle\Library;

use Doctrine\ORM\EntityRepository;
use Hateoas\Representation\CollectionRepresentation;

class RestfulPaginator extends Paginator
{
    protected $repository;
    protected $route;
    protected $queryProcessor;
    protected $postFetchFilter = array();
    protected $data;


    public function __construct(EntityRepository $repository, $queryAlias = 'alias')
    {
        $this->repository = $repository;
        $this->queryProcessor = new Query\Processor($repository, $queryAlias);
    }

    public function addPostFetchFilter(\Closure $callback)
    {
        if (false === array_search($callback, $this->postFetchFilter, true)) {
            $this->postFetchFilter[] = $callback;
        }

        return $this;
    }

    public function getQuery()
    {
        return $this->queryProcessor->getQuery();
    }

    public function fetch($page=1, $limit=0)
    {
        $this->data = $this->paginate($this->getQuery(), $limit, $page);

        if (!empty($this->postFetchFilter)) {
                foreach($this->postFetchFilter as $filter) {
                    $this->data = call_user_func($filter, $this->data);
                }
            }

        return $this->data;
    }

    public function getData()
    {
        if (is_null($this->data)) {
            throw new \LogicException("Paginate should be call first.");
        }

        return $this->data;
    }

    public function getRepresentation(
        $limit,
        $page,
        $route,
        array $routeParams=array(),
        $absolutePath = false,
        $rel=null,
        $xmlElement=null,
        $pageParameter = 'page',
        $limitParameter = 'limit'
        )
    {
        $data = $this->fetch($page, $limit);

        $paginatedCollection = new PaginatedRepresentation(
                new CollectionRepresentation($data, $rel, $xmlElement),
                $route,
                $routeParams,
                $this->getCurrentPage(),
                $limit, // limit
                $this->getTotalPages(),
                $pageParameter,  // page route parameter name, optional, defaults to 'page'
                $limitParameter, // limit route parameter name, optional, defaults to 'limit'
                $absolutePath
        );

        $paginatedCollection->setOrderBy($this->queryProcessor->getOrderField())
                            ->setOrder($this->queryProcessor->getOrder())
                            ->setQuery($this->getSearchQuery())
                            ->setTotalCount($this->getCount())
                            ->setFilters($this->queryProcessor->getAppliedFilters());

        return $paginatedCollection;
    }

    public function __call($name, $args) {
        if (method_exists($this->queryProcessor, $name)) {
            return call_user_func_array(array($this->queryProcessor, $name), $args);
        }

        throw new \BadMethodCallException("Undefined method {$name}");
    }
}
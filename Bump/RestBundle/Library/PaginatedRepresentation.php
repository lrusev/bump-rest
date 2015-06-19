<?php
namespace Bump\RestBundle\Library;

use Hateoas\Representation\PaginatedRepresentation as Base;
use JMS\Serializer\Annotation as Serializer;

class PaginatedRepresentation extends Base
{
    /**
     * @var string
     *
     * @Serializer\Expose
     * @Serializer\XmlAttribute
     */
    private $query;

    /**
     * @var string
     *
     * @Serializer\Expose
     * @Serializer\XmlAttribute
     */
    private $orderBy;
    /**
     * @var string
     *
     * @Serializer\Expose
     * @Serializer\XmlAttribute
     */
    private $order;

    /**
     * @var int
     *
     * @Serializer\Expose
     * @Serializer\XmlAttribute
     */
    private $totalCount;

    /**
     * @var array
     *
     * @Serializer\Expose
     * @Serializer\XmlAttribute
     */
    private $filters;
    /**
     * Filters paramete name
     * @Serializer\Exclude
     * @var string
     */
    private $filtersParameterName = 'filters';

    public function getParameters($page = null, $limit = null)
    {
        $parameters = parent::getParameters($page, $limit);

        if (!empty($this->order)) {
            $parameters['order']  = $this->order;
        }

        if (!empty($this->orderBy)) {
            $parameters['order_by'] = $this->orderBy;
        }

        if (!empty($this->filters)) {
            $parameters['filters'] = json_encode($this->filters);
        }

        if (!empty($this->query)) {
            $parameters['query'] = $this->query;
        }

        return $parameters;
    }

    public function setOrderBy($orderBy)
    {
        $this->orderBy = $orderBy;

        return $this;
    }

    public function setOrder($order)
    {
        $this->order = $order;

        return $this;
    }

    public function setQuery($query)
    {
        $this->query = $query;

        return $this;
    }

    public function setTotalCount($count)
    {
        $this->totalCount = (int) $count;

        return $this;
    }

    public function getTotalCount()
    {
        return $this->totalCount;
    }

    public function setFilters(array $filters = null)
    {
        $this->filters = empty($filters) ? null : $filters;

        return $this;
    }

    public function getFilters()
    {
        return $this->filters;
    }

    /**
     * @param  null  $page
     * @param  null  $limit
     * @return array
     */
    /*public function getParameters($page = null, $limit = null, array $filters=array())
    {
        $parameters = parent::getParameters($page, $limit);

        $filters = (empty($filters)? $this->getFilters() : $filters);
        if (!empty($filters)) {
            $parameters[$this->filtersParameterName]= json_encode($filters);
        }

        return $parameters;
    }*/

    /**
     * Sets the Filters paramete name.
     *
     * @param string $filtersParameterName the filters parameter name
     *
     * @return self
     */
    public function setFiltersParameterName($filtersParameterName)
    {
        $this->filtersParameterName = $filtersParameterName;

        return $this;
    }

    /**
     * Gets the Filters paramete name.
     *
     * @return string
     */
    public function getFiltersParameterName()
    {
        return $this->filtersParameterName;
    }
}

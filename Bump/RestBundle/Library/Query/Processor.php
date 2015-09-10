<?php
namespace Bump\RestBundle\Library\Query;

use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\ORM\EntityRepository;
use Bump\RestBundle\Library\Utils;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use RuntimeException;
use InvalidArgumentException;
use Doctrine\ORM\QueryBuilder;

class Processor
{
    const OPERATION_EQ = 'eq';
    const OPERATION_LT = 'lt';
    const OPERATION_GT = 'gt';
    const OPERATION_LTE = 'lte';
    const OPERATION_GTE = 'gte';
    const OPERATION_NEQ = 'neq';
    const OPERATION_IN = 'in';
    const OPERATION_NOT_IN = 'notIn';
    const OPERATION_LIKE = 'like';
    const OPERATION_BETWEEN = 'between';
    const OPERATION_START = 'start';
    const OPERATION_END = 'end';
    const OPERATION_IS_NULL = 'isNull';
    const OPERATION_IS_NOT_NULL = 'isNotNull';

    const CONJUNCTION_OR = 'orx';
    const CONJUNCTION_AND = 'andx';

    protected $repository;
    protected $query;
    protected $queryAlias = 'a';
    protected $orderBy = 'id';
    protected $order = 'asc';
    protected $mapping;
    protected $metadata;
    protected $searchQuery;
    protected $filteredFields = array();
    protected $appliedFilters = array();
    protected $fallbackMetadata;
    protected static $operations = array(
        self::OPERATION_EQ ,
        self::OPERATION_NEQ ,
        self::OPERATION_LT ,
        self::OPERATION_GT,
        self::OPERATION_LTE,
        self::OPERATION_GTE,
        self::OPERATION_IN,
        self::OPERATION_NOT_IN,
        self::OPERATION_LIKE,
        self::OPERATION_BETWEEN,
        self::OPERATION_START,
        self::OPERATION_END,
        self::OPERATION_IS_NULL,
        self::OPERATION_IS_NOT_NULL,
    );

    public function __construct(EntityRepository $repository, $queryAlias = 'alias')
    {
        $this->setRepository($repository)
             ->setQueryAlias($queryAlias);
    }

    public static function getOperationsList()
    {
        return self::$operations;
    }

    public function getQuery()
    {
        if (empty($this->query)) {
            $alias = $this->getQueryAlias();
            $this->query = $this->repository->createQueryBuilder($alias)
                                            ->select($alias);
        }

        return $this->query;
    }

    //alias
    public function qb()
    {
        return $this->getQuery();
    }

    public function applyFilters($filters, $conjuction = self::CONJUNCTION_AND, $outConjunction = self::CONJUNCTION_OR)
    {
        if (!in_array($conjuction, array(self::CONJUNCTION_AND, self::CONJUNCTION_OR))) {
            throw new InvalidFilter("Invalid Conjunction Argument.");
        }

        $this->appliedFilters[] = $filters;
        $fields = array_values($this->getColumnsMapping());
        $qb = $this->getQuery();
        $metadata = $this->getMetadata();

        $textUnit = "CHAR";
        $platform = $qb->getEntityManager()->getConnection()->getDatabasePlatform();
        if ($platform instanceof PostgreSqlPlatform) {
            $textUnit = "TEXT";
        }

        $alias = $this->getQueryAlias();
        $expressions = array();
        $iterrationIndex = 0;
        foreach ($filters as $filter) {
            $iterrationIndex++;

            $filter = array_merge(array('field' => null, 'operation' => null, 'value' => null), $filter);
            extract($filter);
            if (is_null($field) || is_null($operation)) {
                throw new InvalidFilter("Invalid Filter parameter.");
            }

            if (!is_array($field)) {
                $field = $this->getFieldName($field, true);
            }

            if (empty($field) || is_array($field)) {
                $fields = $filter['field'];
                if (!is_array($fields)) {
                    $fields = array($fields);
                }

                $meta = $parentAlias = $joinAlias = null;
                $countFields = count($fields);
                for ($index = 0; $index<$countFields; $index++) {
                    $last = ($index == $countFields-1);
                    $field = $fields[$index];
                    if ($last && is_array($field)) {
                        $expressions[] = $this->expr($qb, $joinAlias, $field['field'], $field['operation'], $field['value'], $iterrationIndex+$index, $textUnit, (isset($field['meta'])?$field['meta']:null));
                        if (!$last) {
                            continue;
                        }

                        break;
                    }

                    //handle fallback join
                    if (is_array($field) && isset($field['fallbackJoin'])) {
                        $tmp = $field;
                        $field = $field['inversedBy'];
                        $meta = $tmp;
                    } else {
                        $meta = $this->getAssocFieldName($field, $meta, true);
                    }

                    if (!$meta) {
                        throw new InvalidFilter("Invalid field '{$parentAlias}.{$field}' presented in filters.");
                    }

                    $parentAlias = $joinAlias;
                    if (is_null($parentAlias)) {
                        $parentAlias = $alias;
                    }
                    $joinAlias = $field.'_';
                    $joinPart = $qb->getDQLPart('join');
                    $join = null;
                    if (isset($joinPart[$alias])) {
                        $joined = array_filter(
                            $joinPart[$alias],
                            function ($join) use ($joinAlias) {
                                return $join->getAlias() == $joinAlias;
                            }
                        );
                        $join = reset($joined);
                    }

                    if (isset($meta['sourceToTargetKeyColumns'])) {
                        $sourceToTargetKeyColumns = reset($meta['sourceToTargetKeyColumns']);
                    } elseif (isset($meta['relationToTargetKeyColumns'])) {
                        $sourceToTargetKeyColumns = reset($meta['relationToTargetKeyColumns']);
                    } else {
                        throw new InvalidFilter("Invalid filter '{$parentAlias}.{$field}' presented in filters.");
                    }

                    if (empty($join)) {
                        if (isset($meta['fallbackJoin']) && isset($meta['targetToSourceKeyColumns'])) {
                            $paramName = "{$joinAlias}{$meta['fallbackField']['field']}{$iterrationIndex}";

                            $qb->innerJoin(
                                "{$parentAlias}.{$field}",
                                $joinAlias,
                                'WITH',
                                $qb->expr()->eq("{$joinAlias}.{$meta['fallbackField']['field']}", ":{$paramName}")
                            );

                            $qb->setParameter($paramName, $meta['fallbackField']['value']);
                            continue;
                        }

                        if ($last) {
                            $paramName = "{$joinAlias}{$field}{$iterrationIndex}";
                            switch ($operation) {
                                case self::OPERATION_START:
                                    $value = strtolower($value)."%";
                                    $qb->innerJoin("{$parentAlias}.{$field}", $joinAlias);
                                    $expressions[] = $qb->expr()->like("LOWER({$joinAlias}.{$sourceToTargetKeyColumns})", ":{$paramName}");
                                    break;
                                case self::OPERATION_END:
                                    $value = "%".strtolower($value);
                                    $qb->innerJoin("{$parentAlias}.{$field}", $joinAlias);
                                    $expressions[] = $qb->expr()->like("LOWER({$joinAlias}.{$sourceToTargetKeyColumns})", ":{$paramName}");
                                    break;
                                case self::OPERATION_LIKE:
                                    $value = "%".strtolower($value)."%";
                                    $qb->innerJoin("{$parentAlias}.{$field}", $joinAlias);
                                    $expressions[] = $qb->expr()->like("LOWER({$joinAlias}.{$sourceToTargetKeyColumns})", ":{$paramName}");
                                    break;
                                default:
                                    $qb->innerJoin("{$parentAlias}.{$field}", $joinAlias);
                                    $expressions[] = $qb->expr()->eq("{$joinAlias}.{$sourceToTargetKeyColumns}", ":{$paramName}");
                            }

                            $qb->setParameter($paramName, $value);
                        } else {
                            $qb->innerJoin("{$parentAlias}.{$field}", $joinAlias);
                        }
                    } elseif ($last) {
                        $paramName = "{$joinAlias}{$field}{$iterrationIndex}";
                        switch ($operation) {
                            case self::OPERATION_START:
                                $value = strtolower($value)."%";
                                $expressions[] = $qb->expr()->like("LOWER({$joinAlias}.{$sourceToTargetKeyColumns})", ":{$paramName}");
                                break;
                            case self::OPERATION_END:
                                $value = "%".strtolower($value);
                                $expressions[] = $qb->expr()->like("LOWER({$joinAlias}.{$sourceToTargetKeyColumns})", ":{$paramName}");
                                break;
                            case self::OPERATION_LIKE:
                                $value = "%".strtolower($value)."%";
                                $expressions[] = $qb->expr()->like("LOWER({$joinAlias}.{$sourceToTargetKeyColumns})", ":{$paramName}");
                                break;
                            default:
                                $expressions[] = $qb->expr()->eq("{$joinAlias}.{$sourceToTargetKeyColumns}", ":{$paramName}");
                        }
                        $qb->setParameter($paramName, $value);
                    }
                }
                continue;
            }

            $expressions[] = $this->expr($qb, $alias, $field, $operation, $value, $iterrationIndex, $textUnit);
        }

        if (!empty($expressions)) {
            $expr = call_user_func_array(array($qb->expr(), $conjuction), $expressions);
            if ($outConjunction == self::CONJUNCTION_AND) {
                $qb->andWhere($expr);
            } elseif ($outConjunction == self::CONJUNCTION_OR) {
                $qb->orWhere($expr);
            } else {
                throw new RuntimeException("Invalid out conjunction {$outConjunction}");
            }
        }

        /*print_r($qb->getQuery()->getSQL());
        var_dump($qb->getParameters());
        var_dump($expressions);
        die;*/

        return $this;
    }

    private function expr(QueryBuilder $qb, $alias, $field, $operation, $value, $uniqueId, $textUnit, array $meta = null)
    {
        $expression = null;
        if (is_null($meta)) {
            $meta = $this->getMetadata()->getFieldMapping($field);
        }

        if (in_array($operation, array(self::OPERATION_IS_NULL, self::OPERATION_IS_NOT_NULL))) {
            switch ($operation) {
                case self::OPERATION_IS_NULL:
                    $expression = $qb->expr()->isNull("{$alias}.{$field}");
                    break;
                case self::OPERATION_IS_NOT_NULL:
                    $expression = $qb->expr()->isNotNull("{$alias}.{$field}");
                    break;
            }


            return $expression;
        }

        switch ($meta['type']) {
            case 'boolean':
                if (!in_array($operation, array(self::OPERATION_EQ, self::OPERATION_NEQ))) {
                    throw new InvalidFilter("Operator '{$operation}' doesn not support for field '{$field}'.");
                }

                $value = (bool) $value;
                switch ($operation) {
                    case self::OPERATION_EQ:
                        $expression = $qb->expr()->eq("{$alias}.{$field}", ":{$field}{$uniqueId}");
                        break;
                    default:
                        $expression = $qb->expr()->neq("{$alias}.{$field}", ":{$field}{$uniqueId}");
                        break;
                }
                $qb->setParameter("{$field}{$uniqueId}", $value);
                break;
            case 'ipaddress':
                if (!in_array($operation, array(self::OPERATION_LIKE, self::OPERATION_START, self::OPERATION_END))) {
                    throw new InvalidFilter("Operator '{$operation}' doesn not support for field '{$field}'.");
                }

                $value = strtolower($value);
                if ($platform instanceof PostgreSqlPlatform) {
                    $expression = $qb->expr()->like("CAST({$alias}.{$field} AS {$textUnit})", ":{$field}{$uniqueId}");
                } else {
                    $expression = $qb->expr()->like("INET_NTOA({$alias}.{$field})", ":{$field}{$uniqueId}");
                }

                switch ($operation) {
                    case self::OPERATION_START:
                        $qb->setParameter("{$field}{$uniqueId}", "{$value}%");
                        break;
                    case self::OPERATION_END:
                        $qb->setParameter("{$field}{$uniqueId}", "%{$value}");
                        break;
                    default:
                        $qb->setParameter("{$field}{$uniqueId}", $value);
                }
                break;
            case 'string':
            case 'text':
                if (!in_array($operation, array(self::OPERATION_EQ, self::OPERATION_LIKE, self::OPERATION_START, self::OPERATION_END))) {
                    throw new InvalidFilter("Operator '{$operation}' doesn not support for field '{$field}'.");
                }

                $value = strtolower($value);
                if ($operation === self::OPERATION_EQ) {
                    $expression = $qb->expr()->eq("LOWER({$alias}.{$field})", ":{$field}{$uniqueId}");
                } else {
                    $expression = $qb->expr()->like("LOWER({$alias}.{$field})", ":{$field}{$uniqueId}");
                }

                switch ($operation) {
                    case self::OPERATION_START:
                        $qb->setParameter("{$field}{$uniqueId}", "{$value}%");
                        break;
                    case self::OPERATION_END:
                        $qb->setParameter("{$field}{$uniqueId}", "%{$value}");
                        break;
                    case self::OPERATION_LIKE:
                        $qb->setParameter("{$field}{$uniqueId}", "%{$value}%");
                        break;
                    default:
                        $qb->setParameter("{$field}{$uniqueId}", $value);
                }
                break;
            case 'integer':
            case 'float':
            case 'decimal':
                if (in_array($operation, array(self::OPERATION_BETWEEN))) {
                    throw new InvalidFilter("Operator '{$operation}' doesn not support for field '{$field}'.");
                }

                switch ($operation) {
                    case self::OPERATION_START:
                        $expression = $qb->expr()->like("CAST({$alias}.{$field} AS {$textUnit})", ":{$field}{$uniqueId}");
                        $qb->setParameter("{$field}{$uniqueId}", "{$value}%");
                        break;
                    case self::OPERATION_END:
                        $expression = $qb->expr()->like("CAST({$alias}.{$field} AS {$textUnit})", ":{$field}{$uniqueId}");
                        $qb->setParameter("{$field}{$uniqueId}", "%{$value}");
                        break;
                    case self::OPERATION_END:
                        $expression = $qb->expr()->like("CAST({$alias}.{$field} AS {$textUnit})", ":{$field}{$uniqueId}");
                        $qb->setParameter("{$field}{$uniqueId}", $value);
                        break;
                    default:
                        $expression = call_user_func_array(array($qb->expr(), $operation), array("{$alias}.{$field}", ":{$field}{$uniqueId}"));
                        $qb->setParameter("{$field}{$uniqueId}", $value);
                }
                break;
            case 'datetime':
                if (in_array($operation, array(self::OPERATION_LIKE, self::OPERATION_START, self::OPERATION_END))) {
                    throw new InvalidFilter("Operator '{$operation}' doesn not support for field '{$field}'.");
                }

                if ($operation === self::OPERATION_BETWEEN) {
                    if (!is_array($value)) {
                        throw new InvalidFilter("Expected for between parameter to be 'array'.");
                    }

                    if (count($value) !== 2) {
                        throw new InvalidFilter("Wrong between parameters count, epxected 2.");
                    }

                    try {
                        $x = new \DateTime($value[0]);
                        $y = new \DateTime($value[1]);
                    } catch (\Exception $e) {
                        throw new InvalidFilter("Invalid date format");
                    }

                    $expression = $qb->expr()->between("{$alias}.{$field}", ":{$field}{$uniqueId}x", ":{$field}{$uniqueId}y");

                    $qb->setParameter("{$field}{$uniqueId}x", $x);
                    $qb->setParameter("{$field}{$uniqueId}y", $y);
                } else {
                    if (!$value instanceof \DateTime) {
                        try {
                            $value = new \DateTime($value);
                        } catch (\Exception $e) {
                            throw new InvalidFilter("Invalid date format");
                        }
                    }
                    $expression = call_user_func_array(array($qb->expr(), $operation), array("{$alias}.{$field}", ":{$field}{$uniqueId}"));
                    $qb->setParameter("{$field}{$uniqueId}", $value);
                }
                break;
        }

        return $expression;
    }

    public function getAppliedFilters()
    {
        return $this->appliedFilters;
    }

    public function normalizeFilters($filters, $defaultInConjunction = self::CONJUNCTION_AND, $defaultOutConjunction = self::CONJUNCTION_OR)
    {
        try {
            $filters  = Utils::parseJSON($filters, true, true);
            if (is_null($filters)) {
                return array();
            }
        } catch (\Exception $e) {
            throw new InvalidFilter("Invalid Filter Parameter, expected valid JSON (Error: {$e->getMessage()})");
        }

        if (Utils::isAssoc($filters)) {
            if (!isset($filters['filters'])) {
                $filters = array(array('filters' => array($filters)));
            }
        } else {
            $item = reset($filters);
            if (!isset($item['filters'])) {
                $filters = array(array('filters' => $filters));
            }
        }

        $metadata = $this->getMetadata();
        $associationMapping = $metadata->getAssociationMappings();
        $associationMappingByColumns = array();
        foreach ($associationMapping as $meta) {
            if (!isset($meta['joinColumns'])) {
                continue;
            }
            $joinColumns = reset($meta['joinColumns']);
            $associationMappingByColumns[$joinColumns['name']] = $meta['fieldName'];
        }

        $groups = $filters;
        $result = array();
        $operations = self::getOperationsList();
        for ($i = 0; $i<count($groups); $i++) {
            $assoc = false;
            $normalized = array();
            /*if (isset($groups[$i])) {
                throw new InvalidFilter("Invalid filter group");
            }*/

            $group = $groups[$i];
            if (!isset($group['filters'])) {
                throw new InvalidFilter("Invalid Filter group format, expected 'filters' property.");
            }

            $filters = $group['filters'];
            foreach ($filters as $filter) {
                if (!isset($filter['f'])) {
                    throw new InvalidFilter("Invalid Filter Parameter, expected 'f' (field) property.");
                } elseif (!isset($filter['o'])) {
                    throw new InvalidFilter("Invalid Filter Parameter, expected 'o' (operation) property.");
                } elseif (!isset($filter['v'])) {
                    if (in_array($filter['o'], array(self::OPERATION_IS_NULL, self::OPERATION_IS_NOT_NULL))) {
                        $filter['v'] = null;
                    } else {
                        throw new InvalidFilter("Invalid Filter Parameter, expected 'v' (value) property.");
                    }
                }
                $field = $this->getFieldName($filter['f'], true);
                if (!$field) {
                    if (isset($associationMapping[$filter['f']])) {
                        $field = $filter['f'];
                        $assoc = true;
                    } elseif (isset($associationMappingByColumns[$filter['f']])) {
                        $field = $associationMappingByColumns[$filter['f']];
                        $assoc = true;
                        $filter['f'] = $field;
                    } else {
                        $fields = array();

                        if (false === strpos($filter['f'], '.')) {
                            $field = $this->getAssocFieldName($filter['f']);
                            if (!$field) {
                                if ($this->fallbackMetadata) {
                                    $assoc = true;
                                    $field = $this->fallbackMetadata->getAssociation($metadata);
                                    $fallbackField = $this->fallbackMetadata->getFallbackField($metadata);
                                    $field['fallbackField'] =  array('field' =>$fallbackField['fieldName'], 'operation' => self::OPERATION_EQ, 'value' => $this->normalizeFilterValueByType($filter['f'], $fallbackField['type']));
                                    $fields[] =  $field;

                                    $field = $this->fallbackMetadata->getValueField();
                                    $fields[] = array('field'=>$field['fieldName'], 'operation' => $filter['o'], 'value' => $this->normalizeFilterValueByType($filter['v'], $field['type']), 'fallback'=>true, 'meta' => $field);

                                    $filter['f'] = $fields;
                                } else {
                                    throw new InvalidFilter("Invalid field name: {$filter['f']}");
                                }
                            } else {
                                $assoc = true;
                                $filter['f'] = $field;
                            }
                        } else {
                            try {
                                $stack = $this->getRelationsMetaStack($filter['f']);
                            } catch (InvalidArgumentException $e) {
                                throw new InvalidFilter($e->getMessage());
                            }

                            foreach ($stack as $field => $meta) {
                                if (isset($meta['targetEntity'])) {
                                    $fields[] = $field;
                                } else {
                                    $type = $meta['type'];
                                    $value = $this->normalizeFilterValueByType($filter['v'], $type);

                                    $fields[] = array('field' => $field, 'operation' => $filter['o'], 'value' => $value);
                                }
                            }

                            $assoc = true;
                            $filter['f'] = $fields;
                        }

                    }
                }

                $value = $filter['v'];
                if (!$assoc && !isset($filter['t'])) {
                    $meta = $metadata->getFieldMapping($field);
                    $filter['t'] = $meta['type'];
                }

                if (isset($filter['t'])) {
                    $value = $this->normalizeFilterValueByType($value, $filter['t']);
                }

                if (!in_array($filter['o'], $operations)) {
                    throw new InvalidFilter("Invalid Filter Parameter, undefined operator:{$filter['o']}, please use one of [".implode(', ', array_values($operations))."].");
                }

                if (in_array($filter['o'], array(self::OPERATION_IS_NULL, self::OPERATION_IS_NOT_NULL))) {
                    $normalized[] = array('field' => $filter['f'], 'operation' => $filter['o'], 'value' => null);
                    continue;
                }

                $normalized[] = array('field' => $filter['f'], 'operation' => $filter['o'], 'value' => $value);
            }

            $inConjunction = strtolower(isset($group['in_conj']) ? $group['in_conj'] : $defaultInConjunction);
            $outConjunction = strtolower(isset($group['out_conj']) ? $group['out_conj'] : $defaultOutConjunction);

            $map = array('or' => self::CONJUNCTION_OR, 'and' => self::CONJUNCTION_AND);
            if (isset($map[$inConjunction])) {
                $inConjunction = $map[$inConjunction];
            }
            if (isset($map[$outConjunction])) {
                $outConjunction = $map[$outConjunction];
            }

            if (!in_array($inConjunction, $map)) {
                throw new InvalidFilter("Invalid 'in_conj' value: {$inConjunction}, expected one of: ".implode(',', array_keys($map)));
            }

            if (!in_array($outConjunction, $map)) {
                throw new InvalidFilter("Invalid 'out_conj' value: {$outConjunction}, expected one of: ".implode(',', array_keys($map)));
            }

            $result[] = array('filters' => $normalized, 'in' => $inConjunction, 'out' => $outConjunction);
        }

        return $result;
    }

    protected function normalizeFilterValueByType($value, $type)
    {
        switch ($type) {
            case 'numeric':
            case 'integer':
                $value = (int) $value;
                break;
            case 'string':
            case 'text':
                $value = (string) $value;
                break;
            case 'date':
            case 'datetime':
                try {
                    $value = new \DateTime($value);
                } catch (\Exception $e) {
                    throw new InvalidFilter("Invalid date format.");
                }
                break;
            case 'list':
                $value = explode(',', $value);
                break;
            case 'boolean':
                $value = (bool) (($value == 1 || strtolower($value) == 'true' || strtolower($value) == 'on') ? true : false);
                break;
            case 'raw':
                break;
            case 'float':
            case 'decimal':
                $value = (float) $value;
                break;
            default:
                throw new InvalidFilter("Unsupported filter value type: {$type}.");
        }

        return $value;
    }

    public function whereIn(array $values, $field = null)
    {
        if (empty($values)) {
            throw new InvalidArgumentException("Expected Not Empty values");
        }
        if (is_null($field)) {
            $field = $this->getMetadata()->getSingleIdentifierFieldName();
        } elseif (!$this->isValidColumnName($field)) {
            throw new InvalidArgumentException("Invalid field name '{$field}'");
        }

        $qb = $this->getQuery();
        $qb->andWhere($qb->expr()->in($this->getQueryAlias().'.'.$field, $values));

        return $this;
    }

    public function getResult($limit, $page = 1)
    {
        return $this->getQuery()
                    ->setFirstResult(($page -1) * $limit)
                    ->setMaxResults($limit)
                    ->getQuery()
                    ->getArrayResult();
    }

    public function search($query, array $exclude = array('password', 'salt'))
    {
        $this->searchQuery = $query;
        $queryBuilder = $this->getQuery();
        $metadata = $this->getMetadata();
        $fields = array_values($this->getColumnsMapping());
        $alias = $this->getQueryAlias();

        $qtype = gettype($query);
        $isInteger = is_numeric($query);
        $this->filteredFields = array();
        $wrappedQuery = strtolower("%{$query}%");

        $textUnit = "CHAR";
        $platform = $queryBuilder->getEntityManager()->getConnection()->getDatabasePlatform();
        if ($platform instanceof PostgreSqlPlatform) {
            $textUnit = "TEXT";
        }

        foreach ($fields as $name) {
            if (in_array($name, $exclude)) {
                continue;
            }

            $info = $metadata->getFieldMapping($name);
            if ($info['type'] == $qtype || (is_numeric($query) && in_array($info['type'], array('integer', 'numeric')))) {
                $this->filteredFields[$name] = $info;
            }
        }
        $where = $queryBuilder->getDQLPart('where');
        if ($qtype == 'string') {
            $expr = call_user_func_array(
                array($queryBuilder->expr(), 'orx'),
                array_map(
                    function ($name) use ($queryBuilder, $alias, $textUnit) {
                        return $queryBuilder->expr()->like("LOWER(CAST({$alias}.{$name} AS {$textUnit}))", ':search');
                    },
                    array_keys($this->filteredFields)
                )
            );

            $queryBuilder->where($expr)
                         ->andWhere($where)
                         ->setParameter('search', $wrappedQuery);
        }

        return $this;
    }

    public function getSuggestions($query, $limit)
    {
        $query = strtolower($query);
        $data = $this->search($query)->getResult($limit);
        $filtered = $this->filteredFields;

        $suggestion = array_map(
            function ($item) use ($filtered, $query) {
                $closest = null;
                $max = 0;
                $maxSimilar = 0;

                foreach ($item as $key => $value) {
                    if (!array_key_exists($key, $filtered)) {
                        continue;
                    }

                    if ($query == $value) {
                        $persent = 100;
                        $closest = $value;
                        break;
                    }

                    $similar = similar_text($query, strtolower($value), $persent);
                    if ($persent>$max || $similar>$maxSimilar) {
                        $max = $persent;
                        $maxSimilar = $similar;
                        $closest = $value;
                    }
                }

                return array('persent' => $max, 'closest' => $closest);
            },
            $data
        );

        $suggestion = array_filter(
            $suggestion,
            function ($item) {
                return $item['closest'] !== null;
            }
        );

        usort(
            $suggestion,
            function ($a, $b) {
                if ($a['persent'] == $b['persent']) {
                    return 0;
                }

                return ($a['persent'] > $b['persent']) ? -1 : 1;
            }
        );

        $suggestion = array_map(
            function ($item) {
                return $item['closest'];
            },
            $suggestion
        );

        return array_map('strval', array_values(array_unique($suggestion)));
    }

    public function getSearchQuery()
    {
        return $this->searchQuery;
    }

    public function setOrder($field, $order = 'asc')
    {
        $original = $field;
        $order = strtolower($order);
        if (!in_array($order, array('asc', 'desc'))) {
            throw new InvalidArgumentException("Invalid order");
        }

        $this->order = $order;
        $alias = $this->getQueryAlias();

        if (false !== strpos($field, '.')) {
            $stack = $this->getRelationsMetaStack($field);
            //join relation before order
            $qb = $this->getQuery();
            $parentAlias = $alias;
            $lastElement = end($stack);

            foreach ($stack as $fieldName => $meta) {
                if (!isset($meta['targetEntity'])) {
                    $qb->orderBy("{$parentAlias}.{$fieldName}", $this->order);
                    break;
                }

                $joinPart = $qb->getDQLPart('join');
                $joinAlias = $fieldName.'_';
                if (isset($joinPart[$alias])) {
                    $joined = array_filter(
                        $joinPart[$alias],
                        function ($join) use ($joinAlias) {
                            return $join->getAlias() == $joinAlias;
                        }
                    );
                    if (!empty($joined)) {
                        continue;
                    }
                }

                $qb->innerJoin("{$parentAlias}.{$fieldName}", $joinAlias);
                if ($meta === $lastElement) {
                    $qb->orderBy("{$parentAlias}.{$fieldName}", $this->order);
                }

                $parentAlias = $joinAlias;
            }

            $this->orderBy = $original;

            return $this;
        } elseif ((null === ($fieldName = $this->getFieldName($field, true))) && $this->fallbackMetadata) {
            $qb = $this->getQuery();

            $metadata = $this->getMetadata();
            $meta = $this->fallbackMetadata->getAssociation($metadata);
            $fallbackField = $this->fallbackMetadata->getFallbackField($metadata);
            $fallbackValue = $this->fallbackMetadata->getValueField();

            $parentAlias = $alias;
            $joinAlias = $meta['inversedBy'].'_left_';

            $sourceToTargetKeyColumns = reset($meta['sourceToTargetKeyColumns']);
            $paramName = "{$joinAlias}{$fallbackField['fieldName']}";

            $qb->leftJoin(
                "{$parentAlias}.{$meta['inversedBy']}",
                $joinAlias,
                'WITH',
                $qb->expr()->eq("{$joinAlias}.{$fallbackField['fieldName']}", ":{$paramName}")
            );

            $qb->setParameter($paramName, $original);
            $qb->orderBy("{$joinAlias}.{$fallbackValue['fieldName']}", $this->order);

            $this->orderBy = $original;

            return $this;
        }

        if (is_null($fieldName)) {
            $fieldName = $this->getAssocFieldName($field);
        }

        if (is_null($fieldName)) {
            throw new InvalidArgumentException("Invalid column name: {$field}");
        }

        $this->orderBy = $field;

        $this->getQuery()
             ->orderBy($alias.'.'.$fieldName, $this->order);

        return $this;
    }

    protected function getRelationsMetaStack($columns)
    {
        if (!is_array($columns)) {
            $columns = explode('.', $columns);
        }

        $stack = array();
        $parent = null;
        foreach ($columns as $columnName) {
            $field = null;
            $meta = $this->getAssocFieldName($columnName, $parent, true);
            if ($meta) {
                $parent = $meta;
                $field = $meta['fieldName'];
                $stack[$field] = $meta;
            } else {
                $parentMetadata = $this->getMetadata($parent['targetEntity']);
                if (isset($parentMetadata->fieldNames[$columnName])) {
                    $field = $parentMetadata->fieldNames[$columnName];
                } elseif (isset($parentMetadata->columnNames[$columnName])) {
                    $field = $columnName;
                }

                if (!$field) {
                    throw new InvalidArgumentException("Invalid field name: ".implode('.', $columns));
                } else {
                    $stack[$field] = $parentMetadata->getFieldMapping($field);
                }
            }
        }

        return $stack;
    }

    public function getOrderField()
    {
        return $this->orderBy;
    }

    public function getOrder()
    {
        return $this->order;
    }

    public function getMetadata($className = null)
    {
        if (!is_null($className)) {
            return $this->getQuery()->getEntityManager()->getClassMetadata($className);
        }

        if (empty($this->metadata)) {
            $query = $this->getQuery();
            $em = $query->getEntityManager();

            return $em->getClassMetadata($this->repository->getClassName());
        }

        return $this->metadata;
    }

    public function getColumnsMapping($className = null)
    {
        if (empty($this->mapping)) {
            $this->mapping = array();
            $metadata = $this->getMetadata();
            $properties = $metadata->getReflectionProperties();
            $association = array_keys($metadata->getAssociationMappings());

            foreach ($properties as $fieldName => $data) {
                if (in_array($fieldName, $association)) {
                    continue;
                }

                $column = $metadata->getColumnName($fieldName);
                $this->mapping[$column] = $fieldName;
            }
        }

        return $this->mapping;
    }

    public function isValidColumnName($name, $checkFields = false)
    {
        return $this->getFieldName($name, $checkFields) !== null;
    }

    public function isValidAssocColumnName($name)
    {
        return $this->getAssocFieldName($name) !== null;
    }

    public function getAssocFieldName($name, $parent = null, $returnMeta = false)
    {
        $target = null;
        if (!is_null($parent)) {
            if (is_array($parent)) {
                if (!isset($parent['targetEntity'])) {
                    throw new InvalidArgumentException("Invalid parent meta");
                }
                $target = $parent['targetEntity'];
            } else {
                $parents = explode('.', $parent);
                array_shift($parents);
                if (empty($parents)) {
                    $parents = null;
                } else {
                    $parents = implode('.', $parents);
                }
                $parentMeta = $this->getAssocFieldName($parent, $parents, true);
                $target = $parentMeta['targetEntity'];
            }
        }

        $metadata = $this->getMetadata($target);
        $association = $metadata->getAssociationMappings();
        foreach ($association as $fieldName => $meta) {
            if ($name !== $fieldName) {
                continue;
            }

            if ($meta['type'] == ClassMetadataInfo::MANY_TO_ONE || $meta['type'] == ClassMetadataInfo::MANY_TO_ONE) {
                if (count($meta['joinColumns']) == 1 && ($name == $fieldName || $name == $meta['joinColumns'][0]['name'])) {
                    return $returnMeta ? $meta : $fieldName;
                }
            }
        }

        return;
    }

    public function setFallbackMetadata(FallbackMetadata $metadata)
    {
        if ($metadata->isSupport($this->getMetadata())) {
            $this->fallbackMetadata = $metadata;
        } else {
            throw new \RuntimeException("Invalid Fallback metadata");
        }

        return $this;
    }

    public function getFallbackMetadata()
    {
        return $this->fallbackMetadata;
    }

    public function getFieldName($columnName, $checkFields = false)
    {
        if (!$checkFields) {
            return array_key_exists($columnName, $this->getColumnsMapping()) ? $this->mapping[$columnName] : null;
        }

        $mapping = $this->getColumnsMapping();
        if (array_key_exists($columnName, $mapping)) {
            return $mapping[$columnName];
        } elseif (in_array($columnName, $mapping)) {
            return $columnName;
        }

        return;
    }

    public function setRepository(EntityRepository $repository)
    {
        $this->repository = $repository;

        return $this;
    }

    public function getRepository()
    {
        return $this->repository;
    }

    public function setQueryAlias($alias)
    {
        $this->queryAlias = trim($alias);

        return $this;
    }

    public function getQueryAlias()
    {
        return $this->queryAlias;
    }
}

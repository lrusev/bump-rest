<?php

namespace Bump\RestBundle\Controller;

use FOS\RestBundle\Util\Codes;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Request\ParamFetcherInterface;
use FOS\RestBundle\View\RouteRedirectView;
use FOS\RestBundle\View\View;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Bump\RestBundle\Library\PaginatedRepresentation;
use Bump\RestBundle\Library\SuggestionsRepresentation;
use Bump\RestBundle\Library\RestfulPaginator;
use Bump\RestBundle\Library\Query;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Common\Inflector\Inflector;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\HttpFoundation\StreamedResponse;
use JMS\Serializer\SerializationContext;
use FOS\RestBundle\Controller\Annotations;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\ResolvedFormTypeInterface;
use Symfony\Component\Form\Form;
use RuntimeException;
use InvalidArgumentException;
use Closure;

abstract class RestController extends FOSRestController
{
    protected $collectionAlias;
    protected $notFoundMessage;
    protected $collectionRoute;
    protected $relElement;
    protected $serializationVersion;
    protected $serializationGroups = array();

    /* Example or parameter which should be specified
     * @Annotations\QueryParam(name="page", requirements="\d+", nullable=true, default="1", description="Current page number.")
     * @Annotations\QueryParam(name="limit", requirements="\d+", nullable=true, default="5", description="How many items to return.")
     * @Annotations\QueryParam(name="order_by", requirements="\w+", strict=true, nullable=true, default="id", description="Field name to order by")
     * @Annotations\QueryParam(name="order", requirements="asc|desc|ASC|DESC", nullable=true, default="asc", description="Sort")
     * @Annotations\QueryParam(name="query", nullable=true, default=null, description="Search query")
     * @Annotations\QueryParam(name="filters", nullable=true, default=null, description="Filters JSON string")
     * @Annotations\QueryParam(name="serializer_groups", array=true, requirements="Default|default|List|list|Advanced|advanced", nullable=true, default="Default", description="List of serialization groups. Use Advanced to display assets hierarchiсally.")
     */
    protected function handleGetCollection(ParamFetcherInterface $paramFetcher, \Closure $preRequestCallback = null, $public = false, EntityRepository $repository = null, $rel = null)
    {
        $page = $paramFetcher->get('page');
        $page = empty($page) ? 1 : intval($page);
        $limit = $paramFetcher->get('limit');
        $limit = intval($limit);

        $orderBy = $paramFetcher->get('order_by');
        $order = strtolower($paramFetcher->get('order'));

        $this->handleSerializerGroups($paramFetcher);

        try {
            $paginator = $this->getPaginator($repository);
            $paginator->setOrder($orderBy, $order);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $query = $paramFetcher->get('query');
        if (!empty($query)) {
            $paginator->search($query);
        }

        $rel = is_null($rel) ? $this->getRelElement() : $rel;
        $route = $this->getCollectionRoute();

        $filters = $paramFetcher->get('filters');
        if (!empty($filters)) {
            try {
                $filterGroups = $paginator->normalizeFilters($filters);
                foreach ($filterGroups as $group) {
                    $paginator->applyFilters($group['filters'], $group['in'], $group['out']);
                }
            } catch (Query\InvalidFilter $e) {
                throw new BadRequestHttpException($e->getMessage());
            }
        }

        if (!is_null($preRequestCallback)) {
            call_user_func($preRequestCallback, $paginator, $paramFetcher, $this);
        }

        $representation = $paginator->getRepresentation($limit, $page, $route, $this->getCollectionRouteParams(), true, $rel, $rel);
        $representation->setFilters(json_decode($filters, true));
        $representation = $this->postRepresentationLoaded($representation);

        return $this->handleHttpCache($representation, $public);
    }

    protected function getPaginator(EntityRepository $repository = null)
    {
        $alias = $this->getCollectionAlias();
        $paginator = new RestfulPaginator(is_null($repository) ? $this->getRepository() : $repository, $alias);

        return $paginator;
    }

    protected function postRepresentationLoaded(PaginatedRepresentation $representation)
    {
        return $representation;
    }

    protected function handleSerializerGroups(ParamFetcherInterface $paramFetcher)
    {
        $request = $this->get('request');
        if ($request->query->has('serializer_groups')) {
            try {
                $groups = array_unique($paramFetcher->get('serializer_groups'));
            } catch (\InvalidArgumentException $e) {
                return;
            }

            if (!empty($groups)) {
                if (!($configuration = $request->attributes->get('_view'))) {
                    $defaultGroups = $this->getSerializationGroups();

                    $groups = array_map('strtolower', array_unique(array_merge(array("Default"), $groups)));

                    $groups = array_map(
                        function ($group) {
                            return implode('-', array_map('ucfirst', explode('-', $group)));
                        },
                        $groups
                    );

                    $this->setSerializationGroups($groups);

                    return $groups;
                }

                $defaultGroups = $configuration->getSerializerGroups();
                if (empty($defaultGroups)) {
                    $defaultGroups = array();
                }

                $groups = array_map('ucfirst', array_map('strtolower', array_unique(array_merge($defaultGroups, $groups))));
                $configuration->setSerializerGroups($groups);

                return $groups;
            }
        }
    }

    /* Example or parameter which should be specified
     * @Annotations\QueryParam(name="page", requirements="\d+", nullable=true, default="1", description="Current page number.")
     * @Annotations\QueryParam(name="limit", requirements="\d+", nullable=true, default="5", description="How many items to return.")
     * @Annotations\QueryParam(name="order_by", requirements="\w+", strict=true, nullable=true, default="id", description="Field name to order by")
     * @Annotations\QueryParam(name="order", requirements="asc|desc|ASC|DESC", nullable=true, default="asc", description="Sort")
     * @Annotations\QueryParam(name="query", nullable=true, default=null, description="Search query")
     * @Annotations\QueryParam(name="filters", nullable=true, default=null, description="Filters JSON string")
     */
    protected function handleGetSuggestion(ParamFetcherInterface $paramFetcher, \Closure $preRequestCallback = null, $public = false)
    {
        $query = $paramFetcher->get('query');
        $limit = $paramFetcher->get('limit');
        $limit = empty($limit) ? 5 : intval($limit);
        $key = $paramFetcher->get('key');
        $processor = new Query\Processor($this->getRepository(), $this->getCollectionAlias());

        $filters = $paramFetcher->get('filters');
        if (!empty($filters)) {
            try {
                $processor->applyFilters($processor->normalizeFilters($filters));
            } catch (Query\InvalidFilter $e) {
                throw new BadRequestHttpException($e->getMessage());
            }
        }

        if (!is_null($preRequestCallback)) {
            call_user_func($preRequestCallback, $processor, $paramFetcher, $this);
        }

        $representation = new SuggestionsRepresentation($processor->getSuggestions($query, $limit), $key);

        return $this->handleHttpCache($representation, $public);
    }
    /**
     * Delete single entity
     */
    protected function handleDeleteSingle($id, $method = 'find')
    {
        if (is_object($method)) {
            $entity = $method;
        } elseif (is_object($id)) {
            $entity = $id;
            $id = $entity->getId();
        } else {
            $entity = $this->findEntityOrThrowNotFound($id, $method);
        }

        $em = $this->getDoctrine()->getManager();
        $em->remove($entity);
        $em->flush();

        return $this->routeRedirectView($this->getCollectionRoute(), $this->getCollectionRouteParams(), Codes::HTTP_NO_CONTENT);
    }

    /**
     * Delete multiple entity
     */
    protected function handleDeleteBulk($ids, $idSeparator = ',', $message = null, \Closure $callback = null)
    {
        $ids = explode($idSeparator, $ids);
        if (empty($ids)) {
            throw new BadRequestHttpException("Expected entity ids separated by '$idSeparator'");
        }

        $processor = new Query\Processor($this->getRepository(), $this->getCollectionAlias());
        $processor->whereIn($ids);
        $em = $this->getDoctrine()->getManager();
        $batchSize = 20;
        $i = 0;
        $q = $processor->qb()->getQuery();
        $iterableResult = $q->iterate();
        while (($row = $iterableResult->next()) !== false) {
            if (!is_null($callback)) {
                $callback($row[0], $processor, $this);
            }

            $em->remove($row[0]);
            if (($i % $batchSize) == 0) {
                $em->flush(); // Executes all deletions.
                $em->clear(); // Detaches all objects from Doctrine!
            }
            ++$i;
        }

        $em->flush();

        if ($i == 0) {
            if (is_null($message)) {
                $message = $this->getNotFoundMessage();
            }

            throw $this->createNotFoundException(sprintf($message, implode($idSeparator, $ids)));
        }

        return $this->routeRedirectView($this->getCollectionRoute(), $this->getCollectionRouteParams(), Codes::HTTP_NO_CONTENT);
    }

    /**
     * Handling GET method for single entity
     * @param $mixed     $id      the entity id
     */
    protected function handleGetSingle($id, $method = 'find', $public = false)
    {
        if (is_object($method)) {
            $entity = $method;
        } elseif (is_object($id)) {
            $entity = $id;
            $id = $entity->getId();
        } else {
            $entity = $this->findEntityOrThrowNotFound($id, $method);
        }

        return $this->handleHttpCache($entity, $public);
    }

    protected function handleForm($type, $entity, \Closure $validCallback = null, array $options = array(), \Closure $invalidCallback = null, Request $request = null)
    {
        $isNew = $this->getDoctrine()
                      ->getManager()
                      ->getUnitOfWork()
                      ->getEntityState($entity) === UnitOfWork::STATE_NEW;

        if ($type instanceof FormTypeInterface) {
            $form = $this->createForm($type, $entity, $options);
        } elseif ($type instanceof \Symfony\Component\Form\Form) {
            $form = $type;
        } else {
            throw new \InvalidArgumentException("Expected instanceof Symfony\Component\Form\FormTypeInterface or Symfony\Component\Form\Form");
        }

        if (is_null($request)) {
            $request = $this->getRequest();
        }

        if (Request::getHttpMethodParameterOverride() && $request->get('_method')) {
            $request = clone $request;
            if ($request->query->has('_method')) {
                $request->query->remove('_method');
            } elseif ($request->request->has('_method')) {
                $request->request->remove('_method');
            }
        }

        $form->submit($request);

        if ($form->isValid()) {
            $defaultCallback = function ($entity, $isNew, $handler) {
                $em = $handler->getDoctrine()->getManager();
                if ($isNew) {
                    $em->persist($entity);
                }

                $em->flush();
            };

            if (is_null($validCallback)) {
                call_user_func_array($defaultCallback, array($entity, $isNew, $this));
            } else {
                $response = call_user_func_array($validCallback, array($entity, $isNew, $this, $defaultCallback, $form));
                if (is_object($response) && ($response instanceof \Symfony\Component\HttpFoundation\Response || $response instanceof \FOS\RestBundle\View\View)) {
                    return $response;
                }
            }

            return $this->entityRouteRedirectView($entity, ($isNew ? 201 : 204), array('Content-type' => 'text/plain'));
        }

        if (!is_null($invalidCallback)) {
            $response = call_user_func_array($invalidCallback, array($entity, $isNew, $this, $form));
            if (is_object($response) && ($response instanceof \Symfony\Component\HttpFoundation\Response || $response instanceof \FOS\RestBundle\View\View)) {
                return $response;
            }
        }

        return array('form' => $form);
    }

    protected function handlePatchForm($type, $entity, \Closure $validCallback = null, array $options = array(), \Closure $invalidCallback = null, Request $request = null)
    {
        return $this->handleForm($this->createPatchForm($type, $entity, $options, $request), $entity, $validCallback, $options, $invalidCallback, $request);
    }

    protected function createPatchForm($type, $data = null, array $options = array(), Request $request = null)
    {
        if ($type instanceof FormTypeInterface) {
            $builder = $this->container->get('form.factory')->createBuilder($type, $data, $options);
            $type->buildForm($builder, $options);
            $form = $builder->getForm();
        } elseif ($type instanceof Form) {
            $form = $type;
            if (is_null($request)) {
                throw new RuntimeException("Expected request object null taken.");
            }

            $data = (strtoupper($request->getMethod()) === 'GET') ? $request->query->all() : $request->request->all();

            return $this->filterPatchForm($form, $data);
        } else {
            throw new \InvalidArgumentException("Expected instanceof Symfony\Component\Form\FormTypeInterface or Symfony\Component\Form\Form");
        }

        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            function (FormEvent $event) {
                $data = $event->getData();
                $form = $event->getForm();
                $this->filterPatchForm($form, $data);
            }
        );

        return $form;
    }

    protected function filterPatchForm(Form $form, array $data = array())
    {
        $names = array_keys(iterator_to_array($form));
        foreach ($names as $name) {
            if (!array_key_exists($name, $data)) {
                $form->remove($name);
            } else {
                $subHanlder = function ($form, $name, $data) use (&$subHanlder) {
                    $element = $form->get($name);
                    $type = $element->getConfig()->getType();
                    if ($type instanceof FormInterface || $type instanceof ResolvedFormTypeInterface) {
                        $names = array_keys(iterator_to_array($element));
                        foreach ($names as $name) {
                            if (!array_key_exists($name, $data)) {
                                $element->remove($name);
                            } else {
                                $subHanlder($element, $name, $data[$name]);
                            }
                        }
                    }
                };

                $subHanlder($form, $name, $data[$name]);
            }
        }

        return $form;
    }

    protected function findEntityOrThrowNotFound($id, $method = 'find', $message = null)
    {
        if (is_null($message)) {
            $message = $this->getNotFoundMessage();
        }

        if (!($entity = call_user_func(array($this->getRepository(), $method), $id))) {
            throw $this->createNotFoundException(sprintf($message, $id));
        }

        return $entity;
    }

    /*
     * Handling HTTP Cache
     */
    protected function handleHttpCache($data, $public = false)
    {
        $view = $this->view($data);
        $request = $this->getRequest();
        /** @var \FOS\RestBundle\Controller\Annotations\View $configuration */
        $configuration = $request->attributes->get('_view');

        if ($configuration) {
            if ($configuration->getTemplate()) {
                $view->setTemplate($configuration->getTemplate());
            }

            if ($configuration->getTemplateVar()) {
                $view->setTemplateVar($configuration->getTemplateVar());
            }

            if ($configuration->getStatusCode() && (null === $view->getStatusCode() || Codes::HTTP_OK === $view->getStatusCode())) {
                $view->setStatusCode($configuration->getStatusCode());
            }

            if ($configuration->getSerializerGroups()) {
                $context = $view->getSerializationContext() ?: new SerializationContext();
                $context->setGroups($configuration->getSerializerGroups());
                $view->setSerializationContext($context);
            } elseif (count($groups = $this->getSerializationGroups())) {
                $context = $view->getSerializationContext() ?: new SerializationContext();
                $context->setGroups($groups);
                $view->setSerializationContext($context);
            }

            if ($configuration->getSerializerEnableMaxDepthChecks()) {
                $context = $view->getSerializationContext() ?: new SerializationContext();
                $context->enableMaxDepthChecks();
                $view->setSerializationContext($context);
            }

            if (($version = $this->getSerializationVersion())) {
                $context = $view->getSerializationContext() ?: new SerializationContext();
                $context->setVersion($version);
                $view->setSerializationContext($context);
            }
        } else {
            if (count($groups = $this->getSerializationGroups())) {
                $context = $view->getSerializationContext() ?: new SerializationContext();
                $context->setGroups($groups);
                $view->setSerializationContext($context);
            }

            if (($version = $this->getSerializationVersion())) {
                $context = $view->getSerializationContext() ?: new SerializationContext();
                $context->setVersion($version);
                $view->setSerializationContext($context);
            }
        }

        return $this->get('bump_api.http_cache')->handle($view, $public);
    }

    /*
    * @Annotations\QueryParam(name="format", requirements="json|xml|csv|sql", strict=true, nullable=false, default="csv", description="Export format")
    * @Annotations\QueryParam(name="page", requirements="\d+", nullable=true, default="1", description="Page number.")
    * @Annotations\QueryParam(name="limit", requirements="\d+", nullable=true, default="0", description="How many items to export.")
    * @Annotations\QueryParam(name="order_by", requirements="\w+", strict=true, nullable=true, default="id", description="Field name to order by")
    * @Annotations\QueryParam(name="order", requirements="asc|desc|ASC|DESC", nullable=true, default="asc", description="Sort")
    * @Annotations\QueryParam(name="query", nullable=true, default=null, description="Search query")
    * @Annotations\QueryParam(name="filters", nullable=true, default=null, description="Filters JSON string")
    */
    protected function handleExport(ParamFetcherInterface $paramFetcher)
    {
        $response = new StreamedResponse(
            function () use ($paramFetcher) {
                $serializer = $this->get('jms_serializer');
                $context = new SerializationContext();
                $context->setSerializeNull(true);

                $page = $paramFetcher->get('page');
                $page = empty($page) ? 1 : intval($page);
                $limit = $paramFetcher->get('limit');
                $limit = intval($limit);

                $orderBy = $paramFetcher->get('order_by');
                $query = $paramFetcher->get('query');
                $order = strtolower($paramFetcher->get('order'));
                $format = $paramFetcher->get('format');
                $alias = $this->getCollectionAlias();
                $paginator = new RestfulPaginator($this->getRepository(), $alias);
                if (!$paginator->isValidColumnName($orderBy)) {
                    throw new BadRequestHttpException("Invalid order_by param: {$orderBy}");
                }

                if (!empty($query)) {
                    $paginator->search($query);
                }

                $paginator->setOrder($orderBy, $order);

                $filters = $paramFetcher->get('filters');
                if (!empty($filters)) {
                    try {
                        $filterGroups = $paginator->normalizeFilters($filters);
                        foreach ($filterGroups as $group) {
                            $paginator->applyFilters($group['filters'], $group['in'], $group['out']);
                        }
                    } catch (Query\InvalidFilter $e) {
                        throw new BadRequestHttpException($e->getMessage());
                    }
                }

                $qb = $paginator->getQuery();
                if ($limit>0) {
                    $qb = $qb->setFirstResult(($page -1) * $limit)->setMaxResults($limit);
                }

                $results = $qb->getQuery()->iterate();
                $handle = fopen('php://output', 'r+');

                $i = 0;
                $batchSize = 20;
                $em = $this->get('doctrine')->getManager();

                $tableName = $em->getClassMetadata($this->getEntityId())->getTableName();
                $connection = $em->getConnection();

                $filter = function (array $data) {
                    $filtered = array();
                    foreach ($data as $key => $val) {
                        if (!is_array($val) && !is_object($val) && substr($key, 0, 1) != '_') {
                            $filtered[$key] = $val;
                        }
                    }

                    return $filtered;
                };

                if ($format == 'json') {
                    fwrite($handle, '[');
                } elseif ($format == 'xml') {
                    fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?><results>');
                }

                while (false !== ($row = $results->next())) {
                    $json = $serializer->serialize($row[0], 'json', SerializationContext::create()->setSerializeNull(true));
                    $data = $filter(json_decode($json, true));

                    if ($format == 'csv') {
                        if ($i === 0) {
                            fputcsv($handle, array_keys($data));
                        }

                        fputcsv($handle, array_values($data));
                    } elseif ($format == 'json') {
                        fwrite($handle, ($i === 0) ? json_encode($data) : ','.json_encode($data));
                    } elseif ($format == 'xml') {
                        fwrite($handle, str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', trim($serializer->serialize($row[0], 'xml', SerializationContext::create()->setSerializeNull(true)))));
                    } elseif ($format == 'sql') {
                        fwrite($handle, sprintf("INSERT INTO %s (%s) VALUES (%s);\n", $connection->quoteIdentifier($tableName), implode(' ,', array_map(array($connection, 'quoteIdentifier'), array_keys($data))), implode(' ,', array_map(array($connection, 'quote'), array_values($data)))));
                    }

                    if (($i % $batchSize) == 0) {
                        $em->clear(); // Detaches all objects from Doctrine!
                    }
                    $i++;
                }

                if ($format == 'json') {
                    fwrite($handle, ']');
                } elseif ($format == 'xml') {
                    fwrite($handle, '</results>');
                }

                fclose($handle);
            }
        );

        // $response->headers->set('Content-Type', 'text/html');

        $filename = date("Y-m-d")."-export.".$paramFetcher->get('format');
        $response->headers->set('Content-Type', 'application/force-download');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');

        return $response;
    }

    /**
     * Retrieve entity repository
     */
    protected function getRepository($entityId = null)
    {
        if (is_null($entityId)) {
            $entityId = $this->getEntityId();
        }

        return $this->getDoctrine()->getRepository($entityId);
    }

    protected function getQueryBuilder($alias = null)
    {
        if (is_null($alias)) {
            $alias = $this->getCollectionAlias();
        }

        return $this->getRepository()
                    ->createQueryBuilder($alias)
                    ->select($alias);
    }

    protected function getManager()
    {
        return $this->getDoctrine()->getManager();
    }

    protected function getEntityClassName($entityName = null)
    {
        if (is_null($entityName)) {
            $entityName = $this->getEntityId();
        }

        $metadata = $this->getManager()->getClassMetadata($entityName);

        return $metadata->name;
    }

    protected function getNotFoundMessage($single = true, $placeholderName = 'id')
    {
        if (empty($this->notFoundMessage)) {
            $rel = $this->getRelElement();
            if ($single) {
                $rel = Inflector::singularize($rel);
            }

            $message = "No {$rel} were found";
            if (!empty($placeholderName)) {
                $message .= " by requested {$placeholderName} '%s'";
            }

            return $message;
        }

        return $this->notFoundMessage;
    }

    protected function getCollectionAlias()
    {
        if (empty($this->collectionAlias)) {
            $this->collectionAlias = $this->getRelElement();
            $this->collectionAlias = substr($this->collectionAlias, 0, 2).rand(1, 9);
        }

        return $this->collectionAlias;
    }

    protected function getRelElement()
    {
        if (empty($this->relElement)) {
            $entityName = $this->getEntityId();
            if (false !== strpos($entityName, ':')) {
                $parts = explode(':', $entityName);
                $this->relElement = Inflector::pluralize(Inflector::tableize($parts[count($parts)-1]));
            } else {
                $parts = explode("\\", $entityName);
                $this->relElement = Inflector::pluralize(Inflector::tableize($parts[count($parts)-1]));
            }

            $this->relElement = strtolower($this->relElement);
        }

        return $this->relElement;
    }

    protected function handleBulkIterator($ids, Closure $modifier, Closure $beforeIterate = null, $idSeparator = ',', $message = null, $alias = null)
    {
        $ids = explode($idSeparator, $ids);
        if (empty($ids)) {
            throw new BadRequestHttpException("Expected entity ids separated by '$idSeparator'");
        }

        $before = function ($qb, $em, $alias) use (&$beforeIterate, $ids) {
            $qb->where("{$alias}.id IN(:ids)")
               ->setParameter('ids', $ids);

            if (!is_null($beforeIterate)) {
                call_user_func_array($beforeIterate, array($qb, $em, $alias));
            }

            return $qb;
        };

        $index = $this->iterate($modifier, $before, $alias);

        if ($index == 0) {
            if (is_null($message)) {
                $message = $this->getNotFoundMessage();
            }

            throw $this->createNotFoundException(sprintf($message, implode($idSeparator, $ids)));
        }

        return $this->routeRedirectView($this->getCollectionRoute(), $this->getCollectionRouteParams(), Codes::HTTP_NO_CONTENT);
    }

    protected function iterate(Closure $modifier, Closure $beforeIterate = null, $alias = null)
    {
        if (is_null($alias)) {
            $alias = $this->getCollectionAlias();
        }

        $em = $this->getDoctrine()->getManager();
        $qb = $this->getQueryBuilder($alias);

        if (!is_null($beforeIterate)) {
            call_user_func_array($beforeIterate, array($qb, $em, $alias));
        }

        $batchSize = 20;
        $i = 0;
        $iterableResult = $qb->getQuery()->iterate();
        while (($row = $iterableResult->next()) !== false) {
            call_user_func($modifier, $row[0]);

            if (($i % $batchSize) == 0) {
                $em->flush();
                $em->clear();
            }
            ++$i;
        }

        $em->flush();

        return $i;
    }

    protected function getCollectionRoute()
    {
        if (empty($this->collectionRoute)) {
            throw new \RuntimeException("Collection route is not presented");
        }

        return $this->collectionRoute;
    }

    protected function getCollectionRouteParams()
    {
        return array();
    }

    protected function getSerializationVersion()
    {
        return $this->serializationVersion;
    }

    protected function getSerializationGroups()
    {
        return $this->serializationGroups;
    }

    protected function setSerializationGroups(array $groups)
    {
        $this->serializationGroups = $groups;

        return $this;
    }

    abstract protected function getEntityId();

    abstract protected function entityRouteRedirectView($entity, $status = 200, array $headers = array());
}

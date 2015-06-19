<?php

namespace Bump\RestBundle\Controller;

use FOS\RestBundle\Util\Codes;
use FOS\RestBundle\Controller\Annotations;
use FOS\RestBundle\Request\ParamFetcherInterface;
use FOS\RestBundle\View\RouteRedirectView;
use FOS\RestBundle\Controller\Annotations\Post;
use FOS\RestBundle\View\View;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Bump\RestBundle\Library\Utils;
use Bump\RestBundle\Form\Type\UserType;
use Bump\RestBundle\Entity\User;
use Bump\RestBundle\Entity\Role;

/**
 * Should be extended
 */
abstract class UsersController extends RestController
{
    /**
     * Retrieve list of registered api users.
     * Available only for users with 'admin' role.
     * Implemented: search, filter and pagination
     * @ApiDoc(
     *   resource = true,
     *   section="Users",
     *   statusCodes = {
     *     200 = "Returned when successful",
     *     400 = "Returned when the form has errors",
     *     403 = "Returned when the user do not have the necessary permissions",
     *     500 = "Returned when Server error occurred."
     *   }
     * )
     *
     * @Annotations\QueryParam(name="page", requirements="\d+", nullable=true, default="1", description="Current page number.")
     * @Annotations\QueryParam(name="limit", requirements="\d+", nullable=true, default="5", description="How many users to return.")
     * @Annotations\QueryParam(name="order_by", requirements="\w+", strict=true, nullable=true, default="id", description="Field name to order by")
     * @Annotations\QueryParam(name="order", requirements="asc|desc|ASC|DESC", nullable=true, default="asc", description="Sort")
     * @Annotations\QueryParam(name="query", nullable=true, default=null, description="Search query")
     * @Annotations\QueryParam(name="filters", nullable=true, default=null, description="Filters JSON string")
     *
     * @param ParamFetcherInterface $paramFetcher param fetcher service
     *
     * @return array
     */
    public function getUsersAction(ParamFetcherInterface $paramFetcher)
    {
        return $this->handleGetCollection($paramFetcher, null, false);
    }

    /**
     * Retrieve list of roles.
     * @ApiDoc(
     *   resource = true,
     *   section="Users",
     *   statusCodes = {
     *     200 = "Returned when successful",
     *     400 = "Returned when the form has errors",
     *     403 = "Returned when the user do not have the necessary permissions",
     *     500 = "Returned when Server error occurred."
     *   }
     * )
     *
     * @Annotations\QueryParam(name="page", requirements="\d+", nullable=true, default="1", description="Current page number.")
     * @Annotations\QueryParam(name="limit", requirements="\d+", nullable=true, default="5", description="How many users to return.")
     * @Annotations\QueryParam(name="order_by", requirements="\w+", strict=true, nullable=true, default="id", description="Field name to order by")
     * @Annotations\QueryParam(name="order", requirements="asc|desc|ASC|DESC", nullable=true, default="asc", description="Sort")
     * @Annotations\QueryParam(name="query", nullable=true, default=null, description="Search query")
     * @Annotations\QueryParam(name="filters", nullable=true, default=null, description="Filters JSON string")
     *
     * @param  ParamFetcherInterface $paramFetcher param fetcher service
     * @return array
     */
    public function getRolesAction(ParamFetcherInterface $paramFetcher)
    {
        return $this->handleGetCollection($paramFetcher, null, false, $this->getRepository($this->getRoleEntityId()), 'roles');
    }

    /**
     * This action is could be use as like an Autocompleter data source
     * For example with this library <a href="https://github.com/angular-ui/bootstrap/tree/master/src/typeahead">angular-ui/bootstrap</a>
     *
     * @ApiDoc(
     *   resource = true,
     *   section="Users",
     *   statusCodes = {
     *     200 = "Returned when successful",
     *     403 = "Returned when the user do not have the necessary permissions",
     *     400 = "Returned when the form has errors",
     *     500 = "Returned when Server error occurred."
     *   }
     * )
     *
     * @Annotations\QueryParam(name="limit", requirements="\d+", nullable=true, default="5", description="How many users to return.")
     * @Annotations\QueryParam(name="query", nullable=false, strict=true, description="Search query")
     * @Annotations\QueryParam(name="filters", nullable=true, default=null, description="Filters JSON string")
     * @Annotations\QueryParam(name="key", requirements="[a-zA-Z0-9]+", nullable=true, default=null, description="Key for suggestion values")
     *
     *
     * @param ParamFetcherInterface $paramFetcher param fetcher service
     *
     * @return array
     */
    public function getUsersSuggestionAction(ParamFetcherInterface $paramFetcher)
    {
        return $this->handleGetSuggestion($paramFetcher, null, false);
    }

    /**
     * Retrieve single user data requested by id
     *
     * @ApiDoc(
     *   section="Users",
     *   output = "Bump\RestBundle\Entity\User",
     *   statusCodes = {
     *     200 = "Returned when successful",
     *     403 = "Returned when the user do not have the necessary permissions",
     *     404 = "Returned when the user is not found",
     *     500 = "Returned when Server error occurred."
     *   }
     * )
     *
     * @param int $id the user id
     *
     * @return array
     *
     * @throws NotFoundHttpException when user not exist
     */
    public function getUserAction($id)
    {
        return $this->handleGetSingle($id, 'find', false);
    }

    /**
     * Retrieve current logged user data
     * This method is useful to use on authentication process to check is user set right credentials.
     * @ApiDoc(
     *   section="Users",
     *   output = "Bump\RestBundle\Entity\User",
     *   statusCodes = {
     *     200 = "Returned when successful",
     *     403 = "Returned when the user do not have the necessary permissions",
     *     404 = "Returned when the user is not found",
     *     500 = "Returned when Server error occurred."
     *   }
     * )
     *
     * @param int $id the user id
     *
     * @return array
     *
     * @throws NotFoundHttpException when user not exist
     */
    public function meAction()
    {
        $view = $this->view($this->get('security.context')->getToken()->getUser());
        $view->setFormat('json');

        return $view;
    }

    /**
     * Create a new api user from submitted data.
     *
     * @ApiDoc(
     *   resource = true,
     *   section="Users",
     *   input = "Bump\RestBundle\Form\Type\UserType",
     *   statusCodes = {
     *     201 = "Returned when successful",
     *     403 = "Returned when the user do not have the necessary permissions",
     *     400 = "Returned when the form has errors",
     *     500 = "Returned when Server error occurred."
     *   }
     * )
     *
     *
     * @return FormTypeInterface|RouteRedirectView
     */
    public function postUserAction()
    {
        $entityClassName = $this->getEntityClassName();

        return $this->createUser($this->getUserFormType(), new $entityClassName(), $this->getDoctrine()->getManager()->getRepository($this->getRoleEntityId())->findOneByRole('ROLE_USER'));
    }

    /**
     * Creates a new api admin user from the submitted data.
     * User password will be generate automatically and send to user
     *
     * @ApiDoc(
     *   resource = true,
     *   section="Users",
     *   input = "Bump\RestBundle\Form\Type\UserType",
     *   statusCodes = {
     *     201 = "Returned when successful",
     *     403="Returned when the user do not have the necessary permissions",
     *     400 = "Returned when the form has errors",
     *     500 = "Returned when Server error occurred."
     *   }
     * )
     *
     * @param Request $request the request object
     *
     * @return FormTypeInterface|RouteRedirectView
     */
    public function postUsersAdminAction(Request $request)
    {
        $entityClassName = $this->getEntityClassName();

        return $this->createUser($this->getUserFormType(), new $entityClassName(), $this->getDoctrine()->getManager()->getRepository($this->getRoleEntityId())->findOneByRole('ROLE_ADMIN'));
    }

    protected function createUser(FormTypeInterface $type, User $user, Role $role)
    {
        return $this->handleForm($type, $user, function ($user, $isNew,  $handler, $defaultCallback) use ($role) {
            $encoder = $handler->container->get('security.encoder_factory')->getEncoder($user);
            $password = Utils::randomString(16, 3, true);
            $user->setPassword($encoder->encodePassword($password, $user->getSalt()))
                 ->addRole($role);

            $handler->sendAccessInfo($user, $password);
            call_user_func_array($defaultCallback, array($user, $isNew, $handler));
        }, array(
            'data_class' => $this->getEntityClassName(),
        ));
    }

    /**
     * Regenerate User password and send via email
     * @Post("/users/{id}/password/new")
     * @ApiDoc(
     *   resource = true,
     *   section = "Users",
     *   statusCodes={
     *     204="Returned when successful",
     *     403="Returned when the user do not have the necessary permissions",
     *     404="Returned when the user is not found",
     *     500 = "Returned when Server error occurred."
     *   }
     * )
     *
     * @param int $id the user id
     *
     */
    public function postNewUserPasswordAction($id)
    {
        $user = $this->findEntityOrThrowNotFound($id);

        $em = $this->getDoctrine()->getManager();

        $encoder = $this->container->get('security.encoder_factory')->getEncoder($user);
        $password = Utils::randomString(16, 3, true);

        $user->setPassword($encoder->encodePassword($password, $user->getSalt()));
        $em->flush();

        $this->sendAccessInfo($user, $password);

        return $this->routeRedirectView($this->getCollectionRoute(), array('id' => $id), Codes::HTTP_NO_CONTENT);
    }

    protected function sendAccessInfo(User $user, $password)
    {
        list($fromEmail, $fromName) = $this->getSenderData();

        $appRouteName = $this->getAppRouteName();
        $appRouteUrl = null;
        if ($this->get('router')->getRouteCollection()->get($appRouteName)) {
            $appRouteUrl = $this->generateUrl($appRouteName, array(), true);
        }

        $message = \Swift_Message::newInstance()
                ->setSubject('Access Information')
                ->setFrom($fromEmail, $fromName)
                ->setTo($user->getEmail())
                ->setReturnPath($fromEmail)
                ->setBody(
                    $this->renderView(
                        'BumpRestBundle:Email:user-password.html.twig',
                        array(
                            'user' => $user,
                            'password' => $password,
                            'app_route' => $appRouteUrl,
                        )
                    ),
                    'text/html'
                )
            ;

        return $this->get('mailer')->send($message);
    }

    //hack to avoid hardcoded parameters
    //@TODO move to bundle configuration
    protected function getAppRouteName()
    {
        return 'bump_frontend_default_index';
    }

    //@TODO move to bundle configuration
    protected function getSenderData()
    {
        if (!$this->container->hasParameter('sender_address') || !$this->container->hasParameter('sender_name')) {
            throw new \RuntimeException("Expected for api_root_username parameter");
        }

        return array(
            $this->container->getParameter('sender_address'),
            $this->container->getParameter('sender_name'),
        );
    }

    //@TODO move to bundle configuration
    protected function getRootUsername()
    {
        if (!$this->container->hasParameter('api_root_username')) {
            throw new \RuntimeException("Expected for api_root_username parameter");
        }

        return $this->container->getParameter('api_root_username');
    }

    /**
     * Update existing user from the submitted data.
     *
     * @ApiDoc(
     *   resource = true,
     *   section = "Users",
     *   input = "Bump\RestBundle\Form\Type\UserType",
     *   statusCodes = {
     *     204 = "Returned when successful",
     *     400 = "Returned when the form has errors",
     *     403 = "Returned when the user do not have the necessary permissions"
     *   }
     * )
     *
     *
     * @param Request $request the request object
     * @param int     $id      the user id
     *
     * @throws NotFoundHttpException when user not exist
     */
    public function putUserAction($id)
    {
        $user = $this->findEntityOrThrowNotFound($id);
        $current = $this->get('security.context')->getToken()->getUser();
        if ($user->getUsername() == $this->container->getParameter('api_root_username')) {
            throw new HttpException(Codes::HTTP_FORBIDDEN, "You can't modify default account.");
        }

        return $this->handleForm($this->getUserFormType(), $user);
    }

    /**
     * Update partial existing user from the submitted data.
     *
     * @ApiDoc(
     *   resource = true,
     *   section = "Users",
     *   input = "Bump\RestBundle\Form\Type\UserType",
     *   statusCodes = {
     *     204 = "Returned when successful",
     *     400 = "Returned when the form has errors"
     *   }
     * )
     *
     *
     * @param int $id the keyword id
     *
     * @throws NotFoundHttpException when keyword not exist
     */
    public function patchUserAction($id)
    {
        $user = $this->findEntityOrThrowNotFound($id);
        $current = $this->get('security.context')->getToken()->getUser();
        if ($user->getUsername() == $this->container->getParameter('api_root_username')) {
            throw new HttpException(Codes::HTTP_FORBIDDEN, "You can't modify default account.");
        }

        return $this->handlePatchForm($this->getUserFormType(), $user);
    }

    /**
     * Delete user by id
     *
     * @ApiDoc(
     *   resource = true,
     *   section = "Users",
     *   statusCodes={
     *     204="Returned when successful",
     *     403="Returned when the user do not have the necessary permissions",
     *     404="Returned when the user is not found",
     *   }
     * )
     *
     * @param Request $request the request object
     * @param int     $id      the user id
     *
     * @return RouteRedirectView
     *
     * @throws NotFoundHttpException when user not exist
     */
    public function deleteUserAction(Request $request, $id)
    {
        $user = $this->findEntityOrThrowNotFound($id);
        $current = $this->get('security.context')->getToken()->getUser();
        if ($user->getUsername() == $this->container->getParameter('api_root_username')) {
            throw new HttpException(Codes::HTTP_FORBIDDEN, "You can't modify default account.");
        }

        $em = $this->getDoctrine()->getManager();
        $em->remove($user);
        $em->flush();

        return $this->routeRedirectView($this->getCollectionRoute(), array(), Codes::HTTP_NO_CONTENT);
    }

    /**
     * Bulk delete users by comma separated id's
     *
     * @ApiDoc(
     *   resource = true,
     *   section = "Users",
     *   statusCodes={
     *     204="Returned when successful",
     *     403="Returned when the user do not have the necessary permissions",
     *     404="Returned when the item is not found",
     *     500 = "Returned when Server error occurred."
     *   }
     * )
     *
     * @param  int                   $id the item id
     * @return RouteRedirectView
     * @throws NotFoundHttpException when item not exist
     */
    public function deleteUserBulkAction($ids)
    {
        return $this->handleDeleteBulk($ids);
    }

    /**
     * Bulk disable users by comma separated id's
     *
     * @ApiDoc(
     *   resource = true,
     *   section = "Users",
     *   statusCodes={
     *     204="Returned when successful",
     *     403="Returned when the user do not have the necessary permissions",
     *     404="Returned when the item is not found",
     *     500 = "Returned when Server error occurred."
     *   }
     * )
     *
     * @Annotations\Route("/users/{ids}/bulk-disable")
     *
     */
    public function postDisableUsersBulkAction($ids)
    {
        return $this->setUserStatusBulk($ids, false);
    }

    /**
     * Bulk enable users by comma separated id's
     *
     * @ApiDoc(
     *   resource = true,
     *   section = "Users",
     *   statusCodes={
     *     204="Returned when successful",
     *     403="Returned when the user do not have the necessary permissions",
     *     404="Returned when the item is not found",
     *     500 = "Returned when Server error occurred."
     *   }
     * )
     *
     * @Annotations\Route("/users/{ids}/bulk-enable")
     *
     */
    public function postEnableUsersBulkAction($ids)
    {
        return $this->setUserStatusBulk($ids, true);
    }

    protected function setUserStatusBulk($ids, $status)
    {
        $status = (bool) $status;
        $ids = explode(',', $ids);

        if (empty($ids)) {
            throw new BadRequestHttpException("Expected entity ids separated by ','");
        }

        $alias = $this->getCollectionAlias();
        $em = $this->getDoctrine()->getManager();
        $qb = $this->getQueryBuilder($alias);
        $qb->where("{$alias}.id IN(:ids)")
           ->setParameter('ids', $ids);

        $batchSize = 20;
        $i = 0;
        $iterableResult = $qb->getQuery()->iterate();
        while (($row = $iterableResult->next()) !== false) {
            $user = $row[0];
            $user->setIsActive($status);
            if (($i % $batchSize) == 0) {
                $em->flush(); // Executes all deletions.
                $em->clear(); // Detaches all objects from Doctrine!
            }
            ++$i;
        }

        $em->flush();

        if ($i == 0) {
            $message = $this->getNotFoundMessage();
            throw $this->createNotFoundException(sprintf($message, implode($idSeparator, $ids)));
        }

        return $this->routeRedirectView($this->getCollectionRoute(), $this->getCollectionRouteParams(), Codes::HTTP_NO_CONTENT);
    }

    /**
     * Removes user, this is alias of delete action with HTTP GET method allow,
     * it useful to present remove link on the simple ui interface, without ajax thechnics on other.
     *
     * @ApiDoc(
     *   resource = true,
     *   section = "Users",
     *   statusCodes={
     *     204="Returned when successful",
     *     403="Returned when the user do not have the necessary permissions",
     *     404="Returned when the user is not found"
     *   }
     * )
     *
     * @param Request $request the request object
     * @param int     $id      the user id
     *
     * @return RouteRedirectView
     *
     * @throws NotFoundHttpException when user not exist
     */
    public function removeUserAction(Request $request, $id)
    {
        return $this->deleteUserAction($request, $id);
    }

    protected function getUserFormType()
    {
        return new UserType();
    }

    abstract protected function getRoleEntityId();
}

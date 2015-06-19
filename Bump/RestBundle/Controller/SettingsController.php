<?php

namespace Bump\RestBundle\Controller;

use FOS\RestBundle\Util\Codes;
use FOS\RestBundle\Controller\Annotations;
use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\Request\ParamFetcherInterface;
use FOS\RestBundle\View\RouteRedirectView;
use FOS\RestBundle\Controller\Annotations\Post;
use FOS\RestBundle\Controller\Annotations\NamePrefix;
use FOS\RestBundle\View\View;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;
use Symfony\Component\HttpFoundation\Request;
use Bump\RestBundle\Form\Type\SettingType;
use Bump\RestBundle\Events;
use Bump\RestBundle\Event\SettingModifiedEvent;
use Symfony\Component\HttpFoundation\Response;

/**
 * Should be extended
 */
abstract class SettingsController extends RestController
{
    /**
     * Retrieve list of settings. <br>
     * Implemented: search, filter and pagination
     * @ApiDoc(
     *   resource = true,
     *   section="Settings",
     *   statusCodes = {
     *     200 = "Returned when successful",
     *     403 = "Returned when the user do not have the necessary permissions",
     *   }
     * )
     *
     * @Annotations\QueryParam(name="page", requirements="\d+", nullable=true, default="1", description="Current page number.")
     * @Annotations\QueryParam(name="limit", requirements="\d+", nullable=true, default="5", description="How many users to return.")
     * @Annotations\QueryParam(name="order_by", requirements="\w+", strict=true, nullable=true, default="name", description="Field name to order by")
     * @Annotations\QueryParam(name="order", requirements="asc|desc|ASC|DESC", nullable=true, default="asc", description="Sort")
     * @Annotations\QueryParam(name="query", nullable=true, default=null, description="Search query")
     * @Annotations\QueryParam(name="filters", nullable=true, default=null, description="Filters JSON string")
     * @Annotations\View()
     *
     * @param ParamFetcherInterface $paramFetcher param fetcher service
     *
     * @return array
     */
    public function getSettingsAction(ParamFetcherInterface $paramFetcher)
    {
        return $this->handleGetCollection($paramFetcher, null, false);
    }

    /**
     * Retrieve single setting value requested by name
     *
     * @ApiDoc(
     *   section="Settings",
     *   output = "Bump\RestBundle\Entity\Setting",
     *   statusCodes = {
     *     200 = "Returned when successful",
     *     403 = "Returned when the user do not have the necessary permissions",
     *     404 = "Returned when the setting is not found"
     *   }
     * )
     *
     * @param int $name the settings name
     *
     * @return array
     *
     * @throws NotFoundHttpException when setting not exist
     */
    public function getSettingAction($name)
    {
        return $this->handleGetSingle($name, 'findOneByNameOrSlug', false);
    }

    /**
     * Update setting from the submitted data.
     *
     * @ApiDoc(
     *   resource = true,
     *   section = "Settings",
     *   input = "Bump\RestBundle\Form\Type\SettingType",
     *   statusCodes = {
     *     204 = "Returned when successful",
     *     400 = "Returned when the form has errors",
     *     403 = "Returned when the user do not have the necessary permissions"
     *   }
     * )
     *
     * @Annotations\View(
     *   templateVar="form"
     * )
     *
     * @param string     $name      the setting name
     *
     * @throws NotFoundHttpException when user not exist
     */
    public function putSettingAction($name)
    {
        $setting = $this->findEntityOrThrowNotFound($name, 'findOneByNameOrSlug');
        return $this->handleForm(new SettingType, $setting, function($entity, $isNew, $self, $defaultCallback) {
            call_user_func_array($defaultCallback, array($entity, $isNew, $self));
            $self->get('event_dispatcher')->dispatch(Events::SETTING_MODIFIED, new SettingModifiedEvent($entity));
        });
    }

    public function postSettingAction()
    {
        $data = $this->getRequest()->request->all();
        $repo = $this->getRepository();

        $settings = $repo->findAllByNameOrSlug(array_keys($data));
        if (count($settings)) {
            $validator = $this->get('validator');
            $allErrors = array();

            foreach($settings as $setting) {
                $alias = $setting->getSlug();
                if (isset($data[$alias])) {
                    $value = $data[$alias];
                    if (empty($value)) {
                        $value = null;
                    }

                    $setting->setValue($value);
                    $errors = $validator->validate($setting);
                    if (count($errors)>0) {
                        $allErrors[$setting->getSlug()] = array('errors'=>$errors);
                    }
                }
            }
            if (count($allErrors)>0) {
                return $this->view($allErrors, 400)->setFormat('json');
            } else {
                $this->getDoctrine()->getManager()->flush();
            }
        }

        $this->get('event_dispatcher')->dispatch(Events::SETTING_MODIFIED, new SettingModifiedEvent(reset($settings)));
        return new Response('', 204, array('Content-type'=>'text/plain'));
    }
}
<?php

namespace Bump\RestBundle\Services;

use Doctrine\ORM\EntityManager;
use Doctrine\Common\Cache\CacheProvider;
use Bump\RestBundle\Event\SettingModifiedEvent;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Console\Input\ArgvInput;
use \Symfony\Bundle\FrameworkBundle\Console\Application;

class Settings
{
    protected $container;
    protected $em;
    protected $entityId;
    protected $repo;
    protected $cache;
    protected $settings;

    public function __construct(Container $container, EntityManager $em, CacheProvider $cache, $entityId)
    {
        $this->container = $container;
        $this->em = $em;
        $this->entityId = $entityId;
        $this->cache = $cache;
    }

    public function get($name)
    {
        $settings = $this->load();
        if (!isset($settings[$name])) {
            $this->createNotFoundException($name);
        }

        return $settings[$name]->getValue();
    }

    public function all()
    {
        $settings = array();

        foreach ($this->load() as $setting) {
            $settings[$setting->getName()] = $setting->getValue();
        }

        return $settings;
    }

    protected function load()
    {
        if (!empty($this->settings)) {
            return $this->settings;
        }

        $cacheKey = get_class($this);
        if ($this->cache->contains($cacheKey)) {
            return $this->cache->fetch($cacheKey);
        }

        $this->settings = array();
        $settings = $this->getRepo()->findAll();

        foreach($settings as $setting) {
            $this->settings[$setting->getName()] =$setting;
        }

        $this->cache->save($cacheKey, $this->settings, 0);

        return $this->settings;
    }

    public function onSettingModified(SettingModifiedEvent $event)
    {
        $this->settings = null;
        $this->cache->delete(get_class($this));
        //clear application cache
        $input = new ArgvInput(array('console','cache:clear'));
        $application = new Application($this->container->get('kernel'));
        $application->setAutoExit(false);

        @$application->run($input);
    }


    protected function getRepo()
    {
        if (empty($repo)) {
            $this->repo = $this->em->getRepository($this->entityId);
        }

        return $this->repo;
    }


    protected function createNotFoundException($name) {
        return new \RuntimeException(sprintf('Setting "%s" couldn\'t be found.', $name));
    }
}
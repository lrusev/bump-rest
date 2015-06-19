<?php

namespace Bump\RestBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface,
    Symfony\Component\DependencyInjection\ContainerBuilder,
    Symfony\Component\DependencyInjection\Reference,
    Symfony\Component\DependencyInjection\DefinitionDecorator,
    Symfony\Component\DependencyInjection\Definition,
    Doctrine\Bundle\DoctrineBundle\ConnectionFactory,
    Doctrine\DBAL\Exception\TableNotFoundException
    ;

class SettingsCompilerPass implements CompilerPassInterface
{
	protected $databaseConnection;

    public function process(ContainerBuilder $container)
    {
    	$aliases = array();
    	$this->initConnection($container);

    	$settings = $this->loadSettings();
    	foreach($settings as $name =>$value) {
    		if (false!==($pos = strpos($name, '.')) && (($alias = substr($name, 0, $pos))) && !in_array($alias, $aliases)) {
    			$aliases[] = $alias;
    		}

    		$container->setParameter($name, $value);
    	}
    	$this->closeConnection();

    	foreach($aliases as $alias) {
    		if ($container->hasParameter($alias . '.config')) {
    			$container->setParameter($alias . '.config', array_merge($container->getParameter($alias . '.config'), $settings));
    		}

    		if ($container->hasParameter($alias . '.indexes')) {
    			$indexes = $container->getParameter($alias . '.indexes');
    			foreach ($indexes as $name) {
    				$index = $container->getParameter($name);
    				$container->setParameter($name, $this->updateIndex($settings, $index, $name));
    			}
    		}
    	}
    }

    protected function updateIndex(array $settings, array $index, $alias)
    {
    	$updated = $index;

    	foreach($index as $key=>$value) {
    		$name = $alias. '.' .$key;
    		if (!is_array($value)) {
    			if (isset($settings[$name])) {
    				$updated[$key]=$settings[$name];
    			}
    		} else {
    		    $updated[$key] = $this->updateIndex($settings, $value, $name);
    		}
    	}

    	return $updated;
    }

    protected function loadSettings()
    {
    	$settings = array();
    	if (false === $this->checkTableExist('settings')) {
            return $settings;
        }

    	$queryBuilder = $this->databaseConnection->createQueryBuilder();

        $queryBuilder->select('s.name, s.value')->from('settings', 's');
        $query = $this->databaseConnection->query($queryBuilder);

        while (false !== $result = $query->fetchObject()) {
        	$settings[$result->name] = $result->value;
        }

        return $settings;
    }

    protected function checkTableExist($table)
    {
        $queryBuilder = $this->databaseConnection->createQueryBuilder();
        $queryBuilder->select('*');
        $queryBuilder->from($table, 't');

        try {
            $this->databaseConnection->query($queryBuilder);
        } catch (TableNotFoundException $e) {
            return false;
        }

        return true;
    }

    protected function initConnection(ContainerBuilder $container)
    {
        $configs = $container->getExtensionConfig('doctrine');
        $mergedConfig = array();
        foreach ($configs as $config) {
            $mergedConfig = array_merge($mergedConfig, $config);
        }

        $mergedConfig = $container->getParameterBag()->resolveValue($mergedConfig);
        $params = $mergedConfig['dbal'];
        if (array_key_exists('connections', $params)) {
            $defaultEntityManager = $mergedConfig['orm']['default_entity_manager'];
            $defaultConnection = $mergedConfig['orm']['entity_managers'][$defaultEntityManager]['connection'];
            $params = $params['connections'][$defaultConnection];
        }

        $connection_factory = new ConnectionFactory(array());
        $this->databaseConnection = $connection_factory->createConnection($params);
        $this->databaseConnection->connect();
    }

    protected function closeConnection()
    {
        if ($this->databaseConnection->isConnected()) {
            $this->databaseConnection->close();
        }
    }
}
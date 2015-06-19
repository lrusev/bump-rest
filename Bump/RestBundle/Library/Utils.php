<?php

namespace Bump\RestBundle\Library;

use Doctrine\ORM\Query\ResultSetMappingBuilder;

class Utils {

	public static function randomString($length=16, $mode=1, $chars=true)
	{
		$string = '';
		$possible = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		if($chars) {
			$possible .= strtolower($possible);
		}

		switch($mode) {
			case 3:   $possible .= '`~!@#$%^&*()_-+=|}]{[":;<,>.?/';
			case 2:   $possible .= '0123456789';
			break;
		}
		for($i=1;$i<$length;$i++) {
			$char = substr($possible, mt_rand(0, strlen($possible)-1), 1);
			$string .= $char;
		}

		return $string;
	}

	public static function generateUniqueId(\Doctrine\ORM\EntityManager $em, $tableName='users', $field='track_id', $length=8, $maxAttempts=10)
	{
		$unique = false;
        $attempts = 0;
        $rsm = new ResultSetMappingBuilder($em);
        $rsm->addScalarResult('count', 'count');

        do {
	        $hash = self::randomString($length, 2, true);

        	$query = $em->createNativeQuery("SELECT count(*) as count FROM {$tableName} where {$field} = :hash", $rsm);
        	$query->setParameter('hash', $hash);
        	if (0===intval($query->getSingleScalarResult())) {
        		$unique = true;
        		break;
        	}

        	$attempts++;
        } while(!$unique && $attempts<$maxAttempts);

        if (!$unique) {
            throw new \Exception("Can't generate unique track id");
        }

        return $hash;
	}

    public static function parseJSON($data, $options=true, $throw=false)
    {
        $decoded = json_decode($data, $options);

        if (($jsonLastErr = json_last_error()) != JSON_ERROR_NONE) {
            switch ($jsonLastErr) {
                case JSON_ERROR_DEPTH:
                    if ($throw) {
                        throw new \Exception('Decoding failed: Maximum stack depth exceeded');
                    } else {
                        return false;
                    }
                case JSON_ERROR_CTRL_CHAR:
                    if ($throw) {
                        throw new \Exception('Decoding failed: Unexpected control character found');
                    } else {
                        return false;
                    }
                case JSON_ERROR_SYNTAX:
                    if ($throw) {
                        throw new \Exception('Decoding failed: Syntax error');
                    } else {
                        return false;
                    }
                default:
                    if ($throw) {
                        throw new \Exception('Decoding failed: Syntax error');
                    } else {
                        return false;
                    }
            }
        }

        return $decoded;
    }

    public static function isAssoc($array = null) {
        if (!is_array($array)) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    public static function exposeParameters(\Symfony\Component\DependencyInjection\ContainerBuilder $container, array $config, $alias, array $indexes=array(), $glue='.')
    {
        $exposed = array();
        $glue = trim($glue);

        foreach($config as $name=>$value) {
            $key = $alias . $glue . $name;
            if (!is_array($value)) {
                $container->setParameter($key, $value);
                $exposed[$key]=$value;
                continue;
            } else if (in_array($key, $indexes)) {
                $container->setParameter($key, $value);
                $exposed[$key] = $value;
            }

            $exposed = array_merge($exposed, self::exposeParameters($container, $value, $key, $indexes, $glue));
        }

        return $exposed;
    }
}
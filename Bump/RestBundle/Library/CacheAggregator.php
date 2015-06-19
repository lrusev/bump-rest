<?php
namespace Bump\RestBundle\Library;

class CacheAggregator {

    protected $cache;
    protected $cachePrefix;

    public function __construct(\Doctrine\Common\Cache\Cache $cache, $cachePrefix='')
    {
        $this->cache = $cache;
        $this->setCachePrefix($cachePrefix);
    }

    public function remember($key, $lifetime, \Closure $callback)
    {
        if (!empty($this->cachePrefix)) {
            $key = $this->cachePrefix . $key;
        }

        if ($this->cache->contains($key)) {
            return $this->cache->fetch($key);
        }

        $this->cache->save($key, ($value = $callback()), $lifetime);
        return $value;
    }

    public function setCachePrefix($prefix)
    {
        $this->cachePrefix = $prefix;
        return $this;
    }

    public function getCachePrefix()
    {
        return $this->cachePrefix;
    }

    public function __call($name, $args)
    {
        if (method_exists($this->cache, $name)) {
            return call_user_func_array(array($this->cache, $name), $args);
        }

        throw new \BadMethodCallException("Call to undefined method {$name}");
    }
}
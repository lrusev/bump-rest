<?php

namespace Bump\RestBundle\Library;

interface CacheableRepository {
    public function getCacheIds($entity);
}
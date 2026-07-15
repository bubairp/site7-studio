<?php

namespace site7\studio\services;

use Craft;
use craft\base\Component;
use yii\caching\TagDependency;

/**
 * Class CacheService
 *
 * Standardized wrapper around Craft's caching mechanisms for Site7 Studio.
 */
class CacheService extends Component
{
    const DEFAULT_TAG = 'site7-studio';

    /**
     * Gets a cached value or resolves it using the given callback.
     *
     * @param string $key
     * @param callable $callback
     * @param int|null $duration
     * @param array $tags
     * @return mixed
     */
    public function getOrSet(string $key, callable $callback, int $duration = null, array $tags = [])
    {
        $cache = Craft::$app->getCache();
        
        $data = $cache->get($key);
        if ($data !== false) {
            return $data;
        }

        $data = $callback();
        
        $tags[] = self::DEFAULT_TAG;
        $dependency = new TagDependency(['tags' => $tags]);
        
        $cache->set($key, $data, $duration, $dependency);
        
        return $data;
    }

    /**
     * Invalidates caches for given tags.
     *
     * @param array $tags
     */
    public function invalidateTags(array $tags): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), $tags);
    }
}

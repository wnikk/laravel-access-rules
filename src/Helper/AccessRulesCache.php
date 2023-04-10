<?php
namespace Wnikk\LaravelAccessRules\Helper;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;

trait AccessRulesCache
{
    /** @var \Illuminate\Contracts\Cache\Repository */
    protected $cache;

    /** @var array{expiration_time:\DateInterval|int, key:string, store:string} */
    protected static $cacheParams;

    /** @var string */
    protected $cacheKey;

    /** @var array<string> */
    protected $permissions;

    /**
     * Initialize the trait
     *
     * @return void
     */
    protected function initializeAccessRulesCache()
    {
        self::$cacheParams = config('access.cache');
        if (empty(self::$cacheParams['expiration_time'])) self::$cacheParams['expiration_time'] = 24*60;
        if (empty(self::$cacheParams['key'])) self::$cacheParams['key'] = 'access_rules.cache.';
        if (empty(self::$cacheParams['store'])) self::$cacheParams['store'] = 'default';

        $this->cache = $this->getCacheStoreFromConfig();
    }

    /**
     * Returns an object for cache Manager
     *
     * @return Repository
     */
    protected function getCacheStoreFromConfig(): Repository
    {
        $cacheManager = app(CacheManager::class);

        // the 'default' fallback here is from the permission.php config file,
        // where 'default' means to use config(cache.default)
        $cacheDriver = self::$cacheParams['store']??'default';

        // when 'default' is specified, no action is required since we already have the default instance
        if ($cacheDriver === 'default') {
            return $cacheManager->store();
        }

        // if an undefined cache store is specified, fallback to 'array' which is Laravel's closest equiv to 'none'
        if (!array_key_exists($cacheDriver, $cacheDriver)) {
            $cacheDriver = 'array';
        }

        return $cacheManager->store($cacheDriver);
    }

    /**
     *  Set the owner id for cache
     *
     * @param int $type
     * @param $id
     * @return void
     */
    protected function setOwnerCache(int $type, $id = null)
    {
        $this->cacheKey = $this->makeCacheKey($type, $id);
    }

    /**
     * make key for cache
     *
     * @param int $type
     * @param $id
     * @return string
     */
    protected function makeCacheKey(int $type, $id = null): string
    {
        return self::$cacheParams['key'].'.'.$type.'.'.$id;
    }

    /**
     * Loads the rules from cache
     *
     * @return bool
     */
    protected function loadCachePermissions(): bool
    {
        if (!$this->cacheKey || !$this->cache->has($this->cacheKey)) return false;

        $this->permissions = $this->cache->get($this->cacheKey);

        return true;
    }

    /**
     * Saves previously loaded rules to cache
     *
     * @return bool
     */
    protected function saveCachePermissions(): bool
    {
        if (!$this->cacheKey) return false;

        $this->updateCacheList();

        return $this->cache->put(
            $this->cacheKey,
            $this->permissions,
            self::$cacheParams['expiration_time'] * 60
        );
    }

    /**
     * Save all keys list, for clean
     *
     * @return bool
     */
    protected function updateCacheList(): bool
    {
        $deadline = time() + self::$cacheParams['expiration_time'] * 60;
        $key = self::$cacheParams['key'].'_all';

        $all = $this->cache->get($key)??[];
        $all[$this->cacheKey] = $deadline;

        foreach ($all as $k=>$t) {
            if ($t < time()) unset($all[$k]);
        }

        return $this->cache->put(
            $key,
            $all,
            self::$cacheParams['expiration_time'] * 60
        );
    }

    /**
     * Flush the cache.
     *
     * @return bool
     */
    public function forgetCachedPermissions(): bool
    {
        if (!$this->cacheKey) return false;

        $this->permissions = null;

        return $this->cache->forget($this->cacheKey);
    }

    /**
     * Clear list cache for selected owners
     *
     * @param array<array{type: int, id: mixed}> $selected
     * @return void
     */
    public function forgetSelectedCachePermission(array $selected): bool
    {
        foreach ($selected as $one) {
            $this->cache->forget($this->makeCacheKey(
                (string) $one['type']??$one[0]??$one,
                $one['id']??$one[1]??null
            ));
        }
    }

    /**
     * Clear all cached for all users.
     *
     * @return void
     */
    public function clearAllCachedPermissions()
    {
        $this->permissions = null;

        $key = self::$cacheParams['key'].'_all';
        $all = $this->cache->get($key)??[];
        $all[$key] = 1;

        foreach ($all as $item => $timer) {
            $this->cache->forget($item);
        }
    }
}

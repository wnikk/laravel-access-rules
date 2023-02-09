<?php

namespace Wnikk\LaravelAccessRules;

use Illuminate\Cache\CacheManager;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Eloquent\Collection;
use Wnikk\LaravelAccessRules\Models\Assay;

class AccessRules extends Assay
{
    /** @var \Illuminate\Contracts\Cache\Repository */
    protected $cache;

    /** @var \Illuminate\Cache\CacheManager */
    protected $cacheManager;

    /** @var array{expiration_time:\DateInterval|int, key:string, store:string} */
    protected static $cacheParams;

    /** @var string */
    protected $cacheKey;

    /** @var int */
    protected $thisOwnerType = -1;

    /** @var int|null */
    protected $thisOwnerId;

    /** @var array<string> */
    protected $permissions;

    /**
     * PermissionRegistrar constructor.
     *
     * @param  \Illuminate\Cache\CacheManager  $cacheManager
     */
    public function __construct(CacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
        $this->initializeCache();
        parent::__construct();
    }

    /**
     * Loading caching parameters
     *
     * @return void
     */
    public function initializeCache()
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
        // the 'default' fallback here is from the permission.php config file,
        // where 'default' means to use config(cache.default)
        $cacheDriver = self::$cacheParams['store']??'default';

        // when 'default' is specified, no action is required since we already have the default instance
        if ($cacheDriver === 'default') {
            return $this->cacheManager->store();
        }

        // if an undefined cache store is specified, fallback to 'array' which is Laravel's closest equiv to 'none'
        if (!array_key_exists($cacheDriver, $cacheDriver)) {
            $cacheDriver = 'array';
        }

        return $this->cacheManager->store($cacheDriver);
    }

    /**
     * Set the owner id for user/groups support, this id is used when querying roles
     *
     * @param  int|\Illuminate\Database\Eloquent\Model  $typeOrModel
     * @param  null|int|\Illuminate\Database\Eloquent\Model  $id
     */
    public function setOwner($typeOrModel, $id = null)
    {
        if ($typeOrModel instanceof Model) {
            if ($id === null) $id = $typeOrModel->getKey();
            $ownerTypes  = config('access.owner_types');
            $class       = get_class($typeOrModel);
            $typeOrModel = array_search($class, $ownerTypes, true);
            if ($typeOrModel === false) {
                throw new \LogicException('Error: config/access.php not find on owner_types class "'.$class.'".');
            }
        }

        $this->thisOwnerType = $typeOrModel;
        $this->thisOwnerId   = $id;
        $this->cacheKey      = self::$cacheParams['key'].'.'.$type.'.'.$id;
    }

    /**
     * Load permissions from cache
     */
    protected function loadPermissions()
    {
        if ($this->permissions) return;
        if ($this->loadCachePermissions()) return;


        $this->permissions = $this->getAllPermittedRule(
            $this->thisOwnerType,
            $this->thisOwnerId
        );

        $this->saveCachePermissions();
        return;
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

    /**
     * Checks what is right for the current user
     *
     * @param $permission
     * @param $args
     * @return bool
     */
    public function hasPermission($permission, $args = null): bool
    {
        $this->loadPermissions();
        return $this->filterPermission($this->permissions, $permission, $args);
    }

    /**
     * @param Authorizable $user
     * @param string $ability
     * @param $args
     * @return bool
     */
    public function checkOwnerPermission(Authorizable $user, string $ability, $args = null): bool
    {
        if (method_exists($user, 'hasPermission')) {
            return $user->hasPermission($ability, $args) ?: false;
        }

        $this->setOwner($user);
        return $this->hasPermission($ability);
    }
}

<?php

namespace Wnikk\LaravelAccessRules;

use Illuminate\Cache\CacheManager;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Eloquent\Collection;
use Wnikk\LaravelAccessRules\Models\Helper;

class AccessRules extends Helper
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
     * @param  int|\Illuminate\Database\Eloquent\Model  $type
     * @param  null|int|\Illuminate\Database\Eloquent\Model  $id
     */
    public function setOwner($type, $id = null)
    {
        if ($type instanceof \Illuminate\Database\Eloquent\Model) {
            $ownerTypes = config('access.owner_types');
            $class      = get_class($type);
            $id         = $type->getKey();
            $type       = array_search($class, $ownerTypes, true);
            if ($type === false) {
                throw new \Exception('Error: config/access.php not find on owner_types class "'.$class.'".');
            }
        }

        if ($id instanceof \Illuminate\Database\Eloquent\Model) {
            $id = $id->getKey();
        }
        $this->thisOwnerType = $type;
        $this->thisOwnerId   = $id;

        $this->cacheKey = self::$cacheParams['key'].'.'.$type.'.'.$id;
    }

    /**
     * Flush the cache.
     *
     * @return bool
     */
    public function forgetCachedPermissions()
    {
        return $this->cache->forget($this->cacheKey);
    }


    /**
     * Load permissions from cache
     */
    private function loadPermissions()
    {
        if ($this->permissions) {
            return;
        }

        if ($this->cache->has($this->cacheKey)) {
            $this->permissions = $this->cache->get($this->cacheKey);
            return;
        }

        $this->permissions = $this->getAllPermittedRole(
            $this->thisOwnerType,
            $this->thisOwnerId
        );

        $this->cache->put(
            $this->cacheKey,
            $this->permissions,
            self::$cacheParams['expiration_time'] * 60
        );

        return;
    }

    /**
     * Checks what is right for the current user
     *
     * @param $permission
     * @return bool
     */
    public function hasPermission($permission): bool
    {
        $this->loadPermissions();
        return $this->filterPermission($this->permissions, $permission);
    }

    /**
     * @param Authorizable $user
     * @param string $ability
     * @param $args
     * @return bool
     */
    public static function checkPermission(Authorizable $user, string $ability, $args): bool
    {
        if (method_exists($user, 'hasPermission')) {
            return $user->hasPermission($ability) ?: false;
        }
        $self = app(__CLASS__);
        $self->setOwner($user);
        return $self->hasPermission($ability);
    }
}

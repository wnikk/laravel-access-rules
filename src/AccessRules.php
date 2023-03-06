<?php

namespace Wnikk\LaravelAccessRules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Wnikk\LaravelAccessRules\Contracts\AccessRules as AccessRulesContract;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;
use Wnikk\LaravelAccessRules\Models\Aggregator;
use Wnikk\LaravelAccessRules\Helper\AccessRulesCache;
use Wnikk\LaravelAccessRules\Helper\AccessRulesTypeOwner;
use Wnikk\LaravelAccessRules\Helper\AccessRulesPermission;

class AccessRules extends Aggregator implements AccessRulesContract
{
    use AccessRulesCache, AccessRulesTypeOwner, AccessRulesPermission;

    /** @var int */
    protected $thisOwnerType = -1;

    /** @var int|null */
    protected $thisOwnerId;

    /** @var array<string> */
    protected $permissions;

    /** @var string|null */
    protected static $lastDisallow;

    /**
     * PermissionRegistrar constructor.
     */
    public function __construct()
    {
        $this->initializeAccessRulesCache();
    }

    /**
     * Set the owner id for user/groups support, this id is used when querying roles
     *
     * @param  int|\Illuminate\Database\Eloquent\Model  $type
     * @param  null|int|\Illuminate\Database\Eloquent\Model  $id
     */
    public function setOwner($type, $id = null)
    {
        if ($type instanceof Model)
        {
            if ($id === null) $id = $type->getKey();
            $type = get_class($type);
        }
        $type = $this->getTypeID($type);

        $this->thisOwnerType = $type;
        $this->thisOwnerId   = $id;

        $this->setOwnerCache($type, $id);
    }

    /**
     * @return null|OwnerContract
     */
    public function getOwner()
    {
        return $this->findOwner($this->thisOwnerType, $this->thisOwnerId);
    }

    /**
     * @param $type
     * @param $id
     * @param $name
     * @return OwnerContract
     */
    public function newOwner($type, $id = null, $name = null)
    {
        $this->setOwner($type, $id);
        $owner = $this->getOwner();
        if (!$owner) {
            $owner = $this->getOwnerModel();
            $owner->type        = $this->thisOwnerType;
            $owner->original_id = $this->thisOwnerId;
            $owner->name        = $name;
            $owner->save();
        }
        return $owner;
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
     * Returns the name of the last prohibited rule
     *
     * @return string|null
     */
    public static function getLastDisallowPermission()
    {
        return (self::$lastDisallow);
    }

    /**
     * Checks what is right for the current user
     *
     * @param $ability
     * @param $args
     * @return bool
     */
    public function hasPermission($ability, $args = null): bool
    {
        $this->loadPermissions();

        $check = $this->filterPermission($this->permissions, $ability, $args);

        if (!$check) $this::$lastDisallow = $ability;
        return $check;
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
        return $this->hasPermission($ability, $args);
    }
}

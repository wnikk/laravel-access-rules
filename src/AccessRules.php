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
     * @param  int|Model|OwnerContract  $type
     * @param  null|int  $id
     */
    public function setOwner($type, $id = null)
    {
        if ($type instanceof OwnerContract && $type->id)
        {
            $id   = $type->original_id;
            $type = $type->type;
        }
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
    }

    /**
     * Returns a technical map of permits without the rules.
     *
     * @return array{ allow:array{rule_id:int, option:string|null}, disallow: array{rule_id:int, option:string|null} }
     */
    public function getThisPermitMap(): array
    {
        return $this->getAllRuleIDMap(
            $this->thisOwnerType,
            $this->thisOwnerId
        );
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
     * @param string  $ability
     * @param array|null  $args
     * @return bool|null
     */
    public function hasPermission($ability, $args = null): ?bool
    {
        $this->loadPermissions();

        $check = $this->filterPermission($this->permissions, $ability);

        if (!$check) $this::$lastDisallow = $ability;

        return $check;
    }

    /**
     * Check that owner is the author
     *
     * @param  Model  $user
     * @param  mixed|Model  $model
     * @return bool
     */
    public function checkUserIsAuthor($user, $model): bool
    {
        if (
            !$user->getKey() ||
            !is_object($model) ||
            !method_exists($model, 'getKey') ||
            !$model->getKey()
        ) return false;

        // object to name
        $property = strtolower(class_basename($user));

        // remove last letter "s"
        if (substr($property, -1) === 's') $property = substr($property, 0, -1);

        // "user" to "user_id"
        $property .= '_'.$user->getKeyName();

        return (isset($model->$property) && $model->$property === $user->getKey());
    }

    /**
     * Checks that the user is available
     * for permission to edit self records
     *
     * @param $user
     * @param string $ability
     * @param $args
     * @return bool|null
     */
    public function checkMagicRuleSelf($user, string $ability, $args): ?bool
    {
        $check = null;
        // Check magic permission {rule}.self
        if ($ability && !empty($args[0]) && is_object($args[0]))
        {
            if ($this->hasPermission($ability.'.self', $args))
            {
                $check = $this->checkUserIsAuthor($user, $args[0]);
            } else {
                // update last permission that was checked for display in messages
                $this::$lastDisallow = $ability;
            }
        }
        return $check;
    }

    /**
     * @param Authorizable $user
     * @param string  $ability
     * @param array|null  $args
     * @return bool|null
     */
    public function checkOwnerPermission(Authorizable $user, string $ability, $args = null): ?bool
    {
        if (method_exists($user, 'hasPermission')) {
            return $user->hasPermission($ability, $args);
        }

        // if $user not inherited trait HasPermissions
        $this->setOwner($user);
        $check = $this->hasPermission($ability, $args);
        if (!$check && $args) {
            $check = $this->checkMagicRuleSelf($user, $ability, $args);
        }

        return $check;
    }
}

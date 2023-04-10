<?php
namespace Wnikk\LaravelAccessRules\Traits;

use Illuminate\Database\Eloquent\Model;
use Wnikk\LaravelAccessRules\Contracts\AccessRules as AccessRulesContract;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;

trait HasPermissions
{
    /** @var AccessRulesContract */
    protected $accessRules;

    /** @var string */
    protected $ownerName;

    /**
     * @return AccessRulesContract
     */
    protected function appAccessRulesModel()
    {
        return app(AccessRulesContract::class);
    }

    /**
     * Initialize the trait
     *
     * @return void
     */
    protected function initializeHasPermissions()
    {
        $ar = $this->accessRules = $this->appAccessRulesModel();

        static::retrieved(function ($model) use ($ar) {
            $ar->setOwner($model);
        });
        static::created(function ($model) {
            $model->getOwner();
        });
        static::deleting(function ($model) {
            $owner = $model->getOwner();
            $owner->delete();
        });
    }

    /**
     * @return OwnerContract
     */
    public function getOwner()
    {
        $owner = $this->accessRules->getOwner();
        if ($owner) return $owner;

        return $this->accessRules->newOwner(
            $this,
            $this->getKey(),
            $this->ownerName??
            $this->name??
            $this->fullname??
            $this->realname??
            $this->login??
            $this->email??
            $this->phone??
            $this->getKey()??
            null
        );
    }

    /**
     * Get owner object from mixed type
     *
     * @param $type
     * @param $id
     * @return OwnerContract
     */
    private function getOwnerFrom($type, $id = null): OwnerContract
    {
        $owner = null;

        if (is_object($type)) {
            if ($type instanceof OwnerContract && $type->id) {
                $owner = $type;
            } elseif (method_exists($type, 'getOwner')) {
                $owner = $type->getOwner();
            }
        }

        if (!$owner) {
            $accessRules = $this->appAccessRulesModel();
            $accessRules->setOwner($type, $id);
            $owner = $accessRules->getOwner();
        }
        return $owner;
    }

    /**
     * Adds the user to inherit
     *
     * @param  int|Model|OwnerContract  $type
     * @param  null|int  $id
     */
    public function inheritPermissionFrom($type, $id = null): bool
    {
        $owner  = $this->getOwner();
        $parent = $this->getOwnerFrom($type, $id);

        $this->accessRules->refreshPermission();

        return $parent && $owner->addInheritance($parent);
    }

    /**
     * Remove inherit from parent owner
     *
     * @param  int|Model|OwnerContract  $type
     * @param  null|int  $id
     * @return bool
     */
    public function remInheritFrom($type, $id = null): bool
    {
        $owner  = $this->accessRules->getOwner();
        $parent = $this->getOwnerFrom($type, $id);

        $this->accessRules->refreshPermission();

        return $parent && $owner->remInheritance($parent);
    }


    /**
     * Add a permission to owner
     *
     * @param $ability
     * @param $option
     * @return bool
     */
    public function addPermission($ability, $option = null): bool
    {
        $this->getOwner();
        return $this->accessRules->addPermission($ability, $option);
    }

    /**
     * Add blocking resolution to owner
     *
     * @param $ability
     * @param $option
     * @return bool
     */
    public function addProhibition($ability, $option = null): bool
    {
        return $this->accessRules->addProhibition($ability, $option);
    }

    /**
     * Remove resolution from owner
     *
     * @param $ability
     * @param $option
     * @return bool
     */
    public function remPermission($ability, $option = null): bool
    {
        return $this->accessRules->remPermission($ability, $option);
    }

    /**
     * Remove blocking resolution from owner
     *
     * @param $ability
     * @param $option
     * @return bool
     */
    public function remProhibition($ability, $option = null): bool
    {
        return $this->accessRules->remProhibition($ability, $option);
    }

    /**
     * Determine if the model may perform the given permission.
     *
     * @param string  $ability
     * @param array|null  $args
     * @return bool|null
     */
    public function hasPermission($ability, $args = null): ?bool
    {
        $check = $this->accessRules->hasPermission($ability, $args);

        // Check magic permission {rule}.self
        if (!$check && $args){
            $check = $this->accessRules->checkMagicRuleSelf($this, $ability, $args);
        }

        return $check;
    }
}

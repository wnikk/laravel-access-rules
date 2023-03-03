<?php
namespace Wnikk\LaravelAccessRules\Traits;

use Illuminate\Database\Eloquent\Model;
use Wnikk\LaravelAccessRules\AccessRules;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;

trait HasPermissions
{
    /** @var AccessRules */
    protected $accessRules;

    /** @var string */
    protected $ownerName;

    /**
     * @return AccessRules
     */
    protected function appAccessRulesModel()
    {
        return app(AccessRules::class);
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
     * Adds the user to inherit
     *
     * @param  int|\Illuminate\Database\Eloquent\Model  $typeOrModel
     * @param  null|int  $id
     */
    public function inheritPermissionFrom($type, $id = null): bool
    {
        if (is_object($type) && method_exists($type, 'getOwner')) {
            $parent = $type->getOwner();
        } else {
            $parentAr = $this->appAccessRulesModel();
            $parentAr->setOwner($type, $id);
            $parent = $parentAr->getOwner();
        }
        $owner = $this->accessRules->getOwner();

        return $owner->addInheritance($parent);
    }

    /**
     * Determine if the model may perform the given permission.
     *
     */
    public function hasPermission($ability, $args = null): bool
    {
        return $this->accessRules->hasPermission($ability, $args = null);
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

}

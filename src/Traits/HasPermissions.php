<?php
namespace Wnikk\LaravelAccessRules\Traits;

use Illuminate\Database\Eloquent\Model;
use Wnikk\LaravelAccessRules\AccessRules;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;

trait HasPermissions
{
    /** @var AccessRules */
    protected $arClass;

    /** @var string */
    protected $ownerName;

    /**
     * @return AccessRules
     */
    protected function getAccessRulesModel()
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
        $ar = $this->arClass = $this->getAccessRulesModel();

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
        $owner = $this->arClass->getOwner();
        if ($owner) return $owner;

        return $this->arClass->newOwner(
            $this,
            $this->getKey(),
            $this->ownerName??
            $this->name??
            $this->fullname??
            $this->email??
            $this->realname??
            $this->login??
            $this->phone??
            null
        );
    }

    /**
     * Adds the user to inherit
     *
     * @param  int|\Illuminate\Database\Eloquent\Model  $typeOrModel
     * @param  null|int  $id
     */
    public function inheritPermissionFrom($typeOrModel, $id = null): bool
    {
        $parentAr = $this->getAccessRulesModel();
        $parentAr->setOwner($typeOrModel, $id);
        $parent   = $parentAr->getOwner();
        $owner    = $this->arClass->getOwner();

        return $owner->addInheritance($parent);
    }

    /**
     * Determine if the model may perform the given permission.
     *
     */
    public function hasPermission($ability, $args = null): bool
    {
        return $this->arClass->hasPermission($ability, $args = null);
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
        return $this->arClass->addPermission($ability, $option);
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
        return $this->arClass->addProhibition($ability, $option);
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
        return $this->arClass->remPermission($ability, $option);
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
        return $this->arClass->remProhibition($ability, $option);
    }

}

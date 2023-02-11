<?php
namespace Wnikk\LaravelAccessRules\Traits;

use Illuminate\Database\Eloquent\Model;
use Wnikk\LaravelAccessRules\AccessRules;

trait HasPermissions
{
    /** @var AccessRules */
    protected $arClass;

    /**
     * Initialize the trait
     *
     * @return void
     */
    protected function initializeHasPermissions()
    {
        $this->arClass = app(AccessRules::class);

        if ($this instanceof Model) {
            $this->arClass->setOwner($this);
        }
    }

    /**
     * Add a permission to owner
     *
     * @param $ability
     * @param $option
     * @param bool $access
     * @return bool
     */
    protected function addLinkToRule($ability, $option, $access): bool
    {
        $owner = $this->arClass->getOwner();
        $rule  = $this->arClass->getRuleModel($ability, $option);
        if (!$rule) {
            throw new \LogicException(
                'Rule "'.$ability.'" is absent in the database. Before adding a permission, add rule to DB.'
            );
        }
        return $owner->addPermission($rule, $option, $access);
    }

    /**
     * Add blocking resolution to owner
     *
     * @param $ability
     * @param $option
     * @param bool $access
     * @return bool
     */
    protected function remLinkToRule($ability, $option, $access): bool
    {
        $owner = $this->arClass->getOwner();
        $rule  = $this->arClass->getRuleModel($ability, $option);
        if (!$rule) return false;
        return $owner->remPermission($rule, $option, $access);
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
        return $this->addLinkToRule($ability, $option, true);
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
        return $this->addLinkToRule($ability, $option, false);
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
        return $this->remLinkToRule($ability, $option, true);
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
        return $this->remLinkToRule($ability, $option, false);
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
     * Adds the user to inherit
     *
     * @param  int|\Illuminate\Database\Eloquent\Model  $typeOrModel
     * @param  null|int|\Illuminate\Database\Eloquent\Model  $id
     */
    public function inheritPermissionFrom($typeOrModel, $id = null): bool
    {
        $parent = app(AccessRules::class)
            ->setOwner($typeOrModel, $id)
            ->getOwner();
        $owner = $this->arClass->getOwner();

        return $owner->addInheritance($parent);
    }
}

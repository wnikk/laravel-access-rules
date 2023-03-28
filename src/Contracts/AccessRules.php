<?php

namespace Wnikk\LaravelAccessRules\Contracts;


use Illuminate\Contracts\Auth\Access\Authorizable;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;

interface AccessRules
{
    /**
     * Set the owner id for user/groups support, this id is used when querying roles
     *
     * @param  int|\Illuminate\Database\Eloquent\Model  $type
     * @param  null|int|\Illuminate\Database\Eloquent\Model  $id
     */
    public function setOwner($type, $id = null);

    /**
     * @param $type
     * @param $id
     * @param $name
     * @return OwnerContract
     */
    public function newOwner($type, $id = null, $name = null);

    /**
     * @return null|OwnerContract
     */
    public function getOwner();

    /**
     * Create a rule
     *
     * @param mixed $guardName
     * @param string|null $title
     * @param string|null $description
     * @param int|null $parentRuleID
     * @param mixed $options
     * @return int|false
     */
    public static function newRule($guardName, string $title = null, string $description = null, int $parentRuleID = null, $options = null);

    /**
     * Soft remove rule
     *
     * @param string $guardName
     * @return mixed
     */
    public static function delRule(string $guardName);

    /**
     * Add a permission to owner
     *
     * @param $ability
     * @param $option
     * @return bool
     */
    public function addPermission($ability, $option = null): bool;

    /**
     * Add blocking resolution to owner
     *
     * @param $ability
     * @param $option
     * @return bool
     */
    public function addProhibition($ability, $option = null): bool;

    /**
     * Remove resolution from owner
     *
     * @param $ability
     * @param $option
     * @return bool
     */
    public function remPermission($ability, $option = null): bool;

    /**
     * Remove blocking resolution from owner
     *
     * @param $ability
     * @param $option
     * @return bool
     */
    public function remProhibition($ability, $option = null): bool;


    /**
     * Checks what is right for the current user
     *
     * @param $ability
     * @param $args
     * @return bool|null
     */
    public function hasPermission($ability, $args = null): ?bool;

    /**
     * Checks what is right for the authorizable user
     *
     * @param Authorizable $user
     * @param string $ability
     * @param $args
     * @return bool|null
     */
    public function checkOwnerPermission(Authorizable $user, string $ability, $args = null): ?bool;
}

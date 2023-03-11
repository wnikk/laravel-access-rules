<?php

namespace Wnikk\LaravelAccessRules\Contracts;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;
use Wnikk\LaravelAccessRules\Models\Permission;

interface Owner
{
    /**
     * We get specified user
     *
     * @param int $type
     * @param int $originalId
     * @return mixed
     */
    public static function findOwner(int $type, $originalId = null);

    /**
     * Add a permission to owner
     *
     * @param RuleContract $rule
     * @param $option
     * @return bool
     */
    public function addPermission(RuleContract $rule, $option = null): bool;

    /**
     * Add blocking resolution to owner
     *
     * @param RuleContract $rule
     * @param $option
     * @return bool
     */
    public function addProhibition(RuleContract $rule, $option = null): bool;

    /**
     * Remove resolution from owner
     *
     * @param RuleContract $rule
     * @param $option
     * @return bool
     */
    public function remPermission(RuleContract $rule, $option = null): bool;

    /**
     * Remove blocking resolution from owner
     *
     * @param RuleContract $rule
     * @param $option
     * @return bool
     */
    public function remProhibition(RuleContract $rule, $option = null): bool;


    /**
     * Adds the user to inherit
     * from specified user in parameter
     *
     * @param Owner $parent
     * @return bool
     */
    public function addInheritance(Owner $parent): bool;

    /**
     * Removes user from inheritance
     * from specified user in parameter
     *
     * @param Owner $parent
     * @return int
     */
    public function remInheritance(Owner $parent);

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function permission(): HasMany;

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function inheritance(): HasMany;

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function inheritanceParent(): HasMany;
}

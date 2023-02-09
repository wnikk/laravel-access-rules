<?php

namespace Wnikk\LaravelAccessRules\Contracts;

use Illuminate\Database\Eloquent\Relations\HasMany;

interface Owners
{
    /**
     * We get specified user
     *
     * @param int $type
     * @param int $originalId
     * @return mixed
     */
    public function findOwner(int $type, int $originalId = null);

    /**
     * Adds the user to inherit
     * from specified user in parameter
     *
     * @param Owners $parent
     * @return bool
     */
    public function AddInheritance(Owners $parent): bool;

    /**
     * Removes user from inheritance
     * from specified user in parameter
     *
     * @param Owners $parent
     * @return int
     */
    public function RemoveInheritance(Owners $parent);

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function linkage(): HasMany;

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function inheritance(): HasMany;

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function inheritanceParent(): HasMany;
}

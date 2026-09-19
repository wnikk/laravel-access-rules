<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;

/**
 * Record of an owner of permissions: a user, a role, a group or an id of an external system.
 *
 * Only created_at exists. Rows are written once and deleted, never updated, and version 2 created
 * the tables without the second timestamp.
 *
 * @property int         $id
 * @property int         $type
 * @property string|null $original_id
 * @property string|null $name
 * @property Carbon|null $created_at
 */
#[Fillable(['type', 'original_id', 'name'])]
class Owner extends Model implements OwnerContract
{
    const UPDATED_AT = null;

    public function getTable()
    {
        return config('access.table_names.owner', parent::getTable());
    }

    public function permission(): HasMany
    {
        return $this->hasMany(config('access.models.permission', Permission::class), 'owner_id');
    }

    public function inheritance(): HasMany
    {
        return $this->hasMany(config('access.models.inheritance', Inheritance::class), 'owner_id');
    }

    public function inheritanceParent(): HasMany
    {
        return $this->hasMany(config('access.models.inheritance', Inheritance::class), 'owner_parent_id');
    }
}

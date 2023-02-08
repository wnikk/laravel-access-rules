<?php

namespace Wnikk\LaravelAccessRules\Models;

use Wnikk\LaravelAccessRules\Contracts\Role as RoleContract;
use Illuminate\Database\Eloquent\Model;


/**
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property string $options
 * @property ?\Illuminate\Support\Carbon $deleted_at
 */
class Role extends Model implements RoleContract
{
    /**
     * @inherited
     */
    protected $guarded = [];

    /**
     * @inherited
     */
    public function getTable()
    {
        return config('access.table_names.role', parent::getTable());
    }
}

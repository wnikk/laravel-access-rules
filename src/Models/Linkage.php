<?php

namespace Wnikk\LaravelAccessRules\Models;

use Wnikk\LaravelAccessRules\Contracts\Role as RoleContract;
use Wnikk\LaravelAccessRules\Contracts\Owners as OwnersContract;
use Wnikk\LaravelAccessRules\Contracts\Linkage as LinkageContract;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $owner_id
 * @property int $role_id
 * @property bool $permission
 * @property string $option
 * @property ?\Illuminate\Support\Carbon $created_at
 */
class Linkage extends Model implements LinkageContract
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
        return config('access.table_names.linkage', parent::getTable());
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function rule()
    {
        return $this->belongsTo(RoleContract::class, 'role_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function owner()
    {
        return $this->belongsTo(OwnersContract::class, 'owner_id');
    }
}

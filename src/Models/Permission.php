<?php

namespace Wnikk\LaravelAccessRules\Models;

use Wnikk\LaravelAccessRules\Contracts\Permission as PermissionContract;
use Wnikk\LaravelAccessRules\Casts\PermissionOption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $owner_id
 * @property int $rule_id
 * @property bool $permission
 * @property string $option
 * @property ?\Illuminate\Support\Carbon $created_at
 */
class Permission extends Model implements PermissionContract
{
    const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'owner_id',
        'rule_id',
        'permission',
        'option',
        'created_at',
    ];

    /**
     * @inherited
     */
    protected $guarded = [];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'option' => PermissionOption::class,
    ];

    /**
     * @inherited
     */
    public function getTable()
    {
        return config('access.table_names.permission', parent::getTable());
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class, 'rule_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }
}

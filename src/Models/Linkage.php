<?php

namespace Wnikk\LaravelAccessRules\Models;

use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;
use Wnikk\LaravelAccessRules\Contracts\Owners as OwnersContract;
use Wnikk\LaravelAccessRules\Contracts\Linkage as LinkageContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'owner_id',
        'role_id',
        'permission',
        'option',
        'created_at',
    ];

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
    public function rule(): BelongsTo
    {
        return $this->belongsTo(RuleContract::class, 'rule_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(OwnersContract::class, 'owner_id');
    }
}

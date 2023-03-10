<?php

namespace Wnikk\LaravelAccessRules\Models;

use Wnikk\LaravelAccessRules\Contracts\Inheritance as InheritanceContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $owner_id
 * @property int $owner_parent_id
 * @property ?\Illuminate\Support\Carbon $created_at
 */
class Inheritance extends Model implements InheritanceContract
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
        'owner_parent_id',
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
        return config('access.table_names.inheritance', parent::getTable());
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function ownerParent(): BelongsTo
    {
        return $this->belongsTo(Owner::class, 'owner_parent_id');
    }
}

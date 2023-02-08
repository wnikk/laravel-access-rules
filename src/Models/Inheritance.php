<?php

namespace Wnikk\LaravelAccessRules\Models;

use Wnikk\LaravelAccessRules\Contracts\Owners as OwnersContract;
use Wnikk\LaravelAccessRules\Contracts\Inheritance as InheritanceContract;
use Illuminate\Database\Eloquent\Model;


/**
 * @property int $id
 * @property int $owner_id
 * @property int $owner_id_parent
 * @property ?\Illuminate\Support\Carbon $created_at
 */
class Inheritance extends Model implements InheritanceContract
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
        return config('access.table_names.inheritance', parent::getTable());
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function owner()
    {
        return $this->belongsTo(OwnersContract::class, 'owner_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function ownerParent()
    {
        return $this->belongsTo(OwnersContract::class, 'owner_id_parent');
    }
}

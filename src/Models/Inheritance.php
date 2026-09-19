<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Wnikk\LaravelAccessRules\Contracts\Inheritance as InheritanceContract;

/**
 * One link "this owner inherits from that one". An owner can have many parents.
 *
 * @property int         $id
 * @property int         $owner_id
 * @property int         $owner_parent_id
 * @property Carbon|null $created_at
 */
#[Fillable(['owner_id', 'owner_parent_id'])]
class Inheritance extends Model implements InheritanceContract
{
    const UPDATED_AT = null;

    public function getTable()
    {
        return config('access.table_names.inheritance', parent::getTable());
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(config('access.models.owner', Owner::class), 'owner_id');
    }

    public function ownerParent(): BelongsTo
    {
        return $this->belongsTo(config('access.models.owner', Owner::class), 'owner_parent_id');
    }
}

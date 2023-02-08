<?php

namespace Wnikk\LaravelAccessRules\Models;

use Wnikk\LaravelAccessRules\Contracts\Owners as OwnersContract;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $type
 * @property int $original_id
 * @property string $name
 * @property ?\Illuminate\Support\Carbon $created_at
 */
class Owners extends Model implements OwnersContract
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
        return config('access.table_names.owners', parent::getTable());
    }
}

<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Wnikk\LaravelAccessRules\Contracts\Permission as PermissionContract;

/**
 * One permission or prohibition of an owner for a rule, with an optional option and condition.
 *
 * The condition is a tree compiled by ConditionCompiler. The cast decodes it for admin screens;
 * the path of a check reads the column through the query builder and skips Eloquent.
 *
 * @property int         $id
 * @property int         $owner_id
 * @property int         $rule_id
 * @property bool        $permission
 * @property string|null $option
 * @property array|null  $condition
 * @property Carbon|null $created_at
 */
#[Fillable(['owner_id', 'rule_id', 'permission', 'option', 'condition'])]
class Permission extends Model implements PermissionContract
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'permission' => 'boolean',
            'condition'  => 'array',
        ];
    }

    public function getTable()
    {
        return config('access.table_names.permission', parent::getTable());
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(config('access.models.rule', Rule::class), 'rule_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(config('access.models.owner', Owner::class), 'owner_id');
    }
}

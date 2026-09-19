<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the package needs from a model of rules, see Contracts\Owner for why contracts exist.
 *
 * @property int         $id
 * @property int         $parent_id
 * @property string      $guard_name
 * @property string|null $options    validation rules of the dynamic option
 * @property string|null $resource   alias of the entity from config access.resources
 * @property array|null  $condition  condition valid for every holder of the rule
 */
interface Rule
{
    public function parent(): BelongsTo;

    public function children(): HasMany;

    public function permission(): HasMany;
}

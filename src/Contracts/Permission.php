<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the package needs from a model of permissions, see Contracts\Owner for why contracts exist.
 *
 * @property int         $id
 * @property int         $owner_id
 * @property int         $rule_id
 * @property bool        $permission true - allowed, false - prohibited
 * @property string|null $option
 * @property array|null  $condition  condition of this assignment, on top of the condition of the rule
 */
interface Permission
{
    public function rule(): BelongsTo;

    public function owner(): BelongsTo;
}

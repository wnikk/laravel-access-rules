<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the package needs from a model of inheritance links, see Contracts\Owner for why contracts exist.
 *
 * @property int $id
 * @property int $owner_id
 * @property int $owner_parent_id
 *
 * @mixin \Wnikk\LaravelAccessRules\Models\Inheritance The implementing class is an Eloquent model with these columns; the package calls its query, key and save methods through the contract. Analysis reads the default model for them.
 */
interface Inheritance
{
    public function owner(): BelongsTo;

    public function ownerParent(): BelongsTo;
}

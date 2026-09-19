<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Contracts;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the package needs from a model of owners. config access.models can name another class,
 * for example one on a separate connection, as long as it keeps these relations and columns.
 *
 * The relations are all the contract asks for. Version 2 also put writing methods here, and
 * every replacement had to copy them; Administration\Owners does the writing now.
 *
 * @property int         $id
 * @property int         $type
 * @property string|null $original_id
 * @property string|null $name
 */
interface Owner
{
    public function permission(): HasMany;

    /** Links to owners this one inherits from */
    public function inheritance(): HasMany;

    /** Links to owners that inherit from this one */
    public function inheritanceParent(): HasMany;
}

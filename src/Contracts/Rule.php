<?php

namespace Wnikk\LaravelAccessRules\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

interface Rule
{
    /**
     * Find a rule by name
     *
     * @param string $ability
     * @param $option
     * @return Rule|null
     */
    public static function findRule(string $ability, &$option = null);

    /**
     * @return BelongsTo
     */
    public function parent(): BelongsTo;

    /**
     * @return HasMany
     */
    public function children(): HasMany;

    /**
     * @return HasMany
     */
    public function permission(): HasMany;
}

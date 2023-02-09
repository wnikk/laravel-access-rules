<?php

namespace Wnikk\LaravelAccessRules\Contracts;

use Illuminate\Database\Eloquent\Relations\HasMany;

interface Rule
{
    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function linkage(): HasMany;
}

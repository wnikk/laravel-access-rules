<?php

namespace Tests\Fixtures\Shop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Wnikk\LaravelAccessRules\Traits\HasAccessScope;

class Client extends Model
{
    use HasAccessScope;

    protected $table = 'shop_clients';

    protected $guarded = [];

    public $timestamps = false;

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'client_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'client_id');
    }

    /**
     * The table relates to itself. Eloquent aliases it inside the subquery, and a column that is
     * qualified with the real table name would compare the row with itself.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(Item::class, Order::class, 'client_id', 'order_id');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}

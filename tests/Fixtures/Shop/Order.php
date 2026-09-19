<?php

namespace Tests\Fixtures\Shop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Wnikk\LaravelAccessRules\Traits\HasAccessScope;

/**
 * The record most scenarios are about. Its relations declare return types, because that is
 * how conditions tell a relation from any other method.
 */
class Order extends Model
{
    use HasAccessScope;

    protected $table = 'shop_orders';

    protected $guarded = [];

    public $timestamps = false;

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'order_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'shop_order_product', 'order_id', 'product_id');
    }

    /**
     * The morph map stores "order", not the class name. A subquery that compares with the class
     * name finds nothing.
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * latestOfMany() makes Eloquent build a join inside the subquery, the hardest shape a to-one
     * relation can take.
     */
    public function lastComment(): MorphOne
    {
        return $this->morphOne(Comment::class, 'commentable')->latestOfMany();
    }

    /**
     * No return type on purpose. Conditions must refuse it until config lists it as a relation.
     */
    public function untyped()
    {
        return $this->hasMany(Item::class, 'order_id');
    }

    /**
     * Stands for save(), delete() and every other method with an effect. It throws when called,
     * so a condition that reaches it fails the test loudly instead of doing damage quietly.
     */
    public function dangerous(): bool
    {
        throw new \LogicException('A condition has called a method that is not a relation');
    }
}

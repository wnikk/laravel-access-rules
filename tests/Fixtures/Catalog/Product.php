<?php

namespace Tests\Fixtures\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Wnikk\LaravelAccessRules\Traits\HasAccessScope;

/**
 * A product of a catalogue where everything around it is polymorphic: comments and tags
 * belong to products and to articles alike, and likes belong to comments and to products.
 */
class Product extends Model
{
    use HasAccessScope;

    protected $table = 'catalog_products';

    protected $guarded = [];

    public $timestamps = false;

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * A relation with a constraint of its own. The constraint has to reach the subquery of a list
     * filter, or the list counts hidden comments that a check of a loaded product does not see.
     */
    public function visibleComments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')->where('hidden', false);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable', 'catalog_taggables');
    }

    /**
     * The same tags, as models that carry the tree relations of staudenmeir/laravel-adjacency-list.
     */
    public function recursiveTags(): MorphToMany
    {
        return $this->morphToMany(RecursiveTag::class, 'taggable', 'catalog_taggables', 'taggable_id', 'tag_id');
    }

    /**
     * Likes of all comments of the product. Eloquent has no polymorphic "through", so both
     * morph types are spelled out; without them likes of articles with the same ids join in.
     */
    public function commentLikes(): HasManyThrough
    {
        return $this->hasManyThrough(Like::class, Comment::class, 'commentable_id', 'likeable_id')
            ->where('catalog_comments.commentable_type', 'product')
            ->where('catalog_likes.likeable_type', 'comment');
    }
}

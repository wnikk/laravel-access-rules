<?php

namespace Tests\Fixtures\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Wnikk\LaravelAccessRules\Traits\HasAccessScope;

/**
 * Tags form a tree through parent_id, the way categories usually do.
 */
class Tag extends Model
{
    use HasAccessScope;

    protected $table = 'catalog_tags';

    protected $guarded = [];

    public $timestamps = false;

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Every tag above this one, through a closure table: one row per pair "tag, any of its ancestors".
     * The application keeps the table up to date when the tree changes. In return "somewhere below X"
     * becomes an ordinary relation, with no recursion at query time.
     */
    public function ancestors(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'catalog_tag_closure', 'tag_id', 'ancestor_id');
    }
}

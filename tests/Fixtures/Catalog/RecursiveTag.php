<?php

namespace Tests\Fixtures\Catalog;

use Illuminate\Database\Eloquent\Model;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships;

/**
 * The same tags table, with the tree relations of staudenmeir/laravel-adjacency-list:
 * ancestors(), descendants(), bloodline() and their "AndSelf" variants.
 *
 * Those relations build their own WITH RECURSIVE inside the subquery that has() asks for, so
 * a condition can use them like any other relation. Their methods declare no return types, which
 * is why the test lists them in config access.resources.
 */
class RecursiveTag extends Model
{
    use HasRecursiveRelationships;

    protected $table = 'catalog_tags';

    protected $guarded = [];

    public $timestamps = false;
}

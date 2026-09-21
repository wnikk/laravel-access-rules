<?php

namespace Tests\Fixtures\Catalog;

use Illuminate\Support\Facades\DB;
use Wnikk\LaravelAccessRules\Conditions\Context;

/**
 * Function of the application for conditions: ids of all tags below the named one, at any depth.
 *
 *     exists(product.tags, id in tagsUnder('акция'))
 *
 * The language of conditions has no recursion, and a tree of unknown depth needs one. A function
 * fills the gap: its argument does not depend on the record, so a list filter computes it once,
 * before the query, and the database sees a plain "id in (...)".
 *
 * The walk is a query per level. The package has the same thing built in and faster, below() and
 * belowOrSelf(); this class shows what a project writes when it needs a function of its own.
 *
 * Results are remembered in the object. Functions come from the container, so the application
 * registers this class as scoped, and a page that checks fifty products one by one walks the
 * tree once, not fifty times. A static property would do the same and then serve a stale tree
 * to the next request of an Octane worker.
 */
class TagsUnder
{
    /** @var array<string, list<int>> */
    private array $memo = [];

    public int $walks = 0;

    public function __invoke(array $args, Context $context): array
    {
        $name = (string) $args[0];

        return $this->memo[$name] ??= $this->walk($name);
    }

    private function walk(string $name): array
    {
        $this->walks++;

        $found = [];
        $level = DB::table('catalog_tags')->where('name', $name)->pluck('id')->all();

        while ($level !== []) {
            $level = DB::table('catalog_tags')->whereIn('parent_id', $level)->whereNotIn('id', $found)->pluck('id')->all();
            $found = [...$found, ...$level];
        }

        return array_map('intval', $found);
    }
}

<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Internal\Conditions\Evaluation;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Wnikk\LaravelAccessRules\Exceptions\InvalidConditionException;
use Wnikk\LaravelAccessRules\Internal\Conditions\ResourceRegistry;
use Wnikk\LaravelAccessRules\Internal\Storage\HierarchyQuery;

/**
 * Built-in functions for trees: ids of everything below or above a node of a model that points at itself.
 *
 *     exists(product.tags, id in belowOrSelf('tag.name', 'sale'))     the tag "sale" and every tag under it
 *     category.id in aboveOrSelf('category.id', user.category_id)     the category of the user and all its parents
 *
 * "This category and everything under it" is what every catalogue asks for, and the language of
 * conditions has no recursion: each construct must become one SQL fragment and one walk over
 * a loaded record. A recursive operator inside the language would need WITH RECURSIVE inside
 * a subquery, which SQL Server and MySQL 5.7 refuse. A function that returns a list of ids works
 * on every server: its arguments do not depend on the record, so a list filter computes it before
 * the query and the database gets a plain "id in (...)".
 *
 * The walk goes through Storage\HierarchyQuery, the class that collects inherited owners, so it
 * is one recursive query where the server has one. Evaluator calls this class of the conditions
 * layer, Normalizer asks it to check arguments when a condition is saved.
 *
 * Results are remembered in the object, and the provider registers it per request. A page that
 * checks fifty products one by one walks the tree once. A static memory would do the same and
 * then serve yesterday's tree to the next request of an Octane worker.
 *
 * @internal Not part of the public API, it may change in any release. AGENTS.md lists what an application may rely on.
 */
final class TreeFunctions
{
    public const NAMES = ['below', 'belowOrSelf', 'above', 'aboveOrSelf'];

    /** @var array<string, list<int|string>> */
    private array $memo = [];

    public function __construct(
        private ResourceRegistry $resources,
        private HierarchyQuery $hierarchy,
    ) {}

    /**
     * @param  list<mixed>      $args "alias.column" of the node to start from, the value of that column (or a list of values), and optionally the name of the relation to the parent, "parent" by default.
     * @return list<int|string> Keys of the model.
     *
     * @throws InvalidConditionException See check().
     */
    public function ids(string $function, array $args): array
    {
        $key = $function.'|'.json_encode($args);

        return $this->memo[$key] ??= $this->walk($function, $args);
    }

    /**
     * Checks what can be checked without data: the alias is a listed model, the column is a plain
     * name, the relation leads from the model to itself. Normalizer calls it when a condition is
     * saved, so "below('tags.name', ...)" with a typo fails there and not as a refusal at night.
     *
     * @return array{0:class-string, 1:string, 2:BelongsTo} Model, column, relation to the parent.
     *
     * @throws InvalidConditionException
     */
    public function check(string $function, mixed $target, mixed $relation = 'parent'): array
    {
        if (! is_string($target) || ! is_string($relation) || ! preg_match('/^([A-Za-z_]\w*)\.([A-Za-z_]\w*)$/', $target, $m)) {
            throw new InvalidConditionException($function."() expects 'alias.column', a value and optionally the name of the relation to the parent");
        }

        $model = $this->resources->model($m[1]);
        if ($model === null) {
            throw new InvalidConditionException($function.'(): "'.$m[1].'" is not listed in config access.resources');
        }

        $parent = $this->resources->relation($model, $relation);
        if (! $parent instanceof BelongsTo || $parent->getRelated()::class !== $model) {
            throw new InvalidConditionException($function.'(): '.$model.'::'.$relation.'() has to be a belongsTo relation of the model to itself');
        }

        return [$model, $m[2], $parent];
    }

    private function walk(string $function, array $args): array
    {
        [$model, $column, $parent] = $this->check($function, $args[0] ?? null, $args[2] ?? 'parent');

        $node       = new $model;
        $db         = $node->getConnection();
        $softDelete = method_exists($node, 'getDeletedAtColumn') ? $node->getDeletedAtColumn() : null;

        // The model answers "which nodes", so its soft deletes and global scopes apply to the start.
        $start = $model::query()->whereIn($column, (array) $args[1])->pluck($node->getKeyName())->all();

        [$from, $to] = str_starts_with($function, 'below')
            ? [$parent->getForeignKeyName(), $node->getKeyName()]
            : [$node->getKeyName(), $parent->getForeignKeyName()];

        $found = $this->hierarchy->reachable($db, $node->getTable(), $from, $to, $start, $softDelete);

        return array_values(array_unique(str_ends_with($function, 'OrSelf') ? [...$start, ...$found] : $found));
    }
}

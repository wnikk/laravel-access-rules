<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Conditions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Wnikk\LaravelAccessRules\Conditions\Evaluation\TreeFunctions;
use Wnikk\LaravelAccessRules\Exceptions\InvalidConditionException;

/**
 * Resolves the names of a raw syntax tree against real models and produces the stored tree.
 *
 * The parser sees "order.client.city" as three words. This class finds out that "client" is
 * a relation to one record and "city" a column of it, and that "order.items" leads to many
 * records and therefore needs an aggregate. It belongs to the conditions layer, and
 * ConditionCompiler is its only caller.
 *
 * Resolution happens once, when a condition is saved. The evaluator and the SQL compiler get
 * a tree where every path is already split into relations and a column, so neither of them
 * touches reflection during a check.
 *
 * Stored nodes:
 *   ['val', v], ['attr', 'subject'|'env'|'action', path], ['call', name, args], ['is-author']
 *   ['res', chain[], column]                               column of the record, through to-one relations
 *   ['agg', fn, chain[], many, column|null, filter|null]   count, sum, min, max, exists over a to-many relation
 *   ['and', a, b], ['or', a, b], ['not', x], ['cmp', op, l, r], ['in', l, r, negated]
 */
final class Normalizer
{
    public const AGGREGATES = ['count', 'sum', 'min', 'max', 'exists'];

    private const SUBJECT_ROOTS = ['user', 'subject'];

    /** Time functions of Evaluator::call(). Tree functions live in TreeFunctions::NAMES. */
    private const FUNCTIONS = ['now', 'today', 'ago', 'after', 'monthStart', 'yearStart'];

    /**
     * @param class-string<Model>|null $model    Model behind the record. Null when the rule names no resource; then any path to the record is a mistake.
     * @param list<string>             $roots    Words that mean "the record": "resource" and the alias of the model.
     * @param bool                     $rowScope True inside the filter of an aggregate, where a bare name is a column of the related model.
     */
    public function __construct(
        private ResourceRegistry $resources,
        private ?string $model,
        private array $roots = ['resource'],
        private bool $rowScope = false,
    ) {}

    /**
     * Normalizes a node that stands where a boolean is expected. A bare attribute such as
     * "!order.locked" becomes "order.locked == true": SQL has no truthiness, and the evaluator
     * must not invent one that the query cannot repeat.
     */
    public function bool(array $node): array
    {
        $node = $this->node($node);

        return in_array($node[0], ['res', 'attr'], true) ? ['cmp', '==', $node, ['val', true]] : $node;
    }

    public function node(array $node): array
    {
        return match ($node[0]) {
            'val'       => $node,
            'and', 'or' => [$node[0], $this->bool($node[1]), $this->bool($node[2])],
            'not'       => ['not', $this->bool($node[1])],
            'cmp'       => ['cmp', $node[1], $this->node($node[2]), $this->node($node[3])],
            'in'        => ['in', $this->node($node[1]), $this->node($node[2]), (bool) $node[3]],
            'path'      => $this->path($node[1]),
            'call'      => $this->call($node[1], $node[2]),
            default     => throw new InvalidConditionException('Unknown node "'.$node[0].'"'),
        };
    }

    private function call(string $name, array $args): array
    {
        if (in_array($name, self::AGGREGATES, true)) {
            return $this->aggregate($name, $args);
        }

        if ($name === 'isAuthor') {
            if ($args !== []) {
                throw new InvalidConditionException('isAuthor() takes no arguments');
            }

            return ['is-author'];
        }

        $args = array_map($this->node(...), $args);

        if (in_array($name, TreeFunctions::NAMES, true)) {
            if (count($args) < 2 || count($args) > 3) {
                throw new InvalidConditionException($name."() expects 'alias.column', a value and optionally the name of the relation to the parent");
            }

            // The model and the relation are literals and can be checked now. The value may come from the user.
            $literal = static fn (array $arg) => $arg[0] === 'val' ? $arg[1] : null;
            app(TreeFunctions::class)->check($name, $literal($args[0]), isset($args[2]) ? $literal($args[2]) : 'parent');
        } elseif (! in_array($name, self::FUNCTIONS, true) && ! isset((config('access.functions') ?? [])[$name])) {
            // A misspelled function would otherwise surface during a check, as a refusal with a line in the log.
            throw new InvalidConditionException('Unknown function '.$name.'(). Built-in: '.implode(', ', [...self::FUNCTIONS, ...TreeFunctions::NAMES]).'; own functions go to config access.functions');
        }

        return ['call', $name, $args];
    }

    /**
     * Inside a filter a single word is a column even if it reads "user" or "env". Only a dotted
     * path can reach the subject from there, so a column named "user" stays usable.
     */
    private function path(string $path): array
    {
        $segments = explode('.', $path);
        $root     = $segments[0];

        if (! $this->rowScope || count($segments) > 1) {
            if (in_array($root, self::SUBJECT_ROOTS, true)) {
                return ['attr', 'subject', $this->rest($segments, $path)];
            }
            if ($root === 'env' || $root === 'action') {
                return ['attr', $root, $this->rest($segments, $path)];
            }
        }

        $segments = $this->stripRoot($segments, $path);
        $resolved = $this->resolve($segments, $path);

        if ($resolved['many'] === null) {
            return ['res', $resolved['chain'], $resolved['col']];
        }
        // "order.items.count" reads naturally and means count(order.items). Only "count" gets the
        // shortcut: the other aggregates need a column, and "order.items.price.sum" helps nobody.
        if ($resolved['col'] === 'count') {
            return ['agg', 'count', $resolved['chain'], $resolved['many'], null, null];
        }

        throw new InvalidConditionException('"'.$path.'" goes through a to-many relation, use count(), sum(), min(), max() or exists()');
    }

    private function aggregate(string $fn, array $args): array
    {
        if (($args[0][0] ?? null) !== 'path' || count($args) > 2) {
            throw new InvalidConditionException($fn.'() expects a path to a relation and an optional filter');
        }

        $path     = $args[0][1];
        $resolved = $this->resolve($this->stripRoot(explode('.', $path), $path), $path);

        if ($resolved['many'] === null) {
            throw new InvalidConditionException($fn.'('.$path.'): "'.$path.'" is not a to-many relation');
        }
        if (in_array($fn, ['sum', 'min', 'max'], true) && $resolved['col'] === null) {
            throw new InvalidConditionException($fn.'() needs a column: '.$fn.'('.$path.'.column)');
        }
        if (in_array($fn, ['count', 'exists'], true) && $resolved['col'] !== null) {
            throw new InvalidConditionException($fn.'() takes a relation, not a column: '.$path);
        }

        $filter = isset($args[1])
            ? (new self($this->resources, $resolved['model'], [], true))->bool($args[1])
            : null;

        return ['agg', $fn, $resolved['chain'], $resolved['many'], $resolved['col'], $filter];
    }

    private function rest(array $segments, string $path): string
    {
        if (count($segments) < 2) {
            throw new InvalidConditionException('"'.$path.'" needs an attribute name');
        }

        return implode('.', array_slice($segments, 1));
    }

    private function stripRoot(array $segments, string $path): array
    {
        if (in_array($segments[0], $this->roots, true)) {
            array_shift($segments);
        } elseif (! $this->rowScope) {
            throw new InvalidConditionException('Unknown root "'.$segments[0].'" in "'.$path.'"');
        }

        if ($segments === []) {
            throw new InvalidConditionException('"'.$path.'" needs an attribute name');
        }

        return $segments;
    }

    /**
     * Walks the path over relations. A relation to one record extends the chain; a relation to
     * many ends it, with at most one column after it, because rows of a to-many relation have no
     * single value to compare.
     *
     * morphTo is refused. Its far end is a different model for every row, so no single subquery
     * describes it and no model can be checked against the whitelist.
     *
     * @return array{chain:list<string>, many:?string, col:?string, model:class-string<Model>}
     *
     * @throws InvalidConditionException
     */
    private function resolve(array $segments, string $path): array
    {
        if ($this->model === null) {
            throw new InvalidConditionException('"'.$path.'": the rule has no resource, set it to one of config access.resources');
        }

        $model = $this->model;
        $chain = [];
        $last  = count($segments) - 1;

        foreach ($segments as $i => $segment) {
            $relation = $this->resources->relation($model, $segment);

            if ($relation === null) {
                if ($i !== $last) {
                    throw new InvalidConditionException(
                        '"'.$segment.'" in "'.$path.'" is not a relation of '.$model
                        .' (a relation method has to declare its return type or be listed in config access.resources)'
                    );
                }

                return ['chain' => $chain, 'many' => null, 'col' => $segment, 'model' => $model];
            }

            if ($relation instanceof MorphTo) {
                throw new InvalidConditionException('"'.$path.'": a morphTo relation leads to models of different types and cannot be used in a condition');
            }

            $related = $relation->getRelated()::class;
            if (! $this->resources->allows($related)) {
                throw new InvalidConditionException('Model '.$related.' reached by "'.$path.'" is not listed in config access.resources');
            }

            if (! ResourceRegistry::isToOne($relation)) {
                $rest = array_slice($segments, $i + 1);
                if (count($rest) > 1) {
                    throw new InvalidConditionException('"'.$path.'": a path cannot continue after a to-many relation');
                }

                return ['chain' => $chain, 'many' => $segment, 'col' => $rest[0] ?? null, 'model' => $related];
            }

            $chain[] = $segment;
            $model   = $related;
        }

        throw new InvalidConditionException('"'.$path.'" has to end with a column or a to-many relation');
    }
}

<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Internal\Conditions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Wnikk\LaravelAccessRules\Exceptions\InvalidConditionException;
use Wnikk\LaravelAccessRules\Internal\Conditions\Evaluation\Evaluator;
use Wnikk\LaravelAccessRules\Internal\Conditions\Evaluation\TreeFunctions;

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
 *   ['pivot', column]                                      column of the pivot table, inside the filter of an aggregate
 *   ['math', op, l, r]                                     + - * /
 *   ['str', fn, subject, needle|null]                      startsWith, endsWith, contains give a boolean; lower gives text
 *   ['and', a, b], ['or', a, b], ['not', x], ['cmp', op, l, r, type], ['in', l, r, negated, type]
 *
 * "type" is null for most comparisons. 'num' or 'text' marks the ones where PHP and the database
 * would compare differently, see compared().
 *
 * @internal Not part of the public API, it may change in any release. AGENTS.md lists what an application may rely on.
 */
final class Normalizer
{
    public const AGGREGATES = ['count', 'sum', 'min', 'max', 'exists'];

    private const SUBJECT_ROOTS = ['user', 'subject'];

    public const STRINGS = ['startsWith', 'endsWith', 'contains', 'lower'];

    /** Time functions of Evaluator::call(). Tree functions live in TreeFunctions::NAMES. */
    private const FUNCTIONS = ['now', 'today', 'ago', 'after', 'monthStart', 'yearStart'];

    /**
     * @param class-string<Model>|null $model    Model behind the record. Null when the rule names no resource; then any path to the record is a mistake.
     * @param list<string>             $roots    Words that mean "the record": "resource" and the alias of the model.
     * @param bool                     $rowScope True inside the filter of an aggregate, where a bare name is a column of the related model.
     * @param Relation|null            $via      The relation whose rows the filter looks at. It decides whether "pivot.column" means anything.
     */
    public function __construct(
        private ResourceRegistry $resources,
        private ?string $model,
        private array $roots = ['resource'],
        private bool $rowScope = false,
        private ?Relation $via = null,
    ) {}

    /**
     * Normalizes a node that stands where a boolean is expected. A bare attribute such as
     * "!order.locked" becomes "order.locked == true": SQL has no truthiness, and the evaluator
     * must not invent one that the query cannot repeat.
     */
    public function bool(array $node): array
    {
        $node = $this->node($node);

        return in_array($node[0], ['res', 'attr'], true) ? $this->compared(['cmp', '==', $node, ['val', true]], 2, 3) : $node;
    }

    /**
     * Marks the comparisons where the two readers can disagree, with the type of the column
     * that takes part: 'text' or 'num'. Everything else stays unmarked and costs a check nothing.
     *
     * The disagreement is about text that looks like a number. For a varchar the database says
     * '01234' <> '1234' and '10' < '9'; PHP compares two such strings as numbers and says the
     * opposite, so a record is absent from the list and opens by URL. PHP cannot fix it by
     * looking at the value: a decimal arrives as a string too, and that one has to stay a number.
     *
     * Marking every comparison of a column was the first version. It cost 0.2 microseconds per
     * comparison, and most of them cannot go wrong: PHP compares 'new' with 'draft' as text, and
     * a number with a number as numbers. What remains:
     *   a text column against anything that may be a number: a numeric literal, a value that
     *   arrives during the check, another column;
     *   a number column against a value that arrives during the check and may be no number at
     *   all. PostgreSQL fails the whole query on such a parameter.
     * A literal next to a number column needs no mark: '100' becomes 100 right here.
     *
     * A number column against a text column is refused. PostgreSQL has no such operator, MySQL
     * converts the text, SQLite ranks every number below every text: three answers to one condition.
     */
    private function compared(array $node, int $left, int $right): array
    {
        $a = $this->typeOf($node[$left]);
        $b = $this->typeOf($node[$right]);

        if ($a !== null && $b !== null && $a !== $b) {
            throw new InvalidConditionException('A comparison mixes a number column with a text column, and databases disagree on what that means. Compare columns of one kind.');
        }

        $type  = $a ?? $b;
        $at    = $a === null ? $left : $right;
        $other = $node[$at];

        if ($type === null || $other === ['val', null]) {
            $node[] = null;

            return $node;
        }

        if ($other[0] !== 'val') {
            // Two number columns compare as numbers in both readers. The rest is known only during a check.
            $node[] = $type === 'num' && $a !== null && $b !== null ? null : $type;

            return $node;
        }

        $literals = is_array($other[1]) ? $other[1] : [$other[1]];
        $numeric  = array_filter($literals, static fn ($literal) => ! is_string($literal) || is_numeric($literal));

        if ($type === 'text') {
            $node[] = $numeric === [] ? null : 'text';

            return $node;
        }

        if (count($numeric) !== count($literals)) {
            throw new InvalidConditionException("'".implode("', '", array_diff_key($literals, $numeric))."' is compared with a number column and is not a number");
        }

        $literals  = array_map(static fn ($literal) => is_string($literal) ? Evaluator::typed($literal, 'num') : $literal, $literals);
        $node[$at] = ['val', is_array($other[1]) ? $literals : $literals[0]];
        $node[]    = null;

        return $node;
    }

    /**
     * Eloquent loads of a pivot row only the keys and what the relation asks for with withPivot().
     * A column that exists in the table and is missing there reads as NULL for a loaded record
     * and as its value in a list, so it is refused by name.
     */
    private function pivotColumn(?Relation $relation, string $column, string $path): string
    {
        if (! $relation instanceof BelongsToMany) {
            throw new InvalidConditionException('"'.$path.'": only a many-to-many relation has a pivot table');
        }

        $loaded = [$relation->getForeignPivotKeyName(), $relation->getRelatedPivotKeyName(), ...$relation->getPivotColumns()];
        if (! in_array($column, $loaded, true)) {
            throw new InvalidConditionException('"'.$path.'": add ->withPivot(\''.$column.'\') to the relation, otherwise a loaded record does not have the column');
        }

        return $column;
    }

    /**
     * @return 'num'|'text'|null Null for everything whose type nobody knows before a check: values, attributes of the user, functions.
     */
    private function typeOf(array $node): ?string
    {
        return match ($node[0]) {
            'res'   => $this->resources->columnType($this->modelAt($node[1]), $node[2]),
            'pivot' => $this->resources->pivotColumnType($this->via, $node[1]),
            'math'  => 'num',
            'str'   => $node[1] === 'lower' ? 'text' : null,
            'agg'   => match (true) {
                $node[1] === 'exists'                      => null,
                in_array($node[1], ['count', 'sum'], true) => 'num',
                str_starts_with($node[4], 'pivot.')        => $this->resources->pivotColumnType($this->resources->relation($this->modelAt($node[2]), $node[3]), substr($node[4], 6)),
                default                                    => $this->resources->columnType($this->modelAt([...$node[2], $node[3]]), $node[4]),
            },
            default => null,
        };
    }

    private function modelAt(array $chain): string
    {
        $model = $this->model;
        foreach ($chain as $name) {
            $model = $this->resources->relation($model, $name)->getRelated()::class;
        }

        return $model;
    }

    public function node(array $node): array
    {
        return match ($node[0]) {
            'val'       => $node,
            'and', 'or' => [$node[0], $this->bool($node[1]), $this->bool($node[2])],
            'not'       => ['not', $this->bool($node[1])],
            'cmp'       => $this->compared(['cmp', $node[1], $this->node($node[2]), $this->node($node[3])], 2, 3),
            'in'        => $this->compared(['in', $this->node($node[1]), $this->node($node[2]), (bool) $node[3]], 1, 2),
            'math'      => $this->math($node[1], $this->node($node[2]), $this->node($node[3])),
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

        if (in_array($name, self::STRINGS, true)) {
            return $this->string($name, $args);
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
     * Arithmetic takes numbers and nothing else. 'abc' * 2 is 0 on MySQL, an error on PostgreSQL
     * and a TypeError in PHP, so text is refused here, where the author of the condition reads it.
     * A value that arrives during a check and is no number makes the result unknown.
     */
    private function math(string $op, array $left, array $right): array
    {
        foreach ([&$left, &$right] as &$operand) {
            if ($operand[0] === 'val' && is_string($operand[1]) && is_numeric($operand[1])) {
                $operand = ['val', Evaluator::typed($operand[1], 'num')];
            }

            $number = match ($operand[0]) {
                'val'   => is_int($operand[1]) || is_float($operand[1]),
                'agg'   => $operand[1] !== 'exists' && $this->typeOf($operand) !== 'text',
                'str'   => false,
                default => in_array($operand[0], ['res', 'pivot', 'attr', 'call', 'math'], true) && $this->typeOf($operand) !== 'text',
            };

            if (! $number) {
                throw new InvalidConditionException('"'.$op.'" works with numbers: number columns, count(), sum(), attributes of the user and number literals');
            }
        }
        unset($operand);

        return ['math', $op, $left, $right];
    }

    /**
     * startsWith(), endsWith() and contains() compare exactly, letter case included, in both
     * readers. LIKE would have been one line of SQL and three meanings: SQLite ignores case
     * of ASCII, MySQL follows the collation, PostgreSQL is exact. lower() is there for the
     * comparisons that must ignore case: lower(order.email) == lower(user.email).
     *
     * The second argument must be known before the query is built, because a list needs its
     * length: "ends with" is written as "the last N characters equal".
     */
    private function string(string $name, array $args): array
    {
        if (count($args) !== ($name === 'lower' ? 1 : 2)) {
            throw new InvalidConditionException($name === 'lower' ? 'lower() takes one argument' : $name.'() takes what to look in and what to look for');
        }

        $subject = $this->node($args[0]);
        $needle  = isset($args[1]) ? $this->node($args[1]) : null;

        if (! in_array($subject[0], ['res', 'pivot', 'attr', 'call', 'val', 'str'], true) || ($subject[0] === 'str' && $subject[1] !== 'lower') || $this->typeOf($subject) === 'num') {
            throw new InvalidConditionException($name.'() works with text: a text column, an attribute of the user, lower(...)');
        }
        if ($needle !== null && ConditionCompiler::needsRecord($needle)) {
            throw new InvalidConditionException($name.'(): the second argument has to be a value, not a column of the record');
        }

        return ['str', $name, $subject, $needle];
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

        if ($this->rowScope && $root === 'pivot' && count($segments) === 2) {
            return ['pivot', $this->pivotColumn($this->via, $segments[1], $path)];
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
            ? (new self($this->resources, $resolved['model'], [], true, $resolved['relation']))->bool($args[1])
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
     * @return array{chain:list<string>, many:?string, col:?string, model:class-string<Model>, relation:?Relation}
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

                return ['chain' => $chain, 'many' => null, 'col' => $segment, 'model' => $model, 'relation' => null];
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

                // sum(order.products.pivot.quantity): the one path that goes on after a to-many relation.
                if (count($rest) === 2 && $rest[0] === 'pivot') {
                    $rest = ['pivot.'.$this->pivotColumn($relation, $rest[1], $path)];
                }
                if (count($rest) > 1) {
                    throw new InvalidConditionException('"'.$path.'": a path cannot continue after a to-many relation');
                }

                return ['chain' => $chain, 'many' => $segment, 'col' => $rest[0] ?? null, 'model' => $related, 'relation' => $relation];
            }

            $chain[] = $segment;
            $model   = $related;
        }

        throw new InvalidConditionException('"'.$path.'" has to end with a column or a to-many relation');
    }
}

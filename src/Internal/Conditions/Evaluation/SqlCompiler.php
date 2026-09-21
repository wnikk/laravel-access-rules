<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Internal\Conditions\Evaluation;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression;
use Wnikk\LaravelAccessRules\Conditions\Context;
use Wnikk\LaravelAccessRules\Exceptions\UntranslatableConditionException;
use Wnikk\LaravelAccessRules\Internal\Conditions\ConditionCompiler;

/**
 * Turns a condition into a fragment of WHERE, so a list is filtered by the database.
 *
 * It is the second reader of the condition tree, next to Evaluator, and the two must never
 * disagree: a list that shows a record the detail page refuses is the failure this whole
 * layer exists to prevent. The test suite runs every scenario through both and compares.
 * DecisionPoint::constrain() is the caller.
 *
 * Three choices keep the two readers in step:
 *   A relation to one record becomes a scalar subquery, not EXISTS. For a record without
 *   the related row the subquery gives NULL, "unknown", exactly what the evaluator sees.
 *   whereHas() gives false there, and its negation gives true.
 *   Aggregates are built by the relation objects themselves, the way has() and withCount()
 *   do it. Keys, pivot tables, morph types, soft deletes and global scopes of related models
 *   come along without a line of code here.
 *   Everything that does not depend on the record is computed before the query is built:
 *   the subject, the environment, time functions. The database sees constants.
 *
 * Values travel as bindings only. Names come from a tree that ConditionCompiler has checked,
 * and the grammar of the connection wraps them.
 *
 * @internal Not part of the public API, it may change in any release. AGENTS.md lists what an application may rely on.
 */
final class SqlCompiler
{
    /**
     * An expression that is NULL on every server. "0 = 1" would not do: under NOT it turns into
     * true, while "not unknown" has to stay unknown.
     */
    private const UNKNOWN = '(null = null)';

    /**
     * Pivot table of the aggregate whose filter is being compiled, for "pivot.column". It is set
     * and restored around the filter in aggregate(); passing it as an argument would thread one
     * more parameter through every method of the class for the sake of one node.
     */
    private static ?string $pivot = null;

    /**
     * Builds the SQL form of "the first applicable permission decides", from the last entry up:
     *   permit       (c) or (rest)
     *   prohibition  (c is not true) and (rest)
     *
     * "Is not true" is written as CASE, because SQL Server has no such operator and MySQL,
     * PostgreSQL and SQLite spell it differently. Only prohibitions pay for the wrapper.
     * Conditions of permits stay bare, so an index on their columns remains usable.
     *
     * @param  list<array{0:bool, 1:?array}>  $entries Strongest first, as Permissions compiles them.
     * @return array{0:string, 1:list<mixed>} SQL and bindings.
     */
    public static function entries(array $entries, Builder $query, Context $context): array
    {
        // Null stands for "nothing follows", which means "not permitted". It keeps the usual case,
        // one permit and nothing else, free of an "or (0 = 1)" tail that reads like a mistake in a query log.
        [$sql, $bindings] = [null, []];

        foreach (array_reverse($entries) as $entry) {
            [$c, $cb] = $entry[1] === null ? ['1 = 1', []] : self::where($entry[1], $query, $context);

            if ($entry[0]) {
                $sql = $sql === null ? "($c)" : "($c) or ($sql)";
            } elseif ($sql === null) {
                // A prohibition with nothing after it permits nothing, whatever its condition says.
                continue;
            } else {
                $sql = "(case when ($c) then 1 else 0 end = 0) and ($sql)";
            }

            $bindings = [...$cb, ...$bindings];
        }

        $sql ??= '0 = 1';

        return [$sql, $bindings];
    }

    /**
     * @return array{0:string, 1:list<mixed>} boolean SQL + bindings
     */
    public static function where(array $node, Builder $query, Context $context): array
    {
        $node = self::fold($node, $context);

        switch ($node[0]) {
            case 'val':
                return [$node[1] === true ? '1 = 1' : ($node[1] === false ? '0 = 1' : self::UNKNOWN), []];

            case 'and':
            case 'or':
                [$a, $ab] = self::where($node[1], $query, $context);
                [$b, $bb] = self::where($node[2], $query, $context);

                return ["($a {$node[0]} $b)", [...$ab, ...$bb]];

            case 'not':
                [$a, $ab] = self::where($node[1], $query, $context);

                return ["not ($a)", $ab];

            case 'agg':
                // Only exists() stands alone as a boolean. Other aggregates reach here through "cmp".
                return self::operand($node, $query, $context);

            case 'str':
                return self::string($node, $query, $context);

            case 'is-author':
                $subject = $context->subject;
                if (! $subject || $subject->getKey() === null) {
                    return [self::UNKNOWN, []];
                }
                $column = Evaluator::authorKey($subject, $query->getModel());

                return [self::column($query, $column).' = ?', [$subject->getKey()]];

            case 'in':
                [$left, $lb] = self::operand($node[1], $query, $context);
                $list        = Evaluator::evaluate($node[2], $context);
                if (! is_array($list)) {
                    return [self::UNKNOWN, []];
                }
                if ($list === []) {
                    return [$node[3] ? '1 = 1' : '0 = 1', []];
                }
                if (($node[4] ?? null) !== null) {
                    // What cannot be a value of the column cannot match it either, in "in" and in "not in" alike.
                    $list = array_filter(array_map(static fn ($item) => Evaluator::typed($item, $node[4]), $list), static fn ($item) => $item !== null);
                    if ($list === []) {
                        return [$node[3] ? '1 = 1' : '0 = 1', []];
                    }
                }
                $marks = implode(', ', array_fill(0, count($list), '?'));

                return ["$left ".($node[3] ? 'not in' : 'in')." ($marks)", [...$lb, ...array_values($list)]];

            case 'cmp':
                [, $op, $leftNode, $rightNode] = $node;
                [$left, $lb]                   = self::operand($leftNode, $query, $context);
                if ($rightNode === ['val', null]) {
                    return ["$left is ".($op === '==' ? '' : 'not ').'null', $lb];
                }
                [$right, $rb] = self::operand($rightNode, $query, $context);

                // The value goes to the database in the kind of the column, as Evaluator compares it.
                // Without it MySQL compares a varchar with the integer 1234 as numbers, SQLite ranks
                // a number given as text above every aggregate, PostgreSQL fails on 'abc' for a number.
                if (($node[4] ?? null) !== null) {
                    foreach ([[$left, &$lb], [$right, &$rb]] as [$sql, &$bindings]) {
                        if ($sql !== '?') {
                            continue;
                        }
                        $bindings[0] = Evaluator::typed($bindings[0], $node[4]);
                        if ($bindings[0] === null) {
                            return [self::UNKNOWN, []];
                        }
                    }
                    unset($bindings);
                }

                return ["$left ".($op === '==' ? '=' : $op)." $right", [...$lb, ...$rb]];
        }

        throw new UntranslatableConditionException('Condition node "'.$node[0].'" cannot be used in a query');
    }

    /**
     * Computes every part that does not read the record and simplifies "and" / "or" around the
     * result. "env.weekday in [6,7] && order.cost < 200" on a Friday becomes "0 = 1", and the
     * database does not evaluate a cost comparison for rows that cannot match.
     */
    private static function fold(array $node, Context $context): array
    {
        if (! ConditionCompiler::needsRecord($node)) {
            return ['val', Evaluator::evaluate($node, $context)];
        }

        if ($node[0] !== 'and' && $node[0] !== 'or') {
            return $node;
        }

        $a = self::fold($node[1], $context);
        $b = self::fold($node[2], $context);

        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            if ($x[0] !== 'val') {
                continue;
            }
            if ($node[0] === 'and' && $x[1] === false) {
                return ['val', false];
            }
            if ($node[0] === 'and' && $x[1] === true) {
                return $y;
            }
            if ($node[0] === 'or' && $x[1] === true) {
                return ['val', true];
            }
            if ($node[0] === 'or' && $x[1] === false) {
                return $y;
            }
        }

        return [$node[0], $a, $b];
    }

    private static function operand(array $node, Builder $query, Context $context): array
    {
        if (! ConditionCompiler::needsRecord($node)) {
            $value = Evaluator::evaluate($node, $context);

            return ['?', [is_bool($value) ? (int) $value : $value]];
        }

        return match ($node[0]) {
            'res'   => self::through($query, $node[1], fn (Builder $b) => [self::column($b, $node[2]), []]),
            'agg'   => self::through($query, $node[2], fn (Builder $b) => self::aggregate($b, $node, $context)),
            'pivot' => [self::pivotColumn($query, $node[1]), []],
            'math'  => self::rounded($node, $query, $context),
            'str'   => self::string($node, $query, $context),
            default => throw new UntranslatableConditionException(
                $node[0] === 'call'
                    ? 'Function '.$node[1].'() is applied to columns of the record and cannot be used in a query'
                    : 'Condition node "'.$node[0].'" cannot be used as a value in a query'
            ),
        };
    }

    /**
     * Rounds the way Evaluator does, see Evaluator::math(). SQLite has no fixed decimals and
     * ignores the scale of a cast, so it rounds with round(); PostgreSQL has no round() for
     * double precision, so everybody else casts.
     */
    private static function rounded(array $node, Builder $query, Context $context): array
    {
        [$sql, $bindings] = self::math($node, $query, $context);

        return [$query->getConnection()->getDriverName() === 'sqlite'
            ? 'round('.$sql.', '.Evaluator::SCALE.')'
            : 'cast('.$sql.' as decimal(30, '.Evaluator::SCALE.'))', $bindings];
    }

    /**
     * Numbers are written into the SQL text, not bound. PostgreSQL gives a parameter the type of
     * its neighbour: next to an integer column "? " becomes an integer, and 1.2 fails to bind.
     * Only values that Evaluator::typed() has turned into numbers get here, so there is nothing to inject.
     *
     * "* 1.0" before a division keeps integers from dividing as integers: 150 / 4 is 37 on
     * PostgreSQL and SQLite and 37.5 in PHP and MySQL.
     */
    private static function math(array $node, Builder $query, Context $context): array
    {
        $parts    = [];
        $bindings = [];

        foreach ([$node[2], $node[3]] as $operand) {
            if ($operand[0] === 'math') {
                [$sql, $own] = self::math($operand, $query, $context);
            } elseif (ConditionCompiler::needsRecord($operand)) {
                [$sql, $own] = self::operand($operand, $query, $context);
            } else {
                $value = Evaluator::evaluate($operand, $context);
                $value = $value === null ? null : Evaluator::typed($value, 'num');

                [$sql, $own] = [$value === null || (is_float($value) && ! is_finite($value)) ? 'null' : (string) $value, []];
            }

            $parts[]  = $sql;
            $bindings = [...$bindings, ...$own];
        }

        return [$node[1] === '/'
            ? '('.$parts[0].' * 1.0 / nullif('.$parts[1].', 0))'
            : '('.$parts[0].' '.$node[1].' '.$parts[1].')', $bindings];
    }

    /**
     * Written without LIKE, see Normalizer::string(). The length of the needle is known here,
     * because the needle never reads the record, and goes into the SQL text as a number.
     */
    private static function string(array $node, Builder $query, Context $context): array
    {
        [$subject, $bindings] = self::operand($node[2], $query, $context);

        if ($node[1] === 'lower') {
            return ['lower('.$subject.')', $bindings];
        }

        $needle = Evaluator::evaluate($node[3], $context);
        if (! is_scalar($needle)) {
            return [self::UNKNOWN, []];
        }

        $needle = (string) $needle;
        $length = mb_strlen($needle);
        $driver = $query->getConnection()->getDriverName();

        // Every text starts with, ends with and contains the empty text, except the text that is not there.
        if ($length === 0) {
            return [$subject.' is not null', $bindings];
        }

        // MySQL and MariaDB compare text by the collation of the column, and the usual one ignores case:
        // "startsWith(status, 'DR')" would find "draft" in a list and refuse it for the record. Bytes do not
        // ignore anything, and the cast does not care which charset the column has.
        $exact  = in_array($driver, ['mysql', 'mariadb'], true);
        $text   = $exact ? 'cast('.$subject.' as binary)' : $subject;
        $needed = $exact ? 'cast(? as binary)' : '?';
        $length = $exact ? strlen($needle) : $length;

        $sql = match ($node[1]) {
            'startsWith' => ($driver === 'sqlsrv' ? 'substring' : 'substr').'('.$text.', 1, '.$length.') = '.$needed,
            'endsWith'   => ($driver === 'sqlite' ? 'substr('.$text.', -'.$length.')' : 'right('.$text.', '.$length.')').' = '.$needed,
            'contains'   => match ($driver) {
                'pgsql'  => 'strpos('.$text.', ?) > 0',
                'sqlsrv' => 'charindex(?, '.$text.') > 0',
                default  => 'instr('.$text.', '.$needed.') > 0',
            },
        };

        // charindex() takes the needle first, so its binding goes before those of the subject.
        return [$sql, $driver === 'sqlsrv' && $node[1] === 'contains' ? [$needle, ...$bindings] : [...$bindings, $needle]];
    }

    private static function pivotColumn(Builder $query, string $column): string
    {
        if (self::$pivot === null) {
            throw new UntranslatableConditionException('"pivot.'.$column.'" is used outside of an aggregate over a many-to-many relation');
        }

        return $query->getQuery()->getGrammar()->wrap(self::$pivot.'.'.$column);
    }

    /**
     * Nests one scalar subquery per relation of the chain. LIMIT 1 is for hasOne and morphOne
     * that point at several rows: PostgreSQL refuses a scalar subquery that returns two.
     */
    private static function through(Builder $parent, array $chain, callable $leaf): array
    {
        if ($chain === []) {
            return $leaf($parent);
        }

        $sub                = self::existence($parent, array_shift($chain));
        [$inner, $bindings] = self::through($sub, $chain, $leaf);

        $sub->getQuery()->columns = [new Expression($inner)];
        $sub->getQuery()->addBinding($bindings, 'select');
        $sub->getQuery()->limit(1);

        return ['('.$sub->toSql().')', $sub->getBindings()];
    }

    /**
     * sum() of nothing is 0, through COALESCE, because the evaluator sums an empty collection to 0.
     * min() and max() of nothing stay NULL in both readers.
     *
     * Aggregates are the expensive part of a condition. exists() becomes a semi join, which the
     * database plans as a whole. count(), sum(), min() and max() become a correlated scalar
     * subquery, and the database runs it once for every parent row that reaches it. The price
     * follows the number of rows checked: a page or a list narrowed by other conditions pays
     * little, a whole table with nothing else to narrow it pays for every row, and paginate()
     * asks for that with its count(*). Measured on PostgreSQL with 500 thousand comments and
     * 3 million likes, "a comment with count(likes) > 10": 5 ms for a page, 4.8 s for the whole list.
     *
     * The alternative, "id in (select ... group by ... having ...)", reads the related table once
     * and costs the same 0.5 s for a page and for the whole list, because LIMIT does not pass
     * through GROUP BY. It also loses parents with no related rows, so "count < 3" needs a
     * different query than "count > 10". The correlated form is kept: lists are read by pages.
     * A counter column on the parent model makes the question disappear, since it is an
     * ordinary attribute for a condition.
     */
    private static function aggregate(Builder $parent, array $node, Context $context): array
    {
        [, $fn, , $many, $column, $filter] = $node;

        $sub      = self::existence($parent, $many);
        $relation = $parent->getModel()->$many();
        $outer    = self::$pivot;

        self::$pivot = $relation instanceof BelongsToMany ? $relation->getTable() : null;

        try {
            $target = $column === null ? null : (str_starts_with($column, 'pivot.') ? self::pivotColumn($sub, substr($column, 6)) : self::column($sub, $column));

            $sub->getQuery()->columns = [new Expression(match ($fn) {
                'count'  => 'count(*)',
                'exists' => '1',
                'sum'    => 'coalesce(sum('.$target.'), 0)',
                default  => $fn.'('.$target.')',
            })];

            if ($filter) {
                [$sql, $bindings] = self::where($filter, $sub, $context);
                $sub->whereRaw($sql, $bindings);
            }
        } finally {
            self::$pivot = $outer;
        }

        $sql = '('.$sub->toSql().')';

        return [$fn === 'exists' ? 'exists '.$sql : $sql, $sub->getBindings()];
    }

    /**
     * The query Eloquent itself builds for has() and withCount(). Relations tie it to the parent
     * query, alias the table when a model relates to itself, and add pivot and morph constraints.
     * Writing these joins by hand would mean one code path per relation type and a new bug for
     * every type Laravel adds.
     */
    private static function existence(Builder $parent, string $name): Builder
    {
        $relation = Relation::noConstraints(static fn () => $parent->getModel()->$name());

        $query = $relation->getRelationExistenceQuery($relation->getRelated()->newQueryWithoutRelationships(), $parent, new Expression('1'));

        // A relation can carry conditions of its own, hasMany(...)->where('hidden', false). They live on
        // the query of the relation, not on the fresh one above, and has() of Eloquent merges them the
        // same way. Without the merge a list counts rows that a check of a loaded record never sees.
        return $query->mergeConstraintsFrom($relation->getQuery());
    }

    private static function column(Builder $builder, string $column): string
    {
        return $builder->getQuery()->getGrammar()->wrap($builder->getModel()->qualifyColumn($column));
    }
}

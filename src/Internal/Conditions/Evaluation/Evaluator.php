<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Internal\Conditions\Evaluation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;
use Wnikk\LaravelAccessRules\Conditions\Context;

/**
 * Evaluates a condition against one record that is already loaded.
 *
 * This is the fast half of the conditions layer: $user->can('orders.view', $order) lands
 * here through DecisionPoint, reads attributes of the loaded model and runs no query unless
 * the condition walks a relation that is not loaded yet.
 *
 * The evaluator follows the three-valued logic of SQL. A comparison with NULL, a missing
 * attribute or a missing related record gives "unknown", null in PHP, and "not unknown"
 * stays unknown. Plain boolean logic is simpler and wrong: "!(order.client.city == 'Y')"
 * would be true for an order without a client, while the WHERE clause built from the same
 * tree drops that order. The user would see the record refused in the list and opened by URL.
 *
 * The tree is interpreted, not compiled to PHP. Interpretation costs 0.6 microseconds for five
 * predicates against 0.1 for generated code, and both disappear next to one database query.
 * Generated code would put text from an admin panel on a path that ends in include.
 *
 * @internal Not part of the public API, it may change in any release. AGENTS.md lists what an application may rely on.
 */
final class Evaluator
{
    /**
     * Decimal places arithmetic keeps. Money and quantities use up to four, and a double holds
     * fifteen significant digits, so six places leave nine digits before the point.
     */
    public const SCALE = 6;

    /**
     * @return mixed Boolean nodes give true, false or null for "unknown". Callers treat only true as "applies".
     */
    public static function evaluate(array $node, Context $context): mixed
    {
        switch ($node[0]) {
            case 'val':
                return $node[1];

            case 'attr':
                return $context->attr($node[1], $node[2]);

            case 'call':
                return self::call($node[1], array_map(static fn ($arg) => self::evaluate($arg, $context), $node[2]), $context);

            case 'res':
                $model = self::walk($context->record(), $node[1]);

                return $model === null ? null : Context::scalar($model->getAttribute($node[2]));

            case 'agg':
                return self::aggregate($node, $context);

            case 'pivot':
                return Context::scalar(self::pivot($context->record())?->getAttribute($node[1]));

            case 'math':
                $number = self::math($node, $context);

                return $number === null ? null : round($number, self::SCALE);

            case 'str':
                return self::string($node, $context);

            case 'is-author':
                return self::isAuthor($context);

            case 'not':
                $value = self::evaluate($node[1], $context);

                return $value === null ? null : ! $value;

            case 'and':
                $a = self::evaluate($node[1], $context);
                if ($a === false) {
                    return false;
                }
                $b = self::evaluate($node[2], $context);
                if ($b === false) {
                    return false;
                }

                return ($a === null || $b === null) ? null : true;

            case 'or':
                $a = self::evaluate($node[1], $context);
                if ($a === true) {
                    return true;
                }
                $b = self::evaluate($node[2], $context);
                if ($b === true) {
                    return true;
                }

                return ($a === null || $b === null) ? null : false;

            case 'in':
                $value = self::evaluate($node[1], $context);
                $list  = self::evaluate($node[2], $context);
                if ($value === null || ! is_array($list)) {
                    return null;
                }
                // A marked number column needs nothing here: PHP never finds 'abc' equal to a number.
                // The mark is for SqlCompiler, which must keep 'abc' away from the database.
                if (($node[4] ?? null) === 'text') {
                    $value = self::typed($value, 'text');
                    if ($value === null) {
                        return null;
                    }
                    $list = array_filter(array_map(static fn ($item) => self::typed($item, 'text'), $list), static fn ($item) => $item !== null);

                    // Strict, or '1e3' is found in ['1000'].
                    $found = in_array($value, $list, true);
                } else {
                    $found = in_array($value, $list, false);
                }

                return $node[3] ? ! $found : $found;

            case 'cmp':
                [, $op, $left, $right] = $node;

                // "x == null" means IS NULL and is never unknown. Without the exception nobody could
                // write "no client, or a client outside of city Y".
                if ($right === ['val', null]) {
                    $isNull = self::evaluate($left, $context) === null;

                    return $op === '==' ? $isNull : ! $isNull;
                }

                $l = self::evaluate($left, $context);
                $r = self::evaluate($right, $context);
                if ($l === null || $r === null) {
                    return null;
                }

                // Only comparisons that Normalizer::compared() found risky carry a mark.
                if (($node[4] ?? null) !== null) {
                    if ($node[4] === 'text') {
                        $l = self::typed($l, 'text');
                        $r = self::typed($r, 'text');
                        if ($l === null || $r === null) {
                            return null;
                        }
                        [$l, $r] = [strcmp($l, $r), 0];
                    } elseif ((is_string($l) && ! is_numeric($l)) || (is_string($r) && ! is_numeric($r))) {
                        // Text that is no number against a number column is unknown, as it is in the query.
                        return null;
                    }
                }

                return match ($op) {
                    '==' => $l == $r,
                    '!=' => $l != $r,
                    '>'  => $l > $r,
                    '>=' => $l >= $r,
                    '<'  => $l < $r,
                    '<=' => $l <= $r,
                };
        }

        throw new LogicException('Unknown node "'.$node[0].'" in condition');
    }

    /**
     * The result is rounded once, at the top of an expression, and SqlCompiler rounds the same way.
     * 500 * 1.2 is 600.0000000000001 in PHP and in SQLite and exactly 600 in PostgreSQL, so without
     * rounding "cost * 1.2 > 600" depends on who computes it.
     *
     * Division by zero is unknown, like a comparison with NULL. PostgreSQL would fail the query.
     */
    private static function math(array $node, Context $context): int|float|null
    {
        $values = [];
        foreach ([$node[2], $node[3]] as $operand) {
            $value = $operand[0] === 'math' ? self::math($operand, $context) : self::evaluate($operand, $context);
            $value = $value === null ? null : self::typed($value, 'num');
            if ($value === null || is_string($value)) {
                return null;
            }
            $values[] = $value;
        }

        return match ($node[1]) {
            '+' => $values[0] + $values[1],
            '-' => $values[0] - $values[1],
            '*' => $values[0] * $values[1],
            '/' => $values[1] == 0 ? null : $values[0] / $values[1],
        };
    }

    /**
     * Exact comparison, letter case included. Normalizer::string() tells why LIKE is not used.
     */
    private static function string(array $node, Context $context): bool|string|null
    {
        $subject = self::evaluate($node[2], $context);
        if (! is_scalar($subject)) {
            return null;
        }
        $subject = (string) $subject;

        if ($node[1] === 'lower') {
            return mb_strtolower($subject);
        }

        $needle = self::evaluate($node[3], $context);
        if (! is_scalar($needle)) {
            return null;
        }

        return match ($node[1]) {
            'startsWith' => str_starts_with($subject, (string) $needle),
            'endsWith'   => str_ends_with($subject, (string) $needle),
            'contains'   => str_contains($subject, (string) $needle),
        };
    }

    /**
     * Eloquent hangs the pivot row on the related model as a relation, named "pivot" unless the
     * relation says ->as('membership'). Looking for the Pivot instance finds it under any name.
     */
    private static function pivot(?Model $row): ?Model
    {
        foreach ($row?->getRelations() ?? [] as $related) {
            if ($related instanceof Pivot) {
                return $related;
            }
        }

        return null;
    }

    /**
     * Brings a value to the kind of the column it is compared with, the way the database does.
     * Normalizer::compared() tells why the column decides. SqlCompiler passes bindings through
     * the same method, so both readers convert alike.
     *
     * @return int|float|string|null Null is "unknown": text that is not a number against a number column. PostgreSQL fails the whole query on such a parameter, so it must not reach it.
     */
    public static function typed(mixed $value, string $type): int|float|string|null
    {
        if ($type === 'text') {
            return is_scalar($value) ? (string) (is_bool($value) ? (int) $value : $value) : null;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return is_bool($value) ? (int) $value : $value;
        }

        if (! is_string($value) || ! is_numeric($value)) {
            return null;
        }

        // Ids of twenty digits turn into a float and lose their tail. The database reads them exactly.
        $number = $value + 0;

        return is_float($number) && ctype_digit(ltrim($value, '-')) ? $value : $number;
    }

    /**
     * Functions of the application win over built-in ones, so a project can redefine "now" for
     * its own calendar. Time functions return "Y-m-d H:i:s" text, the form columns are compared in.
     *
     * monthStart() moves to the first day before it adds months. Adding a month to the 31st first
     * would land in the month after next and a turnover condition would count the wrong period.
     */
    public static function call(string $name, array $args, Context $context): mixed
    {
        $own = config('access.functions')[$name] ?? null;
        if ($own !== null) {
            return Context::scalar((is_string($own) ? app($own) : $own)($args, $context));
        }

        if (in_array($name, TreeFunctions::NAMES, true)) {
            return app(TreeFunctions::class)->ids($name, $args);
        }

        $now = $context->now();

        return match ($name) {
            'now'        => $now->format('Y-m-d H:i:s'),
            'today'      => $now->format('Y-m-d'),
            'ago'        => $now->modify('-'.$args[0])->format('Y-m-d H:i:s'),
            'after'      => $now->modify('+'.$args[0])->format('Y-m-d H:i:s'),
            'monthStart' => $now->startOfMonth()->addMonthsNoOverflow((int) ($args[0] ?? 0))->format('Y-m-d H:i:s'),
            'yearStart'  => $now->startOfYear()->addYears((int) ($args[0] ?? 0))->format('Y-m-d H:i:s'),
            default      => throw new LogicException('Unknown function '.$name.'() in condition'),
        };
    }

    /**
     * Column of the record that holds its author, for the ".self" suffix and isAuthor().
     *
     * The model decides first, config second, and the convention of version 2 last: class User
     * with key "id" gives "user_id". The convention stays the default because data and code of
     * version 2 rely on it.
     */
    public static function authorKey(Model $subject, Model $record): string
    {
        if (method_exists($record, 'accessAuthorKey')) {
            return $record->accessAuthorKey();
        }

        $configured = config('access.author_key');
        if ($configured) {
            return $configured;
        }

        // "Users" and "User" both give "user_id": version 2 cut one trailing "s" and projects rely on it.
        $name = strtolower(class_basename($subject));
        if (str_ends_with($name, 's')) {
            $name = substr($name, 0, -1);
        }

        return $name.'_'.$subject->getKeyName();
    }

    /**
     * Keys compare loosely. PDO returns "5" for an integer column on some drivers, and the strict
     * comparison of version 2 refused the author of a record for that alone.
     */
    private static function isAuthor(Context $context): ?bool
    {
        $subject = $context->subject;
        $record  = $context->record();
        if (! $subject || ! $record || $subject->getKey() === null) {
            return null;
        }

        $author = $record->getAttribute(self::authorKey($subject, $record));

        return $author === null ? null : $author == $subject->getKey();
    }

    /**
     * Aggregates are the expensive part of a check of one record. The related rows are loaded as
     * models and counted in PHP, so a record with fifty thousand related rows costs fifty thousand
     * models to answer "count > 10", and a nested aggregate in a filter loads a relation per row.
     *
     * A COUNT query per check is the cheaper reading for one large relation and the more
     * expensive one everywhere else: a relation that is already loaded answers with no query,
     * and a filter may call functions that exist only in PHP. SqlCompiler::aggregate() has the
     * same subject on the side of lists.
     */
    private static function aggregate(array $node, Context $context): mixed
    {
        [, $fn, $chain, $many, $column, $filter] = $node;

        $model = self::walk($context->record(), $chain);
        if ($model === null) {
            return null;
        }

        // getRelationValue() returns a loaded relation as it is and loads a missing one once. Callers
        // that check many records eager load; the evaluator does not guess and does not query per call.
        $rows = $model->getRelationValue($many);

        if ($filter) {
            $rows = $rows->filter(static fn (Model $row) => self::evaluate($filter, $context->forRecord($row)) === true);
        }

        return match ($fn) {
            'count'  => $rows->count(),
            'exists' => $rows->isNotEmpty(),
            'sum'    => $rows->sum($column),
            'min'    => Context::scalar($rows->min($column)),
            'max'    => Context::scalar($rows->max($column)),
        };
    }

    private static function walk(?Model $model, array $chain): ?Model
    {
        foreach ($chain as $relation) {
            $model = $model?->getRelationValue($relation);
            if (! $model instanceof Model) {
                return null;
            }
        }

        return $model;
    }
}

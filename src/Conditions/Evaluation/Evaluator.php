<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Conditions\Evaluation;

use Illuminate\Database\Eloquent\Model;
use LogicException;

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
 */
final class Evaluator
{
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
                $found = in_array($value, $list, false);

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

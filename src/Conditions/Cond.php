<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Conditions;

use Wnikk\LaravelAccessRules\Conditions\Syntax\Parser;

/**
 * Builds a condition in PHP code instead of text.
 *
 *     Cond::all(
 *         Cond::attr('order.cost')->gt(100),
 *         Cond::count('order.items')->lt(3),
 *     )
 *
 * Text is for people who edit conditions, this class is for code that assembles them from
 * parts, where gluing strings together invites quoting mistakes. It produces the raw tree
 * of Parser and nothing else, so both ways pass through the same Normalizer and the same
 * checks. A builder with a tree of its own would need its own validation, and the two would
 * disagree sooner or later.
 *
 * Every method returns a new object, so a partial condition can be reused in two places.
 */
final class Cond
{
    private function __construct(private array $raw) {}

    /**
     * @param string $path The same paths the text uses: "order.cost", "user.department_id", "env.weekday".
     */
    public static function attr(string $path): self
    {
        return new self(['path', $path]);
    }

    public static function value(mixed $value): self
    {
        return new self(['val', $value]);
    }

    /**
     * Lets a piece of text take part in a built condition, for example one stored by an editor.
     */
    public static function raw(string $text): self
    {
        return new self(Parser::parse($text));
    }

    public static function call(string $function, mixed ...$args): self
    {
        return new self(['call', $function, array_map(self::operand(...), $args)]);
    }

    public static function isAuthor(): self
    {
        return new self(['call', 'isAuthor', []]);
    }

    public static function count(string $relation, self|string|null $filter = null): self
    {
        return self::aggregate('count', $relation, $filter);
    }

    public static function exists(string $relation, self|string|null $filter = null): self
    {
        return self::aggregate('exists', $relation, $filter);
    }

    public static function sum(string $column, self|string|null $filter = null): self
    {
        return self::aggregate('sum', $column, $filter);
    }

    public static function min(string $column, self|string|null $filter = null): self
    {
        return self::aggregate('min', $column, $filter);
    }

    public static function max(string $column, self|string|null $filter = null): self
    {
        return self::aggregate('max', $column, $filter);
    }

    public static function all(self|string ...$conditions): self
    {
        return self::join('and', $conditions);
    }

    public static function any(self|string ...$conditions): self
    {
        return self::join('or', $conditions);
    }

    public static function not(self|string $condition): self
    {
        return new self(['not', self::boolean($condition)]);
    }

    public function eq(mixed $value): self
    {
        return $this->compare('==', $value);
    }

    public function ne(mixed $value): self
    {
        return $this->compare('!=', $value);
    }

    public function gt(mixed $value): self
    {
        return $this->compare('>', $value);
    }

    public function gte(mixed $value): self
    {
        return $this->compare('>=', $value);
    }

    public function lt(mixed $value): self
    {
        return $this->compare('<', $value);
    }

    public function lte(mixed $value): self
    {
        return $this->compare('<=', $value);
    }

    public function isNull(): self
    {
        return $this->compare('==', null);
    }

    public function notNull(): self
    {
        return $this->compare('!=', null);
    }

    /**
     * @param array|self $list Values, or a list attribute such as Cond::attr('user.tenant').
     */
    public function in(array|self $list): self
    {
        return new self(['in', $this->raw, self::operand($list), false]);
    }

    public function notIn(array|self $list): self
    {
        return new self(['in', $this->raw, self::operand($list), true]);
    }

    public function toRaw(): array
    {
        return $this->raw;
    }

    private function compare(string $op, mixed $value): self
    {
        return new self(['cmp', $op, $this->raw, self::operand($value)]);
    }

    private static function operand(mixed $value): array
    {
        return $value instanceof self ? $value->raw : ['val', $value];
    }

    private static function boolean(self|string $condition): array
    {
        return is_string($condition) ? Parser::parse($condition) : $condition->raw;
    }

    private static function aggregate(string $fn, string $path, self|string|null $filter): self
    {
        $args = [['path', $path]];
        if ($filter !== null) {
            $args[] = self::boolean($filter);
        }

        return new self(['call', $fn, $args]);
    }

    /**
     * Nodes of the tree are binary, so a list folds to the left. An empty list gives the neutral
     * value: true for "all", false for "any".
     */
    private static function join(string $op, array $conditions): self
    {
        $nodes = array_map(self::boolean(...), array_values($conditions));
        $tree  = array_shift($nodes) ?? ['val', $op === 'and'];

        foreach ($nodes as $node) {
            $tree = [$op, $tree, $node];
        }

        return new self($tree);
    }
}

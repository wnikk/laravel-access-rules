<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Protected\Conditions\Syntax;

use Wnikk\LaravelAccessRules\Exceptions\InvalidConditionException;

/**
 * Turns the text of a condition into a raw syntax tree.
 *
 *     order.cost > 100 && order.items.count < 3
 *     order.status in ['draft', 'review'] and not order.locked
 *     sum(client.payments.amount, paid_at >= monthStart(-1)) > 1000
 *
 * Conditions come from people: from migrations today, from an editor in an admin panel
 * tomorrow. Text is what they can write and review. The parser runs when a condition is
 * saved and never during a check; the database and the cache hold the tree.
 *
 * It is a hand written precedence climbing parser of about 150 lines. symfony/expression-language
 * parses a similar grammar, but its 8.x line needs PHP 8.4.1 and its tree describes PHP
 * expressions, not columns and relations, so the package would translate one tree into
 * another anyway.
 *
 * The parser knows nothing about models. Names stay unresolved paths here, and Normalizer
 * decides whether "order.items" is a column, a relation or a mistake.
 *
 * Raw nodes: ['val', v], ['path', 'a.b.c'], ['call', name, args[]], ['not', x],
 * ['and', a, b], ['or', a, b], ['cmp', op, l, r], ['in', l, r, negated], ['math', op, l, r].
 *
 * @internal Implementation of the package, not an entry point for applications. The public API is the facade Access, the traits and the classes outside src/Protected; AGENTS.md lists them.
 */
final class Parser
{
    private const TOKEN = '/\G\s*(?:(?<num>\d+(?:\.\d+)?)|\'(?<s1>(?:[^\'\\\\]|\\\\.)*)\'|"(?<s2>(?:[^"\\\\]|\\\\.)*)"'
        .'|(?<id>[A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*)|(?<op>&&|\|\||==|!=|>=|<=|[><!()\[\],+*\/-]))/A';

    /**
     * Binding strength, the same ladder as in SQL and PHP: "or" 1, "and" 2, "not" 3 (in prefix()),
     * comparisons, "in" and "between" 4, "+" and "-" 5, "*" and "/" 6. Matching SQL matters,
     * because the same tree becomes a WHERE clause and a reader compares the two.
     */
    private const PREC = ['||' => 1, 'or' => 1, '&&' => 2, 'and' => 2,
        '=='                   => 4, '!=' => 4, '>' => 4, '>=' => 4, '<' => 4, '<=' => 4, 'in' => 4, 'not' => 4, 'between' => 4,
        '+'                    => 5, '-' => 5, '*' => 6, '/' => 6];

    /** @var list<array{0:string,1:mixed}> */
    private array $tokens = [];

    private int $pos = 0;

    public static function parse(string $source): array
    {
        $p = new self;
        $p->tokenize($source);
        $ast = $p->expr(0);
        if ($p->pos < count($p->tokens)) {
            throw new InvalidConditionException('Unexpected "'.$p->tokens[$p->pos][1].'" in: '.$source);
        }

        return $ast;
    }

    private function tokenize(string $src): void
    {
        $offset = 0;
        $len    = strlen(rtrim($src));
        while ($offset < $len) {
            if (! preg_match(self::TOKEN, $src, $m, PREG_UNMATCHED_AS_NULL, $offset)) {
                throw new InvalidConditionException('Cannot parse near: '.substr($src, $offset, 20));
            }
            $offset += strlen($m[0]);
            $this->tokens[] = match (true) {
                $m['num'] !== null => ['val', str_contains($m['num'], '.') ? (float) $m['num'] : (int) $m['num']],
                $m['s1'] !== null  => ['val', stripslashes($m['s1'])],
                $m['s2'] !== null  => ['val', stripslashes($m['s2'])],
                $m['id'] !== null  => ['id', $m['id']],
                default            => ['op', $m['op']],
            };
        }
    }

    private function peek(int $ahead = 0): ?array
    {
        return $this->tokens[$this->pos + $ahead] ?? null;
    }

    private function next(): array
    {
        return $this->tokens[$this->pos++] ?? throw new InvalidConditionException('Unexpected end of expression');
    }

    private function expect(string $op): void
    {
        $t = $this->next();
        if ($t[1] !== $op) {
            throw new InvalidConditionException("Expected \"$op\", got \"{$t[1]}\"");
        }
    }

    /**
     * Comparisons do not chain: "a < b < c" stops after "a < b" and parse() reports the rest.
     * A chained comparison means something else in every language, so the parser refuses instead of guessing.
     */
    private function expr(int $minPrec): array
    {
        $left = $this->prefix();

        while (($t = $this->peek()) && $t[0] !== 'val' && isset(self::PREC[$t[1]])) {
            $op = $t[1];
            if ($op === 'not' && ! in_array($this->peek(1)[1] ?? null, ['in', 'between'], true)) {
                break;
            }
            $prec = self::PREC[$op];
            if ($prec < $minPrec) {
                break;
            }
            $this->next();

            $negated = false;
            if ($op === 'not') {
                $op      = $this->next()[1];
                $negated = true;
            }

            if ($op === 'between') {
                $left = $this->between($left, $negated);

                continue;
            }

            $right = $this->expr($prec + 1);
            $left  = match ($op) {
                '||', 'or'         => ['or', $left, $right],
                '&&', 'and'        => ['and', $left, $right],
                'in'               => ['in', $left, $right, $negated],
                '+', '-', '*', '/' => ['math', $op, $left, $right],
                default            => ['cmp', $op, $left, $right],
            };
        }

        return $left;
    }

    /**
     * "x between a and b" is written out as "x >= a && x <= b" and has no node of its own. A node
     * would need a branch in the normalizer, in both readers and in the printer to say what two
     * comparisons already say. The price: x is read twice, so an aggregate in its place becomes
     * two subqueries of a list. Both ends are included, as in SQL.
     */
    private function between(array $value, bool $negated): array
    {
        $low = $this->expr(5);
        if (($this->next()[1] ?? null) !== 'and') {
            throw new InvalidConditionException('"between" expects "and" between its two ends');
        }
        $high = $this->expr(5);

        $range = ['and', ['cmp', '>=', $value, $low], ['cmp', '<=', $value, $high]];

        return $negated ? ['not', $range] : $range;
    }

    private function prefix(): array
    {
        [$type, $v] = $this->next();

        if ($type === 'val') {
            return ['val', $v];
        }

        if ($type === 'op') {
            switch ($v) {
                case '!': return ['not', $this->expr(3)];
                case '-':
                    // A number keeps its sign as a literal, so "[-1, 2]" stays a list of literals.
                    if (($this->peek()[0] ?? null) === 'val' && ! is_string($this->peek()[1])) {
                        return ['val', -$this->next()[1]];
                    }

                    return ['math', '-', ['val', 0], $this->expr(7)];
                case '(': $e = $this->expr(0);
                    $this->expect(')');

                    return $e;
                case '[':
                    $items = [];
                    while (($this->peek()[1] ?? null) !== ']') {
                        $item = $this->expr(7);
                        if ($item[0] !== 'val') {
                            throw new InvalidConditionException('Only literals are allowed in a list');
                        }
                        $items[] = $item[1];
                        if (($this->peek()[1] ?? null) === ',') {
                            $this->next();
                        }
                    }
                    $this->expect(']');

                    return ['val', $items];
            }
            throw new InvalidConditionException("Unexpected \"$v\"");
        }

        // Keywords are ordinary identifiers to the tokenizer. They get their meaning here, by position.
        switch ($v) {
            case 'not':   return ['not', $this->expr(3)];
            case 'true':  return ['val', true];
            case 'false': return ['val', false];
            case 'null':  return ['val', null];
        }
        if (($this->peek()[1] ?? null) === '(' && $this->peek()[0] === 'op') {
            $this->next();
            $args = [];
            while (($this->peek()[1] ?? null) !== ')') {
                $args[] = $this->expr(0);
                if (($this->peek()[1] ?? null) === ',') {
                    $this->next();
                }
            }
            $this->expect(')');

            return ['call', $v, $args];
        }

        return ['path', $v];
    }
}

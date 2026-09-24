<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Protected\Conditions\Syntax;

use LogicException;

/**
 * Turns a stored condition tree back into text, for admin panels and for error messages.
 *
 * The text of a condition is not stored next to its tree. Two copies drift: somebody fixes
 * the tree through the API and the editor keeps showing the old text. The tree is the only
 * source, and this class makes it readable again. Comments and spacing of the original text
 * do not survive, which is the accepted price.
 *
 * The output is valid input of Parser, and the test suite holds the round trip for every
 * scenario of its catalogue. ConditionCompiler::describe() is the caller.
 *
 * @internal Implementation of the package, not an entry point for applications. The public API is the facade Access, the traits and the classes outside src/Protected; AGENTS.md lists them.
 */
final class Printer
{
    /**
     * The ladder of Parser::PREC, by node type. A child weaker than its parent gets brackets,
     * so "(a || b) && c" does not print as "a || b && c" and change its meaning on the way back.
     */
    private const PRECEDENCE = ['or' => 1, 'and' => 2, 'not' => 3, 'cmp' => 4, 'in' => 4, '+' => 5, '-' => 5, '*' => 6, '/' => 6];

    /**
     * @param string $root Name of the record in the text: the alias of its model, or "resource" when the rule has none.
     */
    public static function print(array $node, string $root = 'resource'): string
    {
        return self::node($node, $root, 0);
    }

    private static function node(array $node, string $root, int $parent): string
    {
        $text = match ($node[0]) {
            'val'       => self::value($node[1]),
            'attr'      => ($node[1] === 'subject' ? 'user' : $node[1]).'.'.$node[2],
            'res'       => self::path($root, [...$node[1], $node[2]]),
            'pivot'     => 'pivot.'.$node[1],
            'is-author' => 'isAuthor()',
            'str'       => $node[1].'('.self::node($node[2], $root, 0).($node[3] === null ? '' : ', '.self::node($node[3], $root, 0)).')',
            // The right side gets brackets at equal strength too: "a - (b - c)" is not "a - b - c".
            'math' => self::node($node[2], $root, self::PRECEDENCE[$node[1]]).' '.$node[1].' '.self::node($node[3], $root, self::PRECEDENCE[$node[1]] + 1),
            'call' => $node[1].'('.implode(', ', array_map(static fn ($arg) => self::node($arg, $root, 0), $node[2])).')',
            'agg'  => $node[1].'('
                .self::path($root, [...$node[2], $node[3], ...($node[4] === null ? [] : [$node[4]])])
                .($node[5] === null ? '' : ', '.self::node($node[5], '', 0))
                .')',
            // Brackets around a comparison, although the parser does not need them: "!order.locked == true"
            // parses as "not (locked == true)" here and reads as "(not locked) == true" to anybody who knows PHP.
            'not'   => '!'.self::node($node[1], $root, self::PRECEDENCE['cmp'] + 1),
            'and'   => self::node($node[1], $root, 2).' && '.self::node($node[2], $root, 2),
            'or'    => self::node($node[1], $root, 1).' || '.self::node($node[2], $root, 1),
            'cmp'   => self::node($node[2], $root, 5).' '.$node[1].' '.self::node($node[3], $root, 5),
            'in'    => self::node($node[1], $root, 5).($node[3] ? ' not in ' : ' in ').self::node($node[2], $root, 5),
            default => throw new LogicException('Unknown node "'.$node[0].'" in condition'),
        };

        $own = self::PRECEDENCE[$node[0] === 'math' ? $node[1] : $node[0]] ?? 9;

        return $own < $parent ? '('.$text.')' : $text;
    }

    /**
     * An empty root marks the filter of an aggregate, where bare names are columns of the related model.
     */
    private static function path(string $root, array $segments): string
    {
        return implode('.', $root === '' ? $segments : [$root, ...$segments]);
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null   => 'null',
            is_bool($value)   => $value ? 'true' : 'false',
            is_array($value)  => '['.implode(', ', array_map(self::value(...), $value)).']',
            is_string($value) => "'".addcslashes($value, "'\\")."'",
            default           => (string) $value,
        };
    }
}

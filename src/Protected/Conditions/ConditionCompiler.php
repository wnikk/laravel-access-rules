<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Protected\Conditions;

use Wnikk\LaravelAccessRules\Conditions\Cond;
use Wnikk\LaravelAccessRules\Exceptions\InvalidConditionException;
use Wnikk\LaravelAccessRules\Protected\Conditions\Syntax\Parser;
use Wnikk\LaravelAccessRules\Protected\Conditions\Syntax\Printer;

/**
 * Turns whatever a caller gives as a condition into a checked tree that is safe to store.
 *
 * A condition arrives as text, as a Cond object, or as a ready tree that an editor sends back.
 * All three meet here, in the conditions layer, and leave as one shape. Administration\Owners
 * and RuleCatalog call compile() before every write.
 *
 * Every mistake of a condition surfaces in this class, at the moment of saving: a typo in
 * a column path, a model missing from config, a relation used as a column. The alternative is
 * finding out during a permission check, where the only safe reaction is a refusal and the
 * author of the typo does not see it.
 *
 * The class does not evaluate anything. Evaluation\Evaluator and Evaluation\SqlCompiler read
 * the tree it produces.
 *
 * @internal Implementation of the package, not an entry point for applications. The public API is the facade Access, the traits and the classes outside src/Protected; AGENTS.md lists them.
 */
final class ConditionCompiler
{
    /**
     * Nesting limit of a tree. A condition written by a person rarely passes five levels; 24 leaves
     * room for generated ones and still keeps the recursive evaluator away from the stack limit.
     */
    private const MAX_DEPTH = 24;

    /**
     * Size limit of a stored tree as JSON. Trees travel inside cached permissions of every owner
     * that holds them, so one runaway condition would inflate thousands of cache entries.
     * 16 KB is about two hundred predicates.
     */
    private const MAX_BYTES = 16 * 1024;

    private const NODES = ['val', 'attr', 'call', 'is-author', 'res', 'agg', 'pivot', 'math', 'str', 'and', 'or', 'not', 'cmp', 'in'];

    public function __construct(private ResourceRegistry $resources) {}

    /**
     * @param string|Cond|array|null $when     Text, Cond, or a tree compiled before. A tree skips name resolution, because only this class could have produced it, but its shape is checked again: it may come from a browser.
     * @param string|null            $resource Alias of the model the rule is about. It lets a condition say "resource.cost" and tells which model "order.cost" means.
     *
     * @throws InvalidConditionException
     */
    public function compile(string|Cond|array|null $when, ?string $resource = null): ?array
    {
        if ($when === null || $when === '' || $when === []) {
            return null;
        }

        if (is_array($when)) {
            $tree = $when;
        } else {
            $raw   = is_string($when) ? Parser::parse($when) : $when->toRaw();
            $alias = $this->aliasUsed($raw) ?? $resource;
            $model = $alias === null ? null : $this->resources->model($alias);

            if ($alias !== null && $model === null) {
                throw new InvalidConditionException('Resource "'.$alias.'" is not listed in config access.resources');
            }

            $roots = $alias === null ? ['resource'] : ['resource', $alias];
            $tree  = (new Normalizer($this->resources, $model, $roots))->bool($raw);
        }

        $this->check($tree, 0);

        if (strlen(json_encode($tree, JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            throw new InvalidConditionException('Condition is too big');
        }

        return $tree;
    }

    public function describe(?array $tree, ?string $resource = null): ?string
    {
        return $tree === null ? null : Printer::print($tree, $resource ?? 'resource');
    }

    /**
     * Checks a stored condition against models and config as they are today.
     *
     * Conditions are checked when they are saved, and then the application moves on: a column gets
     * renamed, a relation removed, a model dropped from config. The stored tree keeps the old names
     * and fails later, as a refusal in memory or as an SQL error in a list.
     *
     * The check prints the tree back to text and compiles the text again. That is the whole
     * validation of a new condition, reused as it is, so lint cannot be more lenient or more strict
     * than saving. Columns are the one thing saving does not look at, because an accessor is
     * a legal attribute of a loaded record; lint reports them, since a list cannot filter by one.
     *
     * @return list<string> Problems, empty when the condition is fine.
     */
    public function lint(array $tree, ?string $resource = null): array
    {
        try {
            $this->check($tree, 0);
            $text = Printer::print($tree, $resource ?? 'resource');
            $this->compile($text, $resource);
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }

        $model = $resource === null ? null : $this->resources->model($resource);

        return $model === null ? [] : $this->missingColumns($tree, $model);
    }

    /**
     * Compiles a stored condition again when types of its columns have changed since it was saved.
     *
     * A comparison keeps the type of its column, see Normalizer::compared(). A migration that
     * turns a varchar into an integer leaves stored comparisons on "text", and records and lists
     * part ways again, which is what the stored type exists to prevent. "acr:lint" reports it
     * and "acr:lint --fix" saves what this method returns.
     *
     * @return array|null Null when the stored tree is up to date, or when it does not compile at all: lint() reports that.
     */
    public function retyped(array $tree, ?string $resource = null): ?array
    {
        try {
            $fresh = $this->compile(Printer::print($tree, $resource ?? 'resource'), $resource);
        } catch (\Throwable) {
            return null;
        }

        return $fresh !== null && self::types($fresh) !== self::types($tree) ? $fresh : null;
    }

    /**
     * Only the types are compared. Whole trees differ for harmless reasons: jsonb returns 100.0
     * as 100, and a tree saved before types existed has shorter nodes.
     *
     * @return list<string|null>
     */
    private static function types(array $node): array
    {
        $types = in_array($node[0], ['cmp', 'in'], true) ? [$node[4] ?? null] : [];

        foreach ($node[0] === 'call' ? $node[2] : array_slice($node, 1) as $child) {
            if (is_array($child) && isset($child[0]) && is_string($child[0]) && $child[0] !== 'val') {
                $types = [...$types, ...self::types($child)];
            }
        }

        return $types;
    }

    /**
     * @return list<string>
     */
    private function missingColumns(array $node, string $model): array
    {
        $problems = [];

        if ($node[0] === 'res' || $node[0] === 'agg') {
            $chain = $node[0] === 'res' ? $node[1] : [...$node[2], $node[3]];
            foreach ($chain as $name) {
                $relation = $this->resources->relation($model, $name);
                if ($relation === null) {
                    // compile() has already reported a relation that is gone.
                    return [];
                }
                $model = $relation->getRelated()::class;
            }

            $column = $node[0] === 'res' ? $node[2] : $node[4];
            $table  = (new $model)->getTable();

            // A pivot column is checked when the condition compiles: the relation has to list it in withPivot().
            if ($column !== null && ! str_starts_with($column, 'pivot.') && ! (new $model)->getConnection()->getSchemaBuilder()->hasColumn($table, $column)) {
                $problems[] = '"'.$column.'" is not a column of table "'.$table.'": a loaded record may still have it as an accessor, a list cannot filter by it';
            }

            // The filter of an aggregate talks about the related model.
            return $node[0] === 'agg' && $node[5] !== null ? [...$problems, ...$this->missingColumns($node[5], $model)] : $problems;
        }

        foreach ($node[0] === 'call' ? $node[2] : array_slice($node, 1) as $child) {
            if (is_array($child) && isset($child[0]) && is_string($child[0]) && $child[0] !== 'val') {
                $problems = [...$problems, ...$this->missingColumns($child, $model)];
            }
        }

        return $problems;
    }

    /**
     * Tells whether a condition reads the record. Permissions stores the answer next to each
     * condition, so a check without a record skips such entries without walking their trees,
     * and SqlCompiler uses it to find the parts it can compute in advance.
     */
    public static function needsRecord(?array $node): bool
    {
        if ($node === null) {
            return false;
        }
        if (in_array($node[0], ['res', 'agg', 'pivot', 'is-author'], true)) {
            return true;
        }
        if ($node[0] === 'val') {
            return false;
        }

        // Arguments of a function sit one level deeper than children of other nodes. Missing them
        // made "half(order.cost) > 100" look constant, and the query compiler folded it away.
        $children = $node[0] === 'call' ? $node[2] : array_slice($node, 1);

        foreach ($children as $child) {
            if (is_array($child) && isset($child[0]) && is_string($child[0]) && self::needsRecord($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Finds which model the text talks about, from its roots: "order.cost > 100" means "order".
     * One condition describes one record, so two aliases in one text are a mistake, not a join.
     */
    private function aliasUsed(array $raw): ?string
    {
        $aliases = array_keys($this->resources->all());
        $found   = [];

        $scan = function (array $node) use (&$scan, &$found, $aliases) {
            if ($node[0] === 'path') {
                $root = explode('.', $node[1])[0];
                if (in_array($root, $aliases, true)) {
                    $found[$root] = true;
                }

                return;
            }
            // Inside the filter of an aggregate bare names are columns of the related model. A column
            // named like an alias, "order" in a filter over items, must not count as a second record.
            if ($node[0] === 'call' && in_array($node[1], Normalizer::AGGREGATES, true)) {
                if (isset($node[2][0])) {
                    $scan($node[2][0]);
                }

                return;
            }
            foreach ($node as $child) {
                if (is_array($child) && isset($child[0]) && is_string($child[0])) {
                    $scan($child);
                } elseif (is_array($child)) {
                    foreach ($child as $item) {
                        if (is_array($item) && isset($item[0]) && is_string($item[0])) {
                            $scan($item);
                        }
                    }
                }
            }
        };
        $scan($raw);

        if (count($found) > 1) {
            throw new InvalidConditionException('Condition refers to several resources: '.implode(', ', array_keys($found)));
        }

        return array_key_first($found);
    }

    /**
     * Checks the shape of a tree: known node types, known operators, names that are plain
     * identifiers. Names are the part that reaches SQL as text, so a tree edited by hand must
     * not be able to carry "cost; drop table orders" in a column position.
     */
    private function check(mixed $node, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidConditionException('Condition is nested too deep');
        }

        if (! is_array($node) || ! isset($node[0]) || ! in_array($node[0], self::NODES, true)) {
            throw new InvalidConditionException('Broken condition tree');
        }

        $children = match ($node[0]) {
            'and', 'or' => [$node[1] ?? null, $node[2] ?? null],
            'not'       => [$node[1] ?? null],
            'cmp'       => [$node[2] ?? null, $node[3] ?? null],
            'in'        => [$node[1] ?? null, $node[2] ?? null],
            'math'      => [$node[2] ?? null, $node[3] ?? null],
            'str'       => ($node[3] ?? null) === null ? [$node[2] ?? null] : [$node[2] ?? null, $node[3]],
            'call'      => $node[2] ?? [],
            'agg'       => ($node[5] ?? null) === null ? [] : [$node[5]],
            default     => [],
        };

        if ($node[0] === 'cmp' && ! in_array($node[1] ?? null, ['==', '!=', '>', '>=', '<', '<='], true)) {
            throw new InvalidConditionException('Unknown comparison in condition');
        }
        if (in_array($node[0], ['cmp', 'in'], true) && ! in_array($node[4] ?? null, [null, 'num', 'text'], true)) {
            throw new InvalidConditionException('Unknown type of comparison in condition');
        }
        if ($node[0] === 'math' && ! in_array($node[1] ?? null, ['+', '-', '*', '/'], true)) {
            throw new InvalidConditionException('Unknown arithmetic operation in condition');
        }
        if ($node[0] === 'str' && ! in_array($node[1] ?? null, Normalizer::STRINGS, true)) {
            throw new InvalidConditionException('Unknown text function in condition');
        }
        if ($node[0] === 'agg' && ! in_array($node[1] ?? null, Normalizer::AGGREGATES, true)) {
            throw new InvalidConditionException('Unknown aggregate in condition');
        }
        if (in_array($node[0], ['res', 'agg', 'pivot'], true)) {
            $names = match ($node[0]) {
                'res'   => [...$node[1], $node[2]],
                'pivot' => [$node[1] ?? null],
                // "pivot.quantity" in the column position is the one dotted name; both halves are checked.
                'agg' => [...$node[2], $node[3], ...($node[4] === null ? [] : explode('.', (string) preg_replace('/^pivot\./', '', $node[4], 1)))],
            };
            foreach ($names as $name) {
                if (! is_string($name) || ! preg_match('/^[A-Za-z_]\w*$/', $name)) {
                    throw new InvalidConditionException('Bad name in condition');
                }
            }
        }

        foreach ($children as $child) {
            $this->check($child, $depth + 1);
        }
    }
}

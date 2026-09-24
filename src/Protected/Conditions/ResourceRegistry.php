<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Protected\Conditions;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Knows which models conditions may touch and which of their methods are relations.
 *
 * A condition is text that may come from an admin panel, and its paths name methods of
 * models. Without a gate, "order.delete.id" would make the package call Order::delete().
 * This class of the conditions layer is that gate, and Normalizer asks it for every segment.
 *
 * The list of models comes from config access.resources and is a whitelist: a relation that
 * leads to a model outside of the list is refused, so a condition on orders cannot read
 * salaries through a chain of relations the project did not list.
 *
 * @internal Implementation of the package, not an entry point for applications. The public API is the facade Access, the traits and the classes outside src/Protected; AGENTS.md lists them.
 */
final class ResourceRegistry
{
    /** @var array<string, Relation|false> */
    private array $relations = [];

    /** @var array<string, array<string, 'num'|'text'|null>> By "connection.table". */
    private array $columns = [];

    /**
     * @return array<string, class-string<Model>> alias => class
     */
    public function all(): array
    {
        $all = [];
        foreach (config('access.resources') ?? [] as $alias => $definition) {
            $all[(string) $alias] = is_array($definition) ? $definition['model'] : $definition;
        }

        return $all;
    }

    /**
     * @return class-string<Model>|null
     */
    public function model(string $alias): ?string
    {
        return $this->all()[$alias] ?? null;
    }

    public function alias(string $class): ?string
    {
        return array_search($class, $this->all(), true) ?: null;
    }

    public function allows(string $class): bool
    {
        return in_array($class, $this->all(), true);
    }

    /**
     * A method counts as a relation only when it is public, takes no required arguments and
     * declares a Relation as its return type, or when config lists it for the model. Reflection
     * reads the return type, so finding out what a method is never calls it. save() and delete()
     * fail the test and stay out of reach.
     *
     * Results are remembered per class and name. Reflection plus building a relation costs tens
     * of microseconds, and the singleton keeps the answer for the life of the process.
     *
     * @return Relation|null Null means "not a relation". The caller then treats the word as a column.
     */
    public function relation(string $class, string $name): ?Relation
    {
        $key = $class.'::'.$name;
        if (isset($this->relations[$key])) {
            return $this->relations[$key] ?: null;
        }

        $relation = false;

        if (method_exists($class, $name)) {
            $method = new ReflectionMethod($class, $name);
            $type   = $method->getReturnType();

            $declared = $type instanceof ReflectionNamedType && is_a($type->getName(), Relation::class, true);
            $listed   = in_array($name, $this->listedRelations($class), true);

            if ($method->isPublic() && ! $method->isStatic() && $method->getNumberOfRequiredParameters() === 0 && ($declared || $listed)) {
                $made = Relation::noConstraints(static fn () => (new $class)->$name());
                if ($made instanceof Relation) {
                    $relation = $made;
                }
            }
        }

        $this->relations[$key] = $relation;

        return $relation ?: null;
    }

    /**
     * Tells how the database compares a column: as a number, as text, or in a way the package
     * does not interfere with (dates, booleans, json, uuid).
     *
     * The question exists because of one reader. The database knows its types and compares
     * "01234" with "1234" as text. PHP sees two strings that look like numbers and compares them
     * as numbers, and it cannot tell a varchar from a decimal either: both arrive from PDO as
     * strings. The query builder passes values to PDO and knows nothing of columns.
     * Casts of the model do not help either: projects declare no cast for a plain varchar. So the schema
     * is asked, once per table and only while a condition is saved or linted.
     *
     * @return 'num'|'text'|null Null also for an accessor, a missing table or a database that does not answer. Comparisons then work as PHP and the database each see fit.
     */
    public function columnType(string $class, string $column): ?string
    {
        try {
            $model = new $class;

            return $this->typeIn($model->getConnection(), $model->getTable(), $column);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A pivot table has no model to ask, so the relation names the table.
     *
     * @return 'num'|'text'|null
     */
    public function pivotColumnType(BelongsToMany $relation, string $column): ?string
    {
        return $this->typeIn($relation->getParent()->getConnection(), $relation->getTable(), $column);
    }

    private function typeIn(Connection $connection, string $table, string $column): ?string
    {
        $key = $connection->getName().'.'.$table;

        if (! isset($this->columns[$key])) {
            $this->columns[$key] = [];

            try {
                foreach ($connection->getSchemaBuilder()->getColumns($table) as $definition) {
                    $this->columns[$key][$definition['name']] = self::typeClass(strtolower((string) $definition['type_name']));
                }
            } catch (\Throwable) {
                // Rules are often seeded by a migration that runs before the table of the model exists.
            }
        }

        return $this->columns[$key][$column] ?? null;
    }

    /**
     * Names are matched whole. "int" as a substring also sits in "interval" and "point".
     */
    private static function typeClass(string $name): ?string
    {
        if (preg_match('/^((tiny|small|medium|big)?int(eger)?|int[248]|(small|big)?serial|numeric|decimal|dec|float[48]?|double( precision)?|real|number|money)( unsigned)?$/', $name)) {
            return 'num';
        }

        return preg_match('/char|text|string|^enum$|^name$/', $name) ? 'text' : null;
    }

    /**
     * @internal Tests change tables between two compilations of one process.
     */
    public function forgetColumns(): void
    {
        $this->columns = [];
    }

    /**
     * Listed by class because Eloquent has no common parent for "leads to one record".
     * BelongsToMany, HasMany, MorphMany, HasManyThrough and the rest count as "many".
     */
    public static function isToOne(Relation $relation): bool
    {
        return $relation instanceof BelongsTo
            || $relation instanceof HasOne
            || $relation instanceof MorphOne
            || $relation instanceof HasOneThrough;
    }

    private function listedRelations(string $class): array
    {
        foreach (config('access.resources') ?? [] as $definition) {
            if (is_array($definition) && ($definition['model'] ?? null) === $class) {
                return $definition['relations'] ?? [];
            }
        }

        return [];
    }
}

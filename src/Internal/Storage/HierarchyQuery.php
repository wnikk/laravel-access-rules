<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Internal\Storage;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Throwable;

/**
 * Walks a parent-child table to any depth: owners an owner inherits from, heirs of an owner,
 * rules below a rule.
 *
 * All three lookups are the same walk over rows that lead from one column to another, so one
 * class of the storage layer serves them. Administration\Owners and Authorization\Permissions
 * call it and pass the table and the two columns.
 *
 * The server decides how the walk runs. WITH RECURSIVE answers in one query at any depth, and
 * where the server lacks it the class reads one level per query, as version 2 did. On a local
 * PostgreSQL a level costs about 0.11 ms, so twenty levels of roles cost 2.2 ms against 0.15 ms.
 *
 * The two ways live in one class on purpose. An interface with two strategy classes was tried
 * and removed: no project swaps the strategy, and four files carried one decision.
 *
 * The class is open for extension because the fallback test replaces byRecursiveQuery() with a
 * method that throws.
 *
 * @internal Not part of the public API, it may change in any release. AGENTS.md lists what an application may rely on.
 */
class HierarchyQuery
{
    /**
     * Whether WITH RECURSIVE works, by connection name. It is a fact about the server, so the
     * provider registers this class as a singleton and the answer outlives requests.
     *
     * @var array<string, bool>
     */
    private array $recursive = [];

    /**
     * @param string                          $mode       "auto" asks the server, "cte" and "loop" force one way. Forcing exists for servers that report a wrong version.
     * @param (Closure(Throwable): void)|null $onFallback Runs once per connection when "auto" gives up on WITH RECURSIVE, so the log names the cause.
     * @param int                             $chunk      Ids per "in (...)" list of the loop. Five hundred stays below the 999 parameters that old SQLite builds allow.
     */
    public function __construct(
        private string $mode = 'auto',
        private ?Closure $onFallback = null,
        private int $chunk = 500,
    ) {}

    /**
     * Ids reachable from $start through one or more rows. A start id appears in the result only
     * when a cycle leads back to it, so callers add the start themselves when they need it.
     *
     * @param  list<int|string> $start
     * @return list<int|string>
     *
     * @throws QueryException Only in mode "cte". In "auto" a refused query switches the connection to the loop for the life of the process.
     */
    public function reachable(Connection $db, string $table, string $from, string $to, array $start, ?string $softDeleteColumn = null): array
    {
        // Names go into raw SQL. They come from config of the package, never from a request, and
        // the check keeps it that way if somebody later passes user input here.
        foreach ([$table, $from, $to, $softDeleteColumn] as $name) {
            if ($name !== null && ! preg_match('/^[A-Za-z_][\w.]*$/', $name)) {
                throw new InvalidArgumentException('Bad identifier "'.$name.'"');
            }
        }

        if ($start === []) {
            return [];
        }

        if (! $this->usesRecursiveQuery($db)) {
            return $this->byLevels($db, $table, $from, $to, $start, $softDeleteColumn);
        }

        try {
            return $this->byRecursiveQuery($db, $table, $from, $to, $start, $softDeleteColumn);
        } catch (QueryException $e) {
            // Whoever forces "cte" wants to see why it fails.
            if ($this->mode === 'cte') {
                throw $e;
            }

            $this->recursive[(string) $db->getName()] = false;
            if ($this->onFallback) {
                ($this->onFallback)($e);
            }

            return $this->byLevels($db, $table, $from, $to, $start, $softDeleteColumn);
        }
    }

    public function usesRecursiveQuery(Connection $db): bool
    {
        return match ($this->mode) {
            'loop'  => false,
            'cte'   => true,
            default => $this->recursive[(string) $db->getName()] ??= static::supportsRecursiveQuery($db),
        };
    }

    /**
     * Versions are the first releases that accept WITH RECURSIVE: SQLite 3.8.3, MariaDB 10.2.2,
     * MySQL 8.0.1. PostgreSQL has it since 8.4, older than anything Laravel 13 connects to.
     */
    public static function supportsRecursiveQuery(Connection $db): bool
    {
        try {
            return match ($db->getDriverName()) {
                'pgsql'   => true,
                'sqlite'  => version_compare($db->getServerVersion(), '3.8.3', '>='),
                'mariadb' => version_compare($db->getServerVersion(), '10.2.2', '>='),
                'mysql'   => $db instanceof MySqlConnection && $db->isMaria()
                    // MariaDB behind the "mysql" driver reports "5.5.5-10.6.12-MariaDB-log".
                    ? version_compare(preg_replace('/^.*?(\d+\.\d+\.\d+)-MariaDB.*$/i', '$1', $db->getServerVersion()), '10.2.2', '>=')
                    : version_compare($db->getServerVersion(), '8.0.1', '>='),
                // SQL Server has recursive queries, but only with UNION ALL. Duplicates stay, a cycle
                // in data runs until MAXRECURSION (100) and the query fails. Unknown drivers get
                // the loop as well: it works everywhere.
                default => false,
            };
        } catch (Throwable) {
            // The version needs a live connection. If it cannot be asked, the loop is the safe answer.
            return false;
        }
    }

    /**
     * UNION, not UNION ALL. UNION drops rows it has already produced, and that is what ends a
     * cycle in data: the second pass through a loop adds nothing new and the recursion stops.
     *
     * The query has no depth column for the same reason. A depth makes every row unique, UNION
     * stops dropping anything, and a cycle runs until the server gives up.
     */
    protected function byRecursiveQuery(Connection $db, string $table, string $from, string $to, array $start, ?string $softDeleteColumn): array
    {
        $grammar = $db->getQueryGrammar();
        $table   = $grammar->wrapTable($table);
        $from    = $grammar->wrap($from);
        $to      = $grammar->wrap($to);
        $alive   = $softDeleteColumn ? ' and e.'.$grammar->wrap($softDeleteColumn).' is null' : '';
        $marks   = implode(', ', array_fill(0, count($start), '?'));

        $sql = 'with recursive acr_tree (id) as ('
            ." select e.$to from $table e where e.$from in ($marks)$alive"
            .' union'
            ." select e.$to from $table e inner join acr_tree t on e.$from = t.id where e.$to is not null$alive"
            .') select id from acr_tree where id is not null';

        return array_map(static fn ($row) => $row->id, $db->select($sql, array_values($start)));
    }

    /**
     * A level holds only ids that no earlier level has seen, so the loop ends on any data,
     * cycles included. Version 2 stopped after 100 levels and returned a partial list without
     * an error; this loop has no limit to hit.
     */
    protected function byLevels(Connection $db, string $table, string $from, string $to, array $start, ?string $softDeleteColumn): array
    {
        $seen  = [];
        $level = array_values($start);

        while ($level !== []) {
            $next = [];
            foreach (array_chunk($level, $this->chunk) as $ids) {
                $query = $db->table($table)->whereIn($from, $ids)->whereNotNull($to);
                if ($softDeleteColumn) {
                    $query->whereNull($softDeleteColumn);
                }

                foreach ($query->pluck($to) as $id) {
                    if (! isset($seen[$id])) {
                        $seen[$id] = $id;
                        $next[]    = $id;
                    }
                }
            }
            $level = $next;
        }

        return array_values($seen);
    }
}

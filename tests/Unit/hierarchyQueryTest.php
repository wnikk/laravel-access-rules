<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\MariaDbGrammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Wnikk\LaravelAccessRules\Storage\HierarchyQuery;

/**
 * Inherited owners can be collected by one WITH RECURSIVE query or level by level, and both
 * ways have to give the same result on any data.
 *
 * "Any data" includes what nobody plans for: loops, diamonds, owners that inherit from
 * themselves, links to nothing. The tables of version 2 forbid none of it, and a walk that
 * hangs on a loop takes every permission check of that owner down with it.
 */
class hierarchyQueryTest extends TestCase
{
    private const UP = ['poc_inheritance', 'owner_id', 'owner_parent_id'];

    private const DOWN = ['poc_inheritance', 'owner_parent_id', 'owner_id'];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('poc_inheritance', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('owner_id');
            $t->integer('owner_parent_id')->nullable();
        });
        Schema::create('poc_rules', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('parent_id')->default(0);
            $t->dateTime('deleted_at')->nullable();
        });
    }

    private function edges(array $pairs): void
    {
        DB::table('poc_inheritance')->insert(array_map(fn ($p) => ['owner_id' => $p[0], 'owner_parent_id' => $p[1]], $pairs));
    }

    /**
     * A third implementation, in plain PHP over the list of pairs. Comparing the two ways of the
     * class only with each other would let them be wrong together.
     */
    private static function reference(array $pairs, array $start, bool $reverse): array
    {
        $seen  = [];
        $level = $start;
        while ($level) {
            $next = [];
            foreach ($pairs as [$from, $to]) {
                if ($reverse) {
                    [$from, $to] = [$to, $from];
                }
                if ($to !== null && in_array($from, $level, true) && ! isset($seen[$to])) {
                    $seen[$to] = $to;
                    $next[]    = $to;
                }
            }
            $level = $next;
        }
        sort($seen);

        return array_values($seen);
    }

    private function reach(HierarchyQuery $query, array $edge, array $start, ?string $softDelete = null): array
    {
        $ids = array_map('intval', $query->reachable(DB::connection(), $edge[0], $edge[1], $edge[2], $start, $softDelete));
        sort($ids);

        return $ids;
    }

    /**
     * Twenty five random graphs from a fixed seed, so a failure repeats on the next run. About one
     * link in twenty points at NULL, and the ranges of ids overlap enough to make loops common.
     */
    public function test_both_ways_agree_on_random_graphs_with_cycles_diamonds_and_self_loops(): void
    {
        mt_srand(20260918);

        for ($round = 0; $round < 25; $round++) {
            DB::table('poc_inheritance')->delete();
            $nodes = mt_rand(5, 40);
            $pairs = [];
            for ($i = 0, $n = mt_rand($nodes, $nodes * 3); $i < $n; $i++) {
                $pairs[] = [mt_rand(1, $nodes), mt_rand(0, 20) === 0 ? null : mt_rand(1, $nodes)];
            }
            $this->edges($pairs);

            foreach ([[1], [2, 3], [mt_rand(1, $nodes)], [999], []] as $start) {
                foreach ([[self::UP, false], [self::DOWN, true]] as [$edge, $reverse]) {
                    $expected = self::reference($pairs, $start, $reverse);

                    $this->assertSame($expected, $this->reach(new HierarchyQuery('cte'), $edge, $start), "recursive query, round $round");
                    $this->assertSame($expected, $this->reach(new HierarchyQuery('loop'), $edge, $start), "levels, round $round");
                    $this->assertSame($expected, $this->reach(new HierarchyQuery('loop', chunk: 2), $edge, $start), "levels in chunks, round $round");
                }
            }
        }
    }

    public function test_number_of_queries_on_deep_chain(): void
    {
        foreach ([5, 50, 150] as $depth) {
            DB::table('poc_inheritance')->delete();
            $this->edges(array_map(fn ($i) => [$i, $i + 1], range(1, $depth)));

            foreach (['cte' => 1, 'loop' => $depth + 1] as $mode => $queries) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $ids = (new HierarchyQuery($mode))->reachable(DB::connection(), ...self::UP, start: [1]);
                DB::disableQueryLog();

                // Depth 150 is past the 100 levels where version 2 stopped and returned a partial list.
                $this->assertCount($depth, $ids);
                $this->assertCount($queries, DB::getQueryLog(), "$mode, depth $depth");
            }
        }
    }

    public function test_rules_below_a_rule_skip_soft_deleted_branches(): void
    {
        // Rule 1 has children 2 and 3, rule 2 has child 4, rule 3 has child 5. Rule 3 is deleted,
        // so 5 has to disappear with it although 5 itself is alive.
        DB::table('poc_rules')->insert([
            ['id' => 1, 'parent_id' => 0, 'deleted_at' => null], ['id'                  => 2, 'parent_id' => 1, 'deleted_at' => null],
            ['id' => 3, 'parent_id' => 1, 'deleted_at' => '2026-01-01 00:00:00'], ['id' => 4, 'parent_id' => 2, 'deleted_at' => null],
            ['id' => 5, 'parent_id' => 3, 'deleted_at' => null],
        ]);

        $edge = ['poc_rules', 'parent_id', 'id'];

        $this->assertSame([2, 4], $this->reach(new HierarchyQuery('cte'), $edge, [1], 'deleted_at'));
        $this->assertSame([2, 4], $this->reach(new HierarchyQuery('loop'), $edge, [1], 'deleted_at'));
    }

    public function test_way_is_chosen_by_driver_and_version_of_server(): void
    {
        $fake = fn (string $driver, string $version) => new class($driver, $version) extends Connection
        {
            public function __construct(private string $driver, private string $version)
            {
                parent::__construct(fn () => null, 'db');
            }

            public function getDriverName()
            {
                return $this->driver;
            }

            public function getServerVersion(): string
            {
                return $this->version;
            }
        };

        foreach ([
            ['sqlite', '3.45.2', true], ['sqlite', '3.7.17', false],
            ['pgsql', '16.2', true],
            ['mysql', '8.0.36', true], ['mysql', '5.7.44', false],
            ['mariadb', '10.6.12', true], ['mariadb', '10.1.48', false],
            ['sqlsrv', '16.0.1000', false],
            ['firebird', '1.0', false],
        ] as [$driver, $version, $expected]) {
            $this->assertSame($expected, HierarchyQuery::supportsRecursiveQuery($fake($driver, $version)), "$driver $version");
        }

        $this->assertTrue((new HierarchyQuery)->usesRecursiveQuery(DB::connection()));
        $this->assertFalse((new HierarchyQuery('loop'))->usesRecursiveQuery(DB::connection()));
    }

    public function test_auto_mode_falls_back_to_levels_once_and_remembers(): void
    {
        $this->edges([[1, 2], [2, 3]]);

        $warnings = 0;
        $query    = new class('auto', function () use (&$warnings) {
            $warnings++;
        }) extends HierarchyQuery
        {

            public int $attempts = 0;

            protected function byRecursiveQuery(Connection $db, string $table, string $from, string $to, array $start, ?string $softDeleteColumn): array
            {
                $this->attempts++;

                throw new QueryException((string) $db->getName(), 'with recursive ...', [], new \RuntimeException('syntax error near RECURSIVE'));
            }
        };

        $this->assertSame([2, 3], $this->reach($query, self::UP, [1]));
        $this->assertSame([2, 3], $this->reach($query, self::UP, [1]));
        $this->assertSame(1, $query->attempts, 'WITH RECURSIVE must be tried only once per connection');
        $this->assertSame(1, $warnings);
    }

    public function test_forced_recursive_query_does_not_hide_the_error(): void
    {
        $query = new class('cte') extends HierarchyQuery
        {
            protected function byRecursiveQuery(Connection $db, string $table, string $from, string $to, array $start, ?string $softDeleteColumn): array
            {
                throw new QueryException((string) $db->getName(), 'with recursive ...', [], new \RuntimeException('syntax error'));
            }
        };

        $this->expectException(QueryException::class);
        $query->reachable(DB::connection(), ...self::UP, start: [1]);
    }

    public function test_names_come_wrapped_with_prefix_and_values_are_bound(): void
    {
        foreach ([MySqlGrammar::class, MariaDbGrammar::class, PostgresGrammar::class] as $grammar) {
            $db = new class(fn () => null, 'db', 'pre_') extends Connection
            {
                public array $captured = [];

                public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
                {
                    $this->captured = [$query, $bindings];

                    return [];
                }
            };
            $db->setQueryGrammar(new $grammar($db));

            (new HierarchyQuery('cte'))->reachable($db, 'access_rules_inheritance', 'owner_id', 'owner_parent_id', [42]);

            $this->assertStringContainsString('with recursive', $db->captured[0]);
            $this->assertStringContainsString('pre_access_rules_inheritance', $db->captured[0]);
            $this->assertSame([42], $db->captured[1]);
        }

        $this->expectException(\InvalidArgumentException::class);
        (new HierarchyQuery)->reachable(DB::connection(), 'inheritance; drop table users', 'a', 'b', [1]);
    }
}

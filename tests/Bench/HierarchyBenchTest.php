<?php

declare(strict_types=1);

namespace Tests\Bench;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Wnikk\LaravelAccessRules\Internal\Storage\HierarchyQuery;

/**
 * Compares WITH RECURSIVE with the level by level loop on the database the suite points at.
 *
 * On SQLite in memory a query costs 0.03 ms and the difference looks small. Point the suite
 * at a real server: on a local PostgreSQL every level costs about 0.11 ms, and over a network
 * several times that.
 */
class HierarchyBenchTest extends TestCase
{
    public function test_bench(): void
    {
        Schema::create('bench_inheritance', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('owner_id')->index();
            $t->integer('owner_parent_id');
        });
        $edge = ['bench_inheritance', 'owner_id', 'owner_parent_id'];
        $db   = DB::connection();

        $out = sprintf("\n%s %s\n depth | CTE: queries / ms | loop: queries / ms\n", $db->getDriverName(), $db->getServerVersion());
        foreach ([3, 5, 10, 20, 50] as $depth) {
            DB::table('bench_inheritance')->delete();
            DB::table('bench_inheritance')->insert(array_map(fn ($i) => ['owner_id' => $i, 'owner_parent_id' => $i + 1], range(1, $depth)));

            $row = [];
            foreach ([new HierarchyQuery('cte'), new HierarchyQuery('loop')] as $strategy) {
                $strategy->reachable($db, ...$edge, start: [1]);             // warm up
                $runs = 30;
                $t    = hrtime(true);
                for ($i = 0; $i < $runs; $i++) {
                    $ids = $strategy->reachable($db, ...$edge, start: [1]);
                }
                $row[] = (hrtime(true) - $t) / 1e6 / $runs;
                $this->assertCount($depth, $ids);
            }
            $out .= sprintf(" %5d | %8d / %6.3f | %9d / %6.3f\n", $depth, 1, $row[0], $depth + 1, $row[1]);
        }
        fwrite(STDERR, $out);
    }
}

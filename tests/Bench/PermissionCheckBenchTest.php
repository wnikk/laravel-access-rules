<?php

declare(strict_types=1);

namespace Tests\Bench;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Shop\Order;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;
use Tests\TestCase;

/**
 * Measures a permission check through real Laravel Gate, cold and warm.
 *
 * The scenario is the one version 2.3.0 was measured with, so numbers compare: 200 rules,
 * a chain of 5 roles with 40 permissions each, a user that inherits the last one. Version 2.3.0
 * gave 12 queries and 9.2 ms for the first check, and 62 microseconds plus one query for every
 * next one, on SQLite in memory.
 *
 * It asserts only that warm checks make no queries. Times are printed, not asserted: they
 * depend on the machine, and a benchmark that fails on a busy laptop gets deleted.
 */
class PermissionCheckBenchTest extends TestCase
{
    public function test_bench(): void
    {
        Config::set('access.owner_types', [TestUser::class, 'Role']);
        ShopSchema::create();
        ShopSchema::seed();

        $acr = $this->getAccessRules();
        for ($i = 0; $i < 200; $i++) {
            $acr->newRule("module$i.action");
        }
        $acr->newRule('orders.view', resource: 'order');

        $previous = null;
        $n        = 0;
        for ($r = 0; $r < 5; $r++) {
            $role = $this->getAccessRules();
            $role->newOwner('Role', "role$r");
            for ($k = 0; $k < 40; $k++) {
                $role->addPermission("module$n.action");
                $n++;
            }
            if ($previous) {
                $role->inheritFrom($previous);
            }
            $previous = $role;
        }
        $user = TestUser::factory()->make()->forceFill(['id' => 7]);
        $user->inheritPermissionFrom($previous);
        $user->addPermission('orders.view', when: "order.cost > 100 && order.status in ['draft','review'] && order.testuser_id == user.id && order.min_level <= 5 && not order.locked");

        $this->app->forgetScopedInstances();
        $order = Order::find(1);

        DB::enableQueryLog();
        $t = hrtime(true);
        $user->can('module199.action');
        $cold        = (hrtime(true) - $t) / 1e6;
        $coldQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        $bench = function (callable $f, int $n = 100000) {
            $t = hrtime(true);
            for ($i = 0; $i < $n; $i++) {
                $f();
            }

            return (hrtime(true) - $t) / $n / 1000;
        };

        $hit         = $bench(fn () => $user->can('module199.action'));
        $miss        = $bench(fn () => $user->can('nonexistent.rule'));
        $cond        = $bench(fn () => $user->can('orders.view', $order));
        $warmQueries = count(DB::getQueryLog());

        fwrite(STDERR, sprintf("\n3.0: cold first can(): %d queries, %.2f ms | warm can(): hit %.2f us, miss %.2f us, condition(5 predicates) %.2f us | queries on warm path: %d\n",
            $coldQueries, $cold, $hit, $miss, $cond, $warmQueries));
        $this->assertSame(0, $warmQueries);
    }
}

<?php

namespace Tests\Unit;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Shop\Order;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;
use Tests\TestCase;

/**
 * Speed of the package, written down as numbers of queries.
 *
 * Microseconds depend on the machine and make flaky tests, queries do not. A query is also
 * what dominates: one costs as much as a hundred permission checks in memory. So the contract
 * counts queries, and tests/Bench measures time for people to read.
 *
 * Version 2 made one query on every check, even with a warm cache. The numbers here are what
 * keeps that from coming back unnoticed.
 */
class performanceContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [TestUser::class, 'Role']);

        Schema::create('test_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    private function queries(callable $do): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $do();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * A user at the end of a chain of roles, each with a permission of its own. Depth is the
     * variable: collecting inherited owners level by level costs a query per level.
     */
    private function userBehindRoles(int $depth): TestUser
    {
        $acr      = $this->getAccessRules();
        $previous = null;

        for ($i = 0; $i < $depth; $i++) {
            $acr->newRule('level'.$i.'.view');

            $role = $this->getAccessRules();
            $role->newOwner('Role', 'role'.$i);
            $role->addPermission('level'.$i.'.view');
            if ($previous) {
                $role->inheritFrom($previous);
            }
            $previous = $role;
        }

        $user = TestUser::create(['name' => 'Deep', 'email' => 'deep@example.com']);
        $user->inheritPermissionFrom($previous);

        return $user;
    }

    public function test_building_permissions_takes_the_same_few_queries_for_any_depth(): void
    {
        $user = $this->userBehindRoles(12);
        $this->app->forgetScopedInstances();
        Config::set('access.cache.enabled', false);

        $queries = $this->queries(function () use ($user) {
            $this->assertTrue($user->hasPermission('level0.view'));
        });

        // Three at depth 12, the same as at depth 1: the owner, its ancestors in one recursive query,
        // permissions joined with rules. A fourth would mean something started asking per role.
        $this->assertSame(3, $queries);
    }

    public function test_checks_after_the_first_one_do_not_touch_the_database(): void
    {
        $user = $this->userBehindRoles(3);
        $user->hasPermission('level0.view');

        $queries = $this->queries(function () use ($user) {
            for ($i = 0; $i < 50; $i++) {
                $user->can('level0.view');
                $user->can('level2.view');
                $user->can('missing.rule');
            }
            TestUser::find($user->id)->can('level1.view');   // another instance of the same user
        });

        $this->assertSame(1, $queries, 'only the query that loads the user');
    }

    public function test_next_request_takes_permissions_from_cache_without_database(): void
    {
        $user = $this->userBehindRoles(3);
        $user->hasPermission('level0.view');

        $this->app->forgetScopedInstances();

        $this->assertSame(0, $this->queries(fn () => $this->assertTrue($user->can('level0.view'))));
    }

    public function test_condition_over_loaded_record_needs_no_queries(): void
    {
        ShopSchema::create();
        ShopSchema::seed();

        $this->getAccessRules()->newRule('orders.view', resource: 'order');
        $user = TestUser::create(['name' => 'Seven', 'email' => 'seven@example.com']);
        $user->addPermission('orders.view', when: "order.cost > 100 && order.status in ['draft', 'review'] && order.client.city == 'X' && order.items.count < 3");
        $user->hasPermission('orders.view');

        $orders = Order::with(['client', 'items'])->get();

        $queries = $this->queries(function () use ($user, $orders) {
            $this->assertSame([1, 4], $orders->filter(fn ($order) => $user->can('orders.view', $order))->pluck('id')->values()->all());
        });

        $this->assertSame(0, $queries);
    }

    public function test_loading_models_with_the_trait_costs_nothing(): void
    {
        for ($i = 0; $i < 20; $i++) {
            TestUser::create(['name' => 'U'.$i, 'email' => $i.'@example.com']);
        }

        $listeners = fn () => count($this->app['events']->getListeners('eloquent.retrieved: '.TestUser::class));
        $before    = $listeners();

        $this->assertSame(1, $this->queries(fn () => TestUser::all()));
        $this->assertSame($before, $listeners());
        $this->assertSame(0, $before, 'nothing of the package listens to loading of models');
    }

    public function test_soft_deleted_user_keeps_permissions_until_it_is_deleted_for_real(): void
    {
        $this->getAccessRules()->newRule('keep.me');

        $user = new class extends TestUser
        {
            use SoftDeletes;

            protected $table = 'test_users';
        };
        Config::set('access.owner_types', [$user::class]);

        $user = $user::create(['name' => 'Soft', 'email' => 'soft@example.com']);
        $user->addPermission('keep.me');

        $user->delete();
        $this->assertNotNull($this->getAccessRules()->setOwner($user)->getOwner());

        $user->restore();
        $this->assertTrue($user->hasPermission('keep.me'));

        $user->forceDelete();
        $this->assertNull($this->getAccessRules()->setOwner($user::class, $user->id)->getOwner());
    }
}

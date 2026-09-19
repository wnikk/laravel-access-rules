<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Shop\Order;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;
use Tests\TestCase;
use Wnikk\LaravelAccessRules\Conditions\ConditionCompiler;

/**
 * The two tools for people: "why is this the answer" and "is everything stored still valid".
 *
 * Both are built from parts that make real decisions. The explanation runs the loop of DecisionPoint,
 * the lint compiles conditions with the compiler that saves them. So the tests here check what the
 * tools add on top, and that an explanation can never disagree with the check it explains.
 */
class explainAndLintTest extends TestCase
{
    /** @var TestUser */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [TestUser::class, 'Role']);
        ShopSchema::create();
        ShopSchema::seed();

        $acr = $this->getAccessRules();
        $acr->newRule('orders.view', 'View orders', resource: 'order');
        $acr->newRule('orders.update', 'Update orders', resource: 'order');
        $acr->newRule('orders.update.self', 'Update own orders', resource: 'order');

        $role = $this->getAccessRules();
        $role->newOwner('Role', 'manager', 'Managers');
        $role->addPermission('orders.view', when: 'order.cost > 100 && order.items.count < 3');

        $this->user = TestUser::factory()->make()->forceFill(['id' => 7, 'name' => 'Ann']);
        $this->user->inheritPermissionFrom($role);
        $this->user->addProhibition('orders.view', when: 'order.locked');
    }

    public function test_explanation_names_the_permission_that_decided_and_where_it_came_from(): void
    {
        $report = $this->user->access()->explain('orders.view', Order::find(1));

        $this->assertTrue($report['decision']);
        $this->assertSame('record', $report['asked']);
        $this->assertFalse($report['stale_cache']);
        $this->assertCount(2, $report['entries']);

        [$prohibition, $permit] = $report['entries'];

        $this->assertSame(['prohibit', 'order.locked == true', false, false, true], [$prohibition['effect'], $prohibition['condition'], $prohibition['result'], $prohibition['decisive'], $prohibition['own']]);

        // Loose on purpose: SQLite hands a boolean column back as 0, PostgreSQL as false. The value is shown as the check saw it.
        $this->assertSame(['permit', 'order.cost > 100 && count(order.items) < 3', true, true, false], [$permit['effect'], $permit['condition'], $permit['result'], $permit['decisive'], $permit['own']]);
        $this->assertSame('Role manager (Managers)', $permit['from']);

        $this->assertEquals(['order.locked' => false, 'order.cost' => 150, 'count(order.items)' => 2], $report['values']);
    }

    public function test_explanation_of_a_refusal(): void
    {
        $report = $this->user->access()->explain('orders.view', Order::find(4));

        // Cost and items are fine, but the order is locked: the own prohibition decides and the permit is not reached.
        $this->assertFalse($report['decision']);
        $this->assertTrue($report['entries'][0]['decisive']);
        $this->assertSame('not reached', $report['entries'][1]['result']);
        $this->assertEquals(['order.locked' => true], $report['values'], 'values of conditions that did not run are not read');
    }

    public function test_explanation_never_disagrees_with_the_check(): void
    {
        $this->user->addPermission('orders.update.self');
        $this->user->addPermission('orders.update', when: 'user.level >= 3');

        foreach (['orders.view', 'orders.update', 'orders.nothing'] as $ability) {
            foreach ([null, Order::class, ...Order::all()->all()] as $record) {
                $report = $this->user->access()->explain($ability, $record);

                $this->assertSame($this->user->hasPermission($ability, $record), $report['decision'], $ability);
                $this->assertFalse($report['stale_cache']);
            }
        }

        $general = $this->user->access()->explain('orders.update', Order::class);
        $this->assertSame('class', $general['asked']);
        $this->assertContains('general', array_column($general['entries'], 'result'));
        $this->assertContains('self', array_column($general['entries'], 'via'));
    }

    public function test_explanation_notices_a_stale_cache(): void
    {
        $this->assertTrue($this->user->can('orders.view', Order::find(1)));

        // Somebody changes permissions past the package, straight in the table: the cache knows nothing.
        DB::table(config('access.table_names.permission'))->where('permission', true)->delete();

        $report = $this->user->access()->explain('orders.view', Order::find(1));

        $this->assertNull($report['decision'], 'the database says nothing permits it any more');
        $this->assertTrue($report['from_cache'], 'a real check still answers from the cache');
        $this->assertTrue($report['stale_cache']);
    }

    public function test_explain_command(): void
    {
        Config::set('access.owner_types', [TestUser::class, 'Role']);

        $this->artisan('acr:explain', ['owner_type' => 'Role', 'owner_id' => 'manager', 'ability' => 'orders.view', 'record' => 'order:1'])
            ->expectsOutputToContain('PERMITTED')
            ->expectsOutputToContain('order.cost = 150')
            ->assertExitCode(0);

        $this->artisan('acr:explain', ['owner_type' => 'Role', 'owner_id' => 'manager', 'ability' => 'orders.view', 'record' => 'order:3'])
            ->expectsOutputToContain('NOTHING IS SAID')
            ->assertExitCode(0);

        $this->artisan('acr:explain', ['owner_type' => 'Role', 'owner_id' => 'manager', 'ability' => 'orders.view', 'record' => 'order'])
            ->expectsOutputToContain('records in general')
            ->assertExitCode(0);
    }

    public function test_lint_is_silent_while_everything_matches(): void
    {
        $this->artisan('acr:lint')->expectsOutputToContain('match current models and config')->assertExitCode(0);
    }

    public function test_lint_finds_what_changed_under_stored_conditions(): void
    {
        $this->getAccessRules()->newRule('clients.view', 'View clients', resource: 'client', when: "client.city == 'X'");
        $this->user->addPermission('orders.update', when: 'order.client.manager_id == user.id');

        // A migration renames a column, a refactoring drops a model from config, a type leaves owner_types.
        Schema::table('shop_orders', fn ($table) => $table->renameColumn('cost', 'price'));
        $resources = ShopSchema::RESOURCES;
        unset($resources['client']);
        Config::set('access.resources', $resources);
        Config::set('access.owner_types', [TestUser::class]);

        $this->artisan('acr:lint')
            ->expectsOutputToContain('"cost" is not a column of table "shop_orders"')
            ->expectsOutputToContain('resource "client" is not listed in config access.resources')
            ->expectsOutputToContain('Client reached by "order.client.manager_id" is not listed')
            ->expectsOutputToContain('that is not in config access.owner_types')
            ->assertExitCode(1);
    }

    public function test_lint_of_one_condition(): void
    {
        $compiler = app(ConditionCompiler::class);
        $tree     = $compiler->compile("order.cost > 100 && exists(order.items, price > 5) && order.client.city == 'X'", 'order');

        $this->assertSame([], $compiler->lint($tree, 'order'));

        Schema::table('shop_items', fn ($table) => $table->renameColumn('price', 'amount'));
        $this->assertStringContainsString('"price" is not a column of table "shop_items"', implode(' ', $compiler->lint($tree, 'order')));

        Config::set('access.functions', []);
        $this->assertStringContainsString('Unknown function', implode(' ', $compiler->lint(['cmp', '>', ['call', 'gone', []], ['val', 1]], 'order')));
    }
}

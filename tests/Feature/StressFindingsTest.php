<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Tests\Fixtures\Shop\Order;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;
use Wnikk\LaravelAccessRules\Administration\Linter;
use Wnikk\LaravelAccessRules\Administration\RuleCatalog;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;
use Wnikk\LaravelAccessRules\Exceptions\InvalidConditionException;
use Wnikk\LaravelAccessRules\Facades\Access;

/**
 * What a stress run of random operation sequences found in 3.3.5, held as promises since 3.3.14.
 * Each test is the shortest sequence that showed the behaviour.
 */
class StressFindingsTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [TestUser::class, 'Role', 'Team']);
        Config::set('access.tenant_types', ['Team']);
        ShopSchema::create();
        ShopSchema::seed();
    }

    private function newRequest(): void
    {
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstances();
    }

    public function test_a_new_rule_under_a_granted_parent_is_covered_at_once_when_the_tree_passes_permissions_down(): void
    {
        Config::set('access.rule_tree_inheritance', true);
        $parent = Access::newRule('reports', 'Reports');
        Access::for('Role', 'a')->create('A');
        Access::for('Role', 'a')->allow('reports');
        $this->assertTrue(Access::for('Role', 'a')->can('reports'));

        Access::newRule('reports.sales', 'Sales', null, $parent);
        $this->newRequest();
        $this->assertTrue(Access::for('Role', 'a')->can('reports.sales'));
        $this->assertFalse(Access::for('Role', 'a')->explain('reports.sales')['stale_cache']);
    }

    public function test_a_permission_for_a_value_the_rule_no_longer_allows_can_be_taken_away(): void
    {
        Access::newRule('orders.export', 'Export', options: 'required|in:csv,pdf', resource: 'order', origin: 'custom');
        Access::for('Role', 'a')->create('A');
        Access::for('Role', 'a')->allow('orders.export', 'pdf');

        app(RuleCatalog::class)->edit('orders.export', ['options' => 'required|in:csv']);
        $this->assertTrue(Access::for('Role', 'a')->can('orders.export.pdf'), 'documented: the row keeps working');
        $this->assertCount(1, app(Linter::class)->run()['problems'], 'documented: lint reports it');

        $this->assertTrue(Access::for('Role', 'a')->removeAllow('orders.export', 'pdf'));
        $this->assertNull(Access::for('Role', 'a')->can('orders.export.pdf'));
        $this->assertSame([], app(Linter::class)->run()['problems']);

        // Taking away what is not there answers false, whatever the option
        $this->assertFalse(Access::for('Role', 'a')->removeAllow('orders.export'));
        $this->assertFalse(Access::for('Role', 'a')->removeAllow('orders.export', 'nothing'));
    }

    public function test_a_change_of_options_switches_the_cache(): void
    {
        Access::newRule('orders.export', 'Export', options: 'in:csv,pdf', resource: 'order', origin: 'custom');
        Access::for('Role', 'a')->create('A');
        Access::for('Role', 'a')->allow('orders.export', 'pdf');
        $this->assertTrue(Access::for('Role', 'a')->can('orders.export.pdf'));

        app(RuleCatalog::class)->edit('orders.export', ['options' => 'in:csv']);
        $this->newRequest();
        $this->assertFalse(Access::for('Role', 'a')->explain('orders.export.pdf')['stale_cache']);
    }

    public function test_a_condition_about_the_record_needs_a_resource_on_the_rule(): void
    {
        Access::newRule('plain', 'Plain');
        Access::for('Role', 'a')->create('A');

        try {
            Access::for('Role', 'a')->allow('plain', when: 'order.cost > 100');
            $this->fail('saved');
        } catch (InvalidConditionException $e) {
            $this->assertStringContainsString('has no resource', $e->getMessage());
        }
        try {
            Access::newRule('plain.two', 'Two', when: 'order.cost > 100');
            $this->fail('saved');
        } catch (InvalidConditionException) {
            // the same for the condition of a rule
        }
        $this->assertSame([], app(Linter::class)->run()['problems']);

        // Conditions about the user, the environment and the author need none
        Access::for('Role', 'a')->allow('plain', when: 'user.department_id == 1 && env.weekday in [1,2,3,4,5,6,7]');
        Access::for('Role', 'a')->deny('plain', when: 'isAuthor()');
    }

    public function test_set_condition_respects_the_origin_of_the_rule_like_edit(): void
    {
        Access::newRule('orders.view', 'View', resource: 'order');
        Access::newRule('news.edit.sport', 'Sport', resource: 'order', origin: 'custom');

        try {
            app(RuleCatalog::class)->setCondition('orders.view', 'order.cost > 1');
            $this->fail('changed a rule of code');
        } catch (AccessRulesException $e) {
            $this->assertSame(AccessRulesException::RULE_MANAGED_BY_CODE, $e->getCode());
        }
        $this->assertTrue(app(RuleCatalog::class)->setCondition('news.edit.sport', 'order.cost > 1'));
        $this->assertSame('order.cost > 1', $this->conditionText(json_decode(DB::table(config('access.table_names.rule'))->where('guard_name', 'news.edit.sport')->value('condition'), true), 'order'));
    }

    public function test_a_tenant_type_outside_owner_types_is_named_by_the_check_and_by_lint(): void
    {
        Config::set('access.owner_types', [TestUser::class, 'Role']);
        Access::newRule('orders.view', 'View');
        Access::for('Role', 'a')->create('A');
        $user = TestUser::factory()->make()->forceFill(['id' => 7]);
        $user->inheritPermissionFrom('Role', 'a');

        try {
            $user->can('orders.view');
            $this->fail('answered');
        } catch (AccessRulesException $e) {
            $this->assertSame(AccessRulesException::UNKNOWN_OWNER_TYPE, $e->getCode());
            $this->assertStringContainsString('Tenant type "Team"', $e->getMessage());
        }
        $problems = app(Linter::class)->run()['problems'];
        $this->assertSame([Linter::UNKNOWN_OWNER_TYPE], array_column($problems, 'code'));
        $this->assertStringContainsString('tenant type "Team"', $problems[0]['problem']);
    }

    public function test_new_rule_answers_false_for_a_name_that_exists(): void
    {
        $this->assertIsInt(Access::newRule('orders.view', 'View'));
        $this->assertFalse(Access::newRule('orders.view', 'Again'));
        $this->assertSame(1, DB::table(config('access.table_names.rule'))->count());
    }

    public function test_a_record_of_a_model_that_is_not_a_resource_is_refused_as_a_mistake(): void
    {
        Access::newRule('orders.view', 'View', resource: 'order');
        Access::newRule('comments.edit.self', 'Own comments');
        $user = TestUser::factory()->make()->forceFill(['id' => 7]);
        $user->addPermission('orders.view', when: 'order.cost > 10');
        $user->addPermission('comments.edit.self');
        $this->assertTrue($user->hasPermission('orders.view', Order::find(1)));

        try {
            $user->hasPermission('orders.view', $user);
            $this->fail('answered about a user as if it were an order');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not listed in config access.resources', $e->getMessage());
        }

        // ".self" compares the author on any model, as in 2.x
        $comment = new class extends Model
        {
            protected $guarded = [];
        };
        $this->assertTrue($user->hasPermission('comments.edit', $comment->forceFill(['testuser_id' => 7])));
    }
}

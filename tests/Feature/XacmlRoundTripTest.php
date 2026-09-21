<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Shop\Order;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;
use Wnikk\LaravelAccessRules\AccessRules;
use Wnikk\LaravelAccessRules\Facades\Access;
use Wnikk\LaravelAccessRules\Xacml\Xacml;

/**
 * What the package exports it has to read back: the same owners, rules, inheritance, the same
 * text of every condition, and therefore the same decisions.
 *
 * The round trip does not prove that the XML means in XACML what the rows mean in the package;
 * XacmlMeaningTest does that with an evaluator of its own. This test holds the other promise:
 * an export is a backup that loses nothing.
 */
class XacmlRoundTripTest extends FeatureTestCase
{
    /** @var array<int, TestUser> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-18 12:00:00');
        Config::set('access.owner_types', [TestUser::class, 'Role', 'Team']);
        Config::set('access.tenant_types', ['Team']);
        ShopSchema::create();
        ShopSchema::seed();

        $root = AccessRules::newRule('orders', 'Orders', 'Everything about orders');
        AccessRules::newRule('orders.view', 'View orders', null, $root, null, 'order');
        AccessRules::newRule('orders.update', 'Update orders', null, $root, null, 'order', 'not order.locked');
        AccessRules::newRule('orders.update.self', 'Update own orders', null, $root, null, 'order');
        AccessRules::newRule('orders.export', 'Export orders', null, $root, 'required|in:csv,pdf', 'order');
        AccessRules::newRule('reports.view', 'View reports');

        Access::for('Role', 'staff')->create('Staff');
        Access::for('Role', 'staff')->allow('reports.view');
        Access::for('Role', 'staff')->allow('orders.view', when: "order.status in ['draft', 'review'] && order.client.city != 'Y'");

        Access::for('Role', 'manager')->create('Managers');
        Access::for('Role', 'manager')->inheritFrom('Role', 'staff');
        Access::for('Role', 'manager')->allow('orders.view', when: 'order.cost * 1.2 > order.budget || order.items.count < 3');
        Access::for('Role', 'manager')->allow('orders.update', when: 'order.cost <= user.approval_limit && order.testuser_id != user.id');
        Access::for('Role', 'manager')->allow('orders.export', 'csv');
        Access::for('Role', 'manager')->deny('orders.view', when: "exists(order.comments, kind == 'claim') && order.created_at < ago('24 hours')");

        Access::for('Team', 1)->create('Team 1');

        foreach ([7 => 'Ann', 9 => 'Bob'] as $id => $name) {
            $this->users[$id] = TestUser::factory()->make()->forceFill(['id' => $id, 'name' => $name, 'department_id' => 1, 'level' => 3, 'approval_limit' => 500, 'prefix' => 're']);
        }
        $this->users[7]->inheritPermissionFrom('Role', 'manager');
        $this->users[7]->inheritPermissionFrom('Team', 1);
        $this->users[7]->addPermission('orders.update.self');
        $this->users[7]->addProhibition('orders.view', when: 'startsWith(order.status, user.prefix) && order.client.team_id in user.tenant');
        $this->users[9]->inheritPermissionFrom('Role', 'staff');
        $this->users[9]->addPermission('orders.view', when: 'order.budget == null || order.cost between 100 and 200');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_export_restores_everything_in_an_empty_database(): void
    {
        $before = $this->snapshot();
        $export = $this->exportXacml();

        foreach (['permission', 'inheritance', 'owner', 'rule'] as $table) {
            DB::table(config('access.table_names.'.$table))->delete();
        }
        Access::flush();
        $this->assertNotSame($before['decisions'], $this->snapshot()['decisions'], 'the database is empty in between');

        $report = app(Xacml::class)->import($export['policy'], $export['manifest']);

        $this->assertSame([], $report['errors']);
        $this->assertTrue($report['written']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_second_import_changes_nothing(): void
    {
        $export = $this->exportXacml();
        $before = $this->snapshot();

        $report = app(Xacml::class)->import($export['policy'], $export['manifest']);

        $this->assertSame([], $report['errors']);
        $this->assertSame(['rules' => 0, 'owners' => 0, 'permissions' => 0, 'inheritance' => 0, 'replaced' => 0], $report['applied']);
        $this->assertSame(['same'], array_values(array_unique(array_column($report['changes'], 'action'))), 'the plan of an unchanged database holds nothing but "same"');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_what_xacml_cannot_say_travels_as_text_and_is_named_in_warnings(): void
    {
        $export = $this->exportXacml();

        $this->assertStringContainsString('urn:wnikk:access:function:dsl', $export['policy']);
        $this->assertCount(1, array_filter($export['warnings'], fn ($w) => str_contains($w, "exists(order.comments, kind == 'claim')")));
        $this->assertCount(1, array_filter($export['warnings'], fn ($w) => str_contains($w, "ago('24 hours')")));

        // Everything else is plain XACML.
        $this->assertCount(2, $export['warnings']);
    }

    private function snapshot(): array
    {
        $rules  = DB::table(config('access.table_names.rule'))->get()->keyBy('id');
        $owners = DB::table(config('access.table_names.owner'))->get()->keyBy('id');

        $permissions = DB::table(config('access.table_names.permission'))->get()->map(fn ($p) => implode(' | ', [
            $owners[$p->owner_id]->type.':'.$owners[$p->owner_id]->original_id, $rules[$p->rule_id]->guard_name, $p->option ?? '-', $p->permission ? 'permit' : 'prohibit',
            $p->condition === null ? '-' : $this->conditionText(json_decode($p->condition, true), $rules[$p->rule_id]->resource),
        ]))->sort()->values()->all();

        $decisions = [];
        foreach ($this->users as $id => $user) {
            foreach (['orders.view', 'orders.update', 'orders.export.csv', 'orders.export.pdf', 'reports.view'] as $ability) {
                $decisions[$id.' '.$ability] = json_encode([$user->hasPermission($ability), Order::orderBy('id')->get()->map(fn ($o) => $user->hasPermission($ability, $o))->all()]);
            }
        }

        return [
            'rules' => $rules->map(fn ($r) => implode(' | ', [$r->guard_name, $r->title, $r->description ?? '-', $r->options ?? '-', $r->resource ?? '-', empty($r->parent_id) ? '-' : $rules[$r->parent_id]->guard_name,
                $r->condition === null ? '-' : $this->conditionText(json_decode($r->condition, true), $r->resource)]))->sort()->values()->all(),
            'owners'      => $owners->map(fn ($o) => $o->type.':'.$o->original_id.' '.$o->name)->sort()->values()->all(),
            'inheritance' => DB::table(config('access.table_names.inheritance'))->get()->map(fn ($l) => $owners[$l->owner_id]->original_id.' < '.$owners[$l->owner_parent_id]->original_id)->sort()->values()->all(),
            'permissions' => $permissions,
            'decisions'   => $decisions,
        ];
    }
}

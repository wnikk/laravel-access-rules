<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;
use Wnikk\LaravelAccessRules\Facades\Access;

/**
 * What an owner holds, read as data: every row that reaches it with where it comes from, whom
 * it inherits from and who inherits from it. This is what an admin panel draws and a console
 * prints, and the compiled set of a check does not keep it: that set folds every row into one
 * answer per ability. The promise is that the rows agree with the tables and are ordered by the
 * five steps, so a screen built on them agrees with a check.
 */
class OwnerRowsTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [TestUser::class, 'Role', 'Team']);
        ShopSchema::create();

        $orders = Access::newRule('orders', 'Orders');
        Access::newRule('orders.view', 'View orders', null, $orders, null, 'order');
        Access::newRule('orders.export', 'Export', null, $orders, 'required|in:csv,pdf', 'order');

        Access::for('Role', 'manager')->create('Managers');
        Access::for('Role', 'manager')->allow('orders.view', when: 'order.cost > 100');
        Access::for('Role', 'manager')->deny('orders.view', when: 'order.locked');
        Access::for('Role', 'manager')->allow('orders.export', 'csv');

        Access::for('Role', 'chief')->create('Chiefs');
        Access::for('Role', 'chief')->inheritFrom('Role', 'manager');

        Access::for('Team', 'north')->create('North');

        Access::for(TestUser::class, 7)->create('Ann');
        Access::for(TestUser::class, 7)->inheritFrom('Role', 'chief');
        Access::for(TestUser::class, 7)->inheritFrom('Team', 'north');
        Access::for(TestUser::class, 7)->deny('orders.export', 'csv');
    }

    public function test_every_row_that_reaches_an_owner_with_where_it_comes_from(): void
    {
        $rows = Access::for(TestUser::class, 7)->permissions();

        $short = array_map(static fn (array $r) => [$r['rule'], $r['option'], $r['effect'], $r['when'], $r['own'], $r['from']['type'].':'.$r['from']['id'].' '.$r['from']['name']], $rows);

        $this->assertSame([
            ['orders.export', 'csv', 'deny', null, true, 'TestUser:7 Ann'],
            ['orders.export', 'csv', 'allow', null, false, 'Role:manager Managers'],
            ['orders.view', null, 'deny', 'order.locked == true', false, 'Role:manager Managers'],
            ['orders.view', null, 'allow', 'order.cost > 100', false, 'Role:manager Managers'],
        ], array_map(static fn (array $r) => [$r[0], $r[1], $r[2], $r[3], $r[4], str_replace('Tests\\Fixtures\\', '', $r[5])], $short), 'grouped by rule, strongest first: own prohibition, inherited permit; inherited prohibition before inherited permit');

        $this->assertSame([null, null, null, null], array_column($rows, 'via'));
        $this->assertSame('Role', $rows[1]['from']['type']);
        $this->assertIsInt($rows[1]['from']['record']);
        $this->assertSame([], Access::for(TestUser::class, 99)->permissions(), 'an owner without a record holds nothing');
    }

    public function test_rows_reach_the_rules_below_when_the_tree_inherits(): void
    {
        Access::for('Role', 'manager')->allow('orders');

        $this->assertSame(['orders'], array_unique(array_column(array_filter(Access::for(TestUser::class, 7)->permissions(), static fn (array $r) => $r['rule'] === 'orders'), 'rule')));
        $this->assertNotContains('tree', array_column(Access::for(TestUser::class, 7)->permissions(), 'via'), 'without the option the tree only groups');

        Config::set('access.rule_tree_inheritance', true);
        $view = array_values(array_filter(Access::for(TestUser::class, 7)->permissions(), static fn (array $r) => $r['rule'] === 'orders.view'));

        $this->assertSame(['deny', 'allow', 'allow'], array_column($view, 'effect'));
        $this->assertSame([null, null, 'tree'], array_column($view, 'via'));
        $this->assertSame('orders', $view[2]['via_rule']);
        $this->assertSame('orders.view', $view[2]['rule'], 'listed under the rule it reaches');
    }

    public function test_sources_and_heirs_carry_the_direct_link_each_came_through(): void
    {
        $sources = Access::for(TestUser::class, 7)->sources();
        $byName  = array_column($sources, null, 'name');

        $this->assertSame(['Chiefs', 'Managers', 'North'], array_keys($byName));
        $this->assertTrue($byName['Chiefs']['direct']);
        $this->assertIsInt($byName['Chiefs']['link']);
        $this->assertNull($byName['Chiefs']['through']);
        $this->assertFalse($byName['Managers']['direct']);
        $this->assertNull($byName['Managers']['link']);
        $this->assertSame($byName['Chiefs']['record'], $byName['Managers']['through'], 'reached through the link to Chiefs');
        $this->assertSame(['Team', 'north'], [$byName['North']['type'], $byName['North']['id']]);

        $heirs = Access::for('Role', 'manager')->heirs();
        $this->assertSame(['Ann', 'Chiefs'], array_column($heirs, 'name'));
        $this->assertSame([false, true], array_column($heirs, 'direct'));
        $this->assertSame(array_column($heirs, 'record', 'name')['Chiefs'], array_column($heirs, 'through', 'name')['Ann']);

        $this->assertSame([], Access::for('Team', 'north')->sources());
        $this->assertSame([], Access::for('Role', 'nobody')->heirs());
    }
}

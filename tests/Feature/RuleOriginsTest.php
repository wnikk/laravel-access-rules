<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\TestUser;
use Wnikk\LaravelAccessRules\AccessRules;
use Wnikk\LaravelAccessRules\Administration\RuleCatalog;
use Wnikk\LaravelAccessRules\Events\AccessChanged;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;
use Wnikk\LaravelAccessRules\Exceptions\InvalidConditionException;
use Wnikk\LaravelAccessRules\Facades\Access;
use Wnikk\LaravelAccessRules\Models\RuleOrigin;

/**
 * Who may do what to a rule.
 *
 * A rule is a name that code asks about, so a click in an admin panel must not be able to
 * remove it or rename it. Rules for names that code builds at run time are the opposite case:
 * a panel creates and deletes them freely. The origin of a rule tells the two apart, and one
 * more guard stands in front of everybody: a rule that somebody still holds is not deleted,
 * because its prohibitions would vanish with it.
 */
class RuleOriginsTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [TestUser::class, 'Role']);
        Access::for('Role', 'manager')->create('Managers');
    }

    public function test_a_rule_comes_with_code_unless_told_otherwise(): void
    {
        AccessRules::newRule('orders.view', 'View orders');
        AccessRules::newRule('news.edit.sport', 'Edit sport news', origin: RuleOrigin::Custom);
        AccessRules::newRule(['guard_name' => 'news.edit.politics', 'origin' => 'custom']);
        AccessRules::newRule('strange', origin: 'no such origin');

        $this->assertSame(
            ['news.edit.politics' => 'custom', 'news.edit.sport' => 'custom', 'orders.view' => 'code', 'strange' => 'code'],
            DB::table(config('access.table_names.rule'))->orderBy('guard_name')->pluck('origin', 'guard_name')->all()
        );
    }

    public function test_a_rule_that_somebody_holds_is_not_deleted(): void
    {
        AccessRules::newRule('orders.view', 'View orders');
        Access::for('Role', 'manager')->deny('orders.view');

        try {
            AccessRules::delRule('orders.view');
            $this->fail('the prohibition would have vanished with the rule');
        } catch (AccessRulesException $e) {
            $this->assertSame(AccessRulesException::RULE_IN_USE, $e->getCode());
            $this->assertStringContainsString('held by 1 permission', $e->getMessage());
        }
        $this->assertFalse(Access::for('Role', 'manager')->can('orders.view'), 'the prohibition is still in force');

        // Either the holder lets go first, or the caller says force and means it.
        Access::for('Role', 'manager')->removeDeny('orders.view');
        $this->assertTrue(AccessRules::delRule('orders.view'));

        AccessRules::newRule('orders.update', 'Update orders');
        Access::for('Role', 'manager')->allow('orders.update');
        $this->assertTrue(AccessRules::delRule('orders.update', true));
        $this->assertSame(0, DB::table(config('access.table_names.permission'))->count());
    }

    public function test_children_move_up_when_their_parent_is_deleted(): void
    {
        $root   = AccessRules::newRule('reports', 'Reports');
        $middle = AccessRules::newRule('reports.sales', 'Sales', null, $root);
        AccessRules::newRule('reports.sales.daily', 'Daily', null, $middle);

        AccessRules::delRule('reports.sales');

        $this->assertSame($root, (int) DB::table(config('access.table_names.rule'))->where('guard_name', 'reports.sales.daily')->value('parent_id'));
    }

    public function test_an_admin_panel_cannot_remove_what_comes_with_code(): void
    {
        $catalog = app(RuleCatalog::class);
        AccessRules::newRule('orders.view', 'View orders');
        AccessRules::newRule('news.edit.sport', 'Edit sport news', origin: RuleOrigin::Custom);
        Access::for('Role', 'manager')->allow('news.edit.sport');

        foreach ([['orders.view', AccessRulesException::RULE_MANAGED_BY_CODE], ['news.edit.sport', AccessRulesException::RULE_IN_USE]] as [$rule, $code]) {
            try {
                $catalog->discard($rule);
                $this->fail($rule.' had to stay');
            } catch (AccessRulesException $e) {
                $this->assertSame($code, $e->getCode());
            }
        }

        Access::for('Role', 'manager')->removeAllow('news.edit.sport');
        $this->assertTrue($catalog->discard('news.edit.sport'));
        $this->assertFalse($catalog->discard('news.edit.sport'), 'already gone');
    }

    public function test_an_admin_panel_may_reword_a_rule_of_code_and_edit_its_options(): void
    {
        Config::set('access.resources', []);
        $catalog = app(RuleCatalog::class);
        AccessRules::newRule('orders.export', 'Export', null, null, 'required|in:csv');

        $heard = [];
        Event::listen(AccessChanged::class, function (AccessChanged $event) use (&$heard) {
            $heard[] = [$event->action, $event->details['fields'] ?? null];
        });

        $this->assertTrue($catalog->edit('orders.export', ['title' => 'Export orders', 'description' => 'As a file', 'options' => 'required|in:csv,pdf']));
        $this->assertSame([[AccessChanged::RULE_EDITED, ['title', 'description', 'options']]], $heard);

        Access::for('Role', 'manager')->allow('orders.export', 'pdf');
        $this->assertTrue(Access::for('Role', 'manager')->can('orders.export.pdf'));

        foreach ([['guard_name' => 'orders.download'], ['resource' => 'order'], ['when' => 'user.level > 3'], ['parent_id' => 1], ['title' => 'x', 'guard_name' => 'y']] as $fields) {
            try {
                $catalog->edit('orders.export', $fields);
                $this->fail(json_encode($fields).' had to be refused');
            } catch (AccessRulesException $e) {
                $this->assertSame(AccessRulesException::RULE_MANAGED_BY_CODE, $e->getCode());
            }
        }

        $this->assertSame('Export orders', DB::table(config('access.table_names.rule'))->where('guard_name', 'orders.export')->value('title'), 'a refused edit changes nothing');
    }

    public function test_a_custom_rule_is_open_and_still_checked(): void
    {
        $catalog = app(RuleCatalog::class);
        $parent  = AccessRules::newRule('news', 'News');
        AccessRules::newRule('news.edit.sport', 'Edit sport news', origin: RuleOrigin::Custom);
        Access::for('Role', 'manager')->allow('news.edit.sport');

        $this->assertTrue($catalog->edit('news.edit.sport', ['guard_name' => 'news.edit.sports', 'parent_id' => $parent, 'when' => 'user.level > 3']));

        $row = DB::table(config('access.table_names.rule'))->where('guard_name', 'news.edit.sports')->first();
        $this->assertSame([$parent, 'custom'], [(int) $row->parent_id, $row->origin]);
        $this->assertNotNull($row->condition);

        // Permissions point at the rule by id, so they follow the new name.
        $this->assertNull(Access::for('Role', 'manager')->can('news.edit.sport'));

        try {
            $catalog->edit('news.edit.sports', ['when' => 'user.level >']);
            $this->fail('a broken condition had to be refused');
        } catch (InvalidConditionException) {
            $this->addToAssertionCount(1);
        }

        try {
            $catalog->edit('no.such.rule', ['title' => 'x']);
            $this->fail('no such rule');
        } catch (AccessRulesException $e) {
            $this->assertSame(AccessRulesException::RULE_NOT_FOUND, $e->getCode());
        }
    }

    public function test_lint_finds_permissions_whose_option_the_rule_no_longer_allows(): void
    {
        AccessRules::newRule('orders.export', 'Export', null, null, 'required|in:csv,pdf');
        Access::for('Role', 'manager')->allow('orders.export', 'csv');
        Access::for('Role', 'manager')->allow('orders.export', 'pdf');
        $this->artisan('acr:lint')->assertExitCode(0);

        app(RuleCatalog::class)->edit('orders.export', ['options' => 'required|in:csv']);

        $this->artisan('acr:lint')
            ->expectsOutputToContain('option "pdf" is no longer allowed by the rule (required|in:csv)')
            ->expectsOutputToContain('1 problem(s) found.')
            ->assertExitCode(1);

        $this->assertTrue(Access::for('Role', 'manager')->can('orders.export.pdf'), 'as the message says, the permission still works');
    }

    public function test_the_console_refuses_and_forces_the_same_way(): void
    {
        $this->artisan('acr:create', ['rule' => 'news.edit.sport', '--origin' => 'custom'])->assertExitCode(0);
        $this->assertSame('custom', DB::table(config('access.table_names.rule'))->where('guard_name', 'news.edit.sport')->value('origin'));

        Access::for('Role', 'manager')->allow('news.edit.sport');

        $this->artisan('acr:delete', ['rule' => 'news.edit.sport'])->expectsOutputToContain('held by 1 permission')->assertExitCode(1);
        $this->artisan('acr:delete', ['rule' => 'news.edit.sport', '--force' => true])->assertExitCode(0);
        $this->artisan('acr:delete', ['rule' => 'news.edit.sport'])->expectsOutputToContain('not found')->assertExitCode(1);
    }
}

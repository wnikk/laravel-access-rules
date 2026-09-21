<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Fixtures\Shop\Order;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;
use Wnikk\LaravelAccessRules\AccessRules;
use Wnikk\LaravelAccessRules\Facades\Access;

/**
 * The places where database servers behave differently: migrations, types of keys, types of values.
 *
 * The rest of the suite passed on PostgreSQL at the first attempt, because Eloquent builds
 * nearly all SQL of the package. What is left is here, and both findings so far were on the
 * SQLite side: it cannot add a foreign key to an existing table, and it ranks every number
 * below every text when an expression has no column type.
 *
 * Run the suite on every server the package claims to support. DB_CONNECTION and friends in
 * the environment override phpunit.xml.
 */
class SchemaTest extends FeatureTestCase
{
    /**
     * Empty on purpose. Tests of this file run the published migrations themselves, and the
     * tables of the base class would be in the way.
     */
    protected function afterRefreshingDatabase() {}

    private function migration(string $file)
    {
        return require __DIR__.'/../../database/migrations/'.$file;
    }

    public function test_fresh_install_has_constraints_from_the_start(): void
    {
        Config::set('access.owner_types', ['Role']);
        $tables = (object) config('access.table_names');

        $this->migration('create_access_rules_tables.php.stub')->up();

        $this->assertTrue(Schema::hasIndex($tables->owner, 'acr_owner_unique'));
        $this->assertTrue(Schema::hasIndex($tables->inheritance, 'acr_inheritance_unique'));
        $this->assertTrue(Schema::hasIndex($tables->permission, 'acr_permission_unique'));

        $this->getAccessRules()->newOwner('Role', 'manager');
        try {
            $duplicate = fn () => DB::table($tables->owner)->insert(['type' => AccessRules::getTypeID('Role'), 'original_id' => 'manager', 'created_at' => now()]);

            // PostgreSQL aborts a transaction on a failed statement, so the attempt needs a savepoint there.
            // MySQL has ended the transaction of the test already, with the first CREATE TABLE, and has no savepoint to return to.
            in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? $duplicate() : DB::transaction($duplicate);
            $this->fail('Unique index has to refuse a duplicate of an owner');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * PostgreSQL stores conditions as jsonb, a binary form that reorders keys of objects and
     * normalizes numbers. Trees are lists, which jsonb keeps in order, and this test is what
     * notices if a future node type turns into an object.
     */
    public function test_condition_comes_back_from_the_database_exactly_as_it_was_saved(): void
    {
        Config::set('access.owner_types', [TestUser::class]);
        $tables = (object) config('access.table_names');
        $this->migration('create_access_rules_tables.php.stub')->up();
        ShopSchema::create();

        if (DB::getDriverName() === 'pgsql') {
            $this->assertSame('jsonb', Schema::getColumnType($tables->permission, 'condition'));
            $this->assertSame('jsonb', Schema::getColumnType($tables->rule, 'condition'));
        }

        $condition = "(order.cost > 100.5 || order.budget == null) && order.status in ['b', 'a', 'c'] && not order.locked"
            .' && sum(order.client.payments.amount, paid_at >= monthStart(-1) && amount != 0) >= 1000 && user.level in [3, 1, 2]';

        $this->getAccessRules()->newRule('orders.view', resource: 'order', when: $condition);
        $user = TestUser::factory()->make()->forceFill(['id' => 7]);
        $user->addPermission('orders.view', when: $condition);

        // jsonb reorders keys and normalizes numbers. A list of values must keep its order, 100.5 must
        // stay 100.5, and the rule and the permission, saved from one text, must hold one and the same tree.
        $ofPermission = $user->getOwner()->permission()->first()->condition;
        $ofRule       = DB::table($tables->rule)->where('guard_name', 'orders.view')->value('condition');

        $this->assertSame($ofPermission, json_decode($ofRule, true));
        $this->assertSame(
            "(order.cost > 100.5 || order.budget == null) && order.status in ['b', 'a', 'c'] && !(order.locked == true)"
            .' && sum(order.client.payments.amount, paid_at >= monthStart(-1) && amount != 0) >= 1000 && user.level in [3, 1, 2]',
            $this->conditionText($ofPermission, 'order')
        );
    }

    /**
     * The example migration ships as documentation, and documentation that never runs drifts.
     * It names App\Models\User, so the test user takes that name for the length of the test.
     */
    public function test_example_migration_for_the_first_user_runs(): void
    {
        if (! class_exists(User::class)) {
            class_alias(TestUser::class, User::class);
        }
        Config::set('access.owner_types', [TestUser::class, 'Group', 'Role']);

        $this->migration('create_access_rules_tables.php.stub')->up();
        Schema::create('test_users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });
        $user = TestUser::create(['name' => 'First', 'email' => 'first@example.com']);

        $this->migration('insert_access_rules_for_first_user.php.stub')->up();

        $user = TestUser::find($user->id);
        $this->assertTrue($user->can('Test.rule'));
        $this->assertTrue($user->can('Test.rule.demo'));
        $this->assertFalse($user->can('Test.rule.other'));

        Carbon::setTestNow('2026-09-18 12:00:00');   // Friday
        $this->assertTrue($user->can('Test.worktime'));
        Carbon::setTestNow('2026-09-19 12:00:00');   // Saturday
        $this->assertFalse(TestUser::find($user->id)->can('Test.worktime'));
        Carbon::setTestNow();

        // The way back leaves nothing behind: a project that tried the example gets clean tables again.
        $this->migration('insert_access_rules_for_first_user.php.stub')->down();

        $this->assertFalse(TestUser::find($user->id)->can('Test.rule'));
        foreach (['rule', 'permission', 'inheritance'] as $table) {
            $this->assertSame(0, DB::table(config('access.table_names.'.$table))->count(), $table);
        }
        $this->assertNull(Access::for('Role', 'RootAdmin')->record());
    }

    public function test_owners_with_string_and_uuid_keys(): void
    {
        Config::set('access.owner_types', [TestUser::class, 'Role', 'Team']);
        Config::set('access.tenant_types', ['Team']);
        $this->migration('create_access_rules_tables.php.stub')->up();
        ShopSchema::create();
        ShopSchema::seed();

        $acr = $this->getAccessRules();
        $acr->newRule('news.view');
        $acr->newRule('orders.view', resource: 'order');

        $uuid = (string) Str::uuid();
        $role = $this->getAccessRules();
        $role->newOwner('Role', $uuid, 'Role with uuid');
        $role->addPermission('news.view');

        // The id column of owners is text. A uuid fits as it is, and a team with id 1 is stored as "1".
        $user = TestUser::factory()->make()->forceFill(['id' => (string) Str::uuid()]);
        $user->inheritPermissionFrom('Role', $uuid);
        $this->getAccessRules()->newOwner('Team', 1, 'Team 1');
        $user->inheritPermissionFrom('Team', 1);
        $user->addPermission('orders.view', when: 'order.client.team_id in user.tenant');

        $this->assertTrue($user->can('news.view'));
        $this->assertSame([1, 2, 3], Order::query()->allowedTo('orders.view', $user)->orderBy('id')->pluck('id')->all());
        $this->assertTrue($user->can('orders.view', Order::find(3)));
        $this->assertFalse($user->can('orders.view', Order::find(5)));

        // PostgreSQL refuses to compare a text column with an integer parameter, so the package casts.
        $this->assertSame(
            $this->getAccessRules()->setOwner('Team', 1)->getOwner()->id,
            $this->getAccessRules()->setOwner('Team', '1')->getOwner()->id
        );
    }
}

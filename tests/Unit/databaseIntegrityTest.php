<?php

namespace Tests\Unit;

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
use Tests\TestCase;
use Wnikk\LaravelAccessRules\Administration\TypeRegistry;
use Wnikk\LaravelAccessRules\Conditions\ConditionCompiler;
use Wnikk\LaravelAccessRules\Contracts\Rule;

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
class databaseIntegrityTest extends TestCase
{
    /**
     * Empty on purpose. Tests of this file create tables themselves, some from the schema of
     * version 2, and the tables of the base class would be in the way.
     */
    protected function afterRefreshingDatabase() {}

    private function migration(string $file)
    {
        return require __DIR__.'/../../database/migrations/'.$file;
    }

    /**
     * The schema comes from the git history of version 2 and rows are inserted the way it wrote
     * them, with raw CRC-16 type numbers. An upgrade that works only on tables created by
     * version 3 proves nothing about the projects that will run it.
     */
    public function test_tables_of_2x_with_data_are_upgraded_and_keep_working(): void
    {
        Config::set('access.owner_types', [TestUser::class, 'Role']);
        $tables = (object) config('access.table_names');

        (require __DIR__.'/../Fixtures/migrations/create_access_rules_tables_v2.php')->up();
        $this->assertFalse(Schema::hasColumn($tables->permission, 'condition'));

        $now  = now()->toDateTimeString();
        $role = DB::table($tables->owner)->insertGetId(['type' => TypeRegistry::crc16('Role'), 'original_id' => 'manager', 'name' => 'Managers', 'created_at' => $now]);
        $user = DB::table($tables->owner)->insertGetId(['type' => TypeRegistry::crc16(TestUser::class), 'original_id' => '7', 'name' => 'Seven', 'created_at' => $now]);
        DB::table($tables->inheritance)->insert(['owner_id' => $user, 'owner_parent_id' => $role, 'created_at' => $now]);
        $view = DB::table($tables->rule)->insertGetId(['guard_name' => 'news.view', 'created_at' => $now]);
        $edit = DB::table($tables->rule)->insertGetId(['guard_name' => 'news.edit', 'options' => 'required|in:1,2,3', 'created_at' => $now]);
        $drop = DB::table($tables->rule)->insertGetId(['guard_name' => 'news.delete', 'created_at' => $now]);
        DB::table($tables->permission)->insert([
            ['owner_id' => $role, 'rule_id' => $view, 'permission' => true,  'option' => null, 'created_at' => $now],
            ['owner_id' => $role, 'rule_id' => $edit, 'permission' => true,  'option' => '2',  'created_at' => $now],
            ['owner_id' => $role, 'rule_id' => $drop, 'permission' => true,  'option' => null, 'created_at' => $now],
            ['owner_id' => $user, 'rule_id' => $drop, 'permission' => false, 'option' => null, 'created_at' => $now],
        ]);

        $upgrade = $this->migration('upgrade_access_rules_tables_to_v3.php.stub');
        $upgrade->up();
        $upgrade->up();   // safe to run twice

        $this->assertTrue(Schema::hasColumns($tables->rule, ['resource', 'condition']));
        $this->assertTrue(Schema::hasColumn($tables->permission, 'condition'));

        // No conversion step ran between the upgrade and these checks.
        $seven = TestUser::factory()->make()->forceFill(['id' => 7]);
        $this->assertTrue($seven->can('news.view'));
        $this->assertTrue($seven->can('news.edit.2'));
        $this->assertFalse($seven->can('news.edit.3'));
        $this->assertFalse($seven->can('news.delete'), 'own prohibition of 2.x');

        // Conditions work on the upgraded tables, next to rows of version 2.
        ShopSchema::create();
        ShopSchema::seed();
        $this->getAccessRules()->newRule('orders.view', resource: 'order');
        $seven->addPermission('orders.view', when: 'order.cost > 100 && order.items.count < 3');
        $this->assertSame([1, 4, 5, 6], Order::query()->allowedTo('orders.view', $seven)->orderBy('id')->pluck('id')->all());

        $upgrade->down();
        $this->assertFalse(Schema::hasColumn($tables->permission, 'condition'));
    }

    public function test_constraints_migration(): void
    {
        Config::set('access.owner_types', [TestUser::class, 'Role']);
        $tables = (object) config('access.table_names');

        // Tables of version 2, upgraded. Fresh tables of version 3 carry the indexes from the start,
        // and the migration would have nothing to do.
        (require __DIR__.'/../Fixtures/migrations/create_access_rules_tables_v2.php')->up();
        $this->migration('upgrade_access_rules_tables_to_v3.php.stub')->up();
        $constraints = $this->migration('add_access_rules_constraints.php.stub');

        $role = $this->getAccessRules();
        $role->newOwner('Role', 'manager');
        $this->getAccessRules()->newRule('news.view');
        $role->addPermission('news.view');

        // Two requests that created the same owner at once left this behind in version 2.
        $duplicate = DB::table($tables->owner)->insertGetId(['type' => TypeRegistry::crc16('Role'), 'original_id' => 'manager', 'created_at' => now()]);

        try {
            DB::transaction(fn () => $constraints->up());
            $this->fail('Duplicates have to stop the migration');
        } catch (\Exception $e) {
            $this->assertStringContainsString('has duplicates', $e->getMessage());
            $this->assertStringContainsString('manager', $e->getMessage());
        }

        DB::table($tables->owner)->where('id', $duplicate)->delete();
        $constraints->up();

        $user = TestUser::factory()->make()->forceFill(['id' => 7]);
        $user->inheritPermissionFrom($role);
        $this->assertTrue($user->can('news.view'));

        try {
            DB::transaction(fn () => DB::table($tables->owner)->insert(['type' => TypeRegistry::crc16('Role'), 'original_id' => 'manager', 'created_at' => now()]));
            $this->fail('Unique index has to refuse a duplicate of an owner');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // A parent removed straight in the database takes its links with it. Not on SQLite, which
        // cannot add a foreign key to an existing table; there only the package cleans up.
        if (DB::getDriverName() !== 'sqlite') {
            DB::table($tables->permission)->delete();
            DB::table($tables->owner)->where('original_id', 'manager')->delete();
            $this->assertSame(0, DB::table($tables->inheritance)->count());
        }

        $constraints->down();
        $this->addToAssertionCount(1);
    }

    public function test_fresh_install_has_constraints_from_the_start(): void
    {
        Config::set('access.owner_types', ['Role']);
        $tables = (object) config('access.table_names');

        $this->migration('create_access_rules_tables.php.stub')->up();

        $this->assertTrue(Schema::hasIndex($tables->owner, 'acr_owner_unique'));
        $this->assertTrue(Schema::hasIndex($tables->inheritance, 'acr_inheritance_unique'));
        $this->assertTrue(Schema::hasIndex($tables->permission, 'acr_permission_unique'));

        // Projects publish every migration of a package at once. The optional one must notice that
        // its work is done and not fail on an index that already exists.
        $this->migration('add_access_rules_constraints.php.stub')->up();

        $this->getAccessRules()->newOwner('Role', 'manager');
        try {
            DB::transaction(fn () => DB::table($tables->owner)->insert(['type' => TypeRegistry::crc16('Role'), 'original_id' => 'manager', 'created_at' => now()]));
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

        $compiler = app(ConditionCompiler::class);
        $tree     = $compiler->compile($condition, 'order');

        $this->getAccessRules()->newRule('orders.view', resource: 'order', when: $condition);
        $user = TestUser::factory()->make()->forceFill(['id' => 7]);
        $user->addPermission('orders.view', when: $condition);

        $this->assertSame($tree, $user->getOwner()->permission()->first()->condition);
        $this->assertSame($tree, app(Rule::class)->newQuery()->first()->condition);
        $this->assertSame($compiler->describe($tree, 'order'), $compiler->describe($user->getOwner()->permission()->first()->condition, 'order'));
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

    /**
     * The evaluator compares loosely, as PHP does, and every server has its own idea of comparing
     * a text column with a number. Each case below once gave, or could give, a different answer
     * in the list and in the check.
     */
    public function test_values_and_columns_of_different_types(): void
    {
        Config::set('access.owner_types', [TestUser::class]);
        $this->migration('create_access_rules_tables.php.stub')->up();
        ShopSchema::create();
        ShopSchema::seed();
        DB::table('shop_clients')->where('id', 3)->update(['city' => '7']);   // text column that holds a number

        $this->getAccessRules()->newRule('clients.view', resource: 'client');
        $this->getAccessRules()->newRule('orders.view', resource: 'order');
        $user = TestUser::factory()->make()->forceFill(['id' => 7, 'limit' => '200']);

        $cases = [
            ['client', 'clients.view', 'client.city == user.id',               [3]],        // text column = integer of the user
            ['client', 'clients.view', 'client.city == 7',                     [3]],        // text column = integer literal
            ['client', 'clients.view', "client.manager_id == '7'",             [1, 2, 4]],  // integer column = text literal
            ['client', 'clients.view', "client.manager_id in ['7', 9]",        [1, 2, 3, 4]],
            ['order',  'orders.view',  'order.cost <= user.limit',             [1, 2, 3, 5]], // integer column <= text of the user
            ['order',  'orders.view',  "count(order.items) >= '2'",            [1, 2]],     // aggregate against text
            ['client', 'clients.view', 'sum(client.payments.amount) > user.limit', [1, 2, 3, 4]], // aggregate against a decimal attribute (text in PHP)
            ['client', 'clients.view', 'user.limit < max(client.payments.amount)', [1, 2, 3, 4]],
            ['order',  'orders.view',  "order.client.manager_id == '9'",       [4]],        // through a relation
            ['order',  'orders.view',  'order.locked == 1',                    [4]],        // boolean column = integer
            ['order',  'orders.view',  'order.locked == false && order.cost > 500', [6]],
            ['order',  'orders.view',  "order.created_at >= '2026-09-18'",     [1, 3, 6]],  // datetime column against a date
        ];

        foreach ($cases as [$resource, $ability, $condition, $expected]) {
            $user->remPermission($ability);
            $user->addPermission($ability, when: $condition);

            $model   = ShopSchema::RESOURCES[$resource];
            $byQuery = $model::query()->allowedTo($ability, $user)->orderBy('id')->pluck('id')->all();
            $byCheck = $model::query()->orderBy('id')->get()->filter(fn ($record) => $user->can($ability, $record))->pluck('id')->values()->all();

            $this->assertSame($expected, $byQuery, 'filter of list: '.$condition);
            $this->assertSame($expected, $byCheck, 'check of record: '.$condition);
        }
    }
}

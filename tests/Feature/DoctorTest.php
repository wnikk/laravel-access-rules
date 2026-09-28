<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TestUser;
use Wnikk\LaravelAccessRules\Administration\Doctor;
use Wnikk\LaravelAccessRules\Facades\Access;

/**
 * The third tool for people: "are the rows what the package would have written". Every row it
 * looks for is one the package refuses to write, so the tests write them past the package, the
 * way a hand-typed statement or a partial restore does.
 */
class DoctorTest extends FeatureTestCase
{
    private string $permissions;

    private string $inheritance;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [TestUser::class, 'Role']);

        $this->permissions = config('access.table_names.permission');
        $this->inheritance = config('access.table_names.inheritance');

        Access::newRule('orders.view', 'View orders');
        Access::newRule('orders.export', 'Export orders', options: 'in:csv,pdf');
        Access::for('Role', 'manager')->create('Managers');
        Access::for('Role', 'manager')->allow('orders.view');
        Access::for('Role', 'manager')->allow('orders.export', 'csv');
        Access::for('Role', 'chief')->create('Chiefs');
        Access::for('Role', 'chief')->inheritFrom('Role', 'manager');
    }

    public function test_doctor_is_silent_while_the_rows_are_consistent(): void
    {
        $this->assertSame(['problems' => [], 'fixed' => 0], app(Doctor::class)->run());
        $this->artisan('acr:doctor')->expectsOutputToContain('consistent')->assertExitCode(0);
    }

    public function test_doctor_finds_rows_the_package_would_never_write_and_deletes_what_it_can(): void
    {
        $manager = Access::for('Role', 'manager')->record()->getKey();
        $chief   = Access::for('Role', 'chief')->record()->getKey();
        $view    = DB::table($this->permissions)->where('owner_id', $manager)->whereNull('option')->first();

        // A second copy of a permission without an option: the unique index treats two NULLs as different values.
        DB::table($this->permissions)->insert(['owner_id' => $manager, 'rule_id' => $view->rule_id, 'permission' => 1, 'option' => null, 'created_at' => now()]);
        DB::table($this->permissions)->insert(['owner_id' => $manager, 'rule_id' => $view->rule_id, 'permission' => 1, 'option' => null, 'created_at' => now()]);

        // The link that inherit() refuses.
        DB::table($this->inheritance)->insert(['owner_id' => $manager, 'owner_parent_id' => $chief, 'created_at' => now()]);

        // Rows that point at nothing, past the foreign keys.
        $this->withoutForeignKeys(function () use ($manager, $view) {
            DB::table($this->permissions)->insert(['owner_id' => $manager, 'rule_id' => 999, 'permission' => 1, 'option' => null, 'created_at' => now()]);
            DB::table($this->permissions)->insert(['owner_id' => 998, 'rule_id' => $view->rule_id, 'permission' => 0, 'option' => null, 'created_at' => now()]);
            DB::table($this->inheritance)->insert(['owner_id' => 997, 'owner_parent_id' => $manager, 'created_at' => now()]);
        });

        $problems = app(Doctor::class)->run()['problems'];

        $this->assertEqualsCanonicalizing(
            [Doctor::DUPLICATE_PERMISSION.' permission', Doctor::RULE_MISSING.' permission', Doctor::OWNER_MISSING.' permission', Doctor::OWNER_MISSING.' inheritance', Doctor::INHERITANCE_LOOP.' inheritance'],
            array_map(fn (array $found) => $found['code'].' '.$found['subject'], $problems)
        );
        foreach ($problems as $found) {
            $this->assertSame(['code', 'subject', 'id', 'where', 'problem'], array_keys($found));
            $this->assertNotNull($found['id']);
        }

        $byCode = array_column($problems, null, 'code');
        $this->assertSame('permission #'.$view->id.' of Role manager for orders.view', $byCode[Doctor::DUPLICATE_PERMISSION]['where']);
        $this->assertStringContainsString('stored 3 times', $byCode[Doctor::DUPLICATE_PERMISSION]['problem']);
        $this->assertStringContainsString('rule #999', $byCode[Doctor::RULE_MISSING]['problem']);
        $this->assertStringContainsString('Role chief inherits from Role manager inherits from Role chief', $byCode[Doctor::INHERITANCE_LOOP]['problem']);

        $this->artisan('acr:doctor')->expectsOutputToContain('stored 3 times')->expectsOutputToContain('5 problem(s) found')->assertExitCode(1);

        // --fix deletes the copies and the orphans; the loop stays, because only a person knows which link is wrong.
        $this->artisan('acr:doctor', ['--fix' => true])->expectsOutputToContain('5 row(s) deleted')->expectsOutputToContain('1 problem(s) found')->assertExitCode(1);

        $this->assertSame(2, DB::table($this->permissions)->where('owner_id', $manager)->count());
        $this->assertSame(1, DB::table($this->permissions)->where('owner_id', $manager)->where('rule_id', $view->rule_id)->count());
        $this->assertSame(2, DB::table($this->inheritance)->count());
        $this->assertTrue(Access::for('Role', 'manager')->can('orders.view'));

        $remaining = app(Doctor::class)->run(true);
        $this->assertSame(0, $remaining['fixed']);
        $this->assertSame([Doctor::INHERITANCE_LOOP], array_column($remaining['problems'], 'code'));
    }

    /**
     * Foreign keys forbid what the doctor looks for. SQLite turns them off with a pragma; PostgreSQL
     * and MySQL keep a constraint until it is dropped, so it is dropped for the callback and not restored:
     * the database of a test lives for one test.
     */
    private function withoutForeignKeys(callable $write): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::disableForeignKeyConstraints();
            $write();
            Schema::enableForeignKeyConstraints();

            return;
        }

        Schema::table($this->permissions, function ($table) {
            $table->dropForeign(['owner_id']);
            $table->dropForeign(['rule_id']);
        });
        Schema::table($this->inheritance, function ($table) {
            $table->dropForeign(['owner_id']);
            $table->dropForeign('acr_inheritance_parent_fk');
        });

        $write();
    }
}

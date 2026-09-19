<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Wnikk\LaravelAccessRules\AccessRules;
use Wnikk\LaravelAccessRules\AccessRulesServiceProvider;
use Wnikk\LaravelAccessRules\Models\Inheritance;
use Wnikk\LaravelAccessRules\Models\Owner;
use Wnikk\LaravelAccessRules\Models\Permission;
use Wnikk\LaravelAccessRules\Models\Rule;

/**
 * Base of every test: an application with the package loaded and its tables in place.
 *
 * The suite runs on whatever database the environment names. phpunit.xml says SQLite in memory,
 * and DB_CONNECTION with friends in the environment points the same tests at PostgreSQL or
 * MySQL. Servers differ exactly where the package writes SQL, so the suite has to pass on all
 * of them, and nothing in the tests may assume SQLite.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * The cache remembers per process whether its store works. One test with a broken store would
     * turn the cache off for every test after it, so the memory is wiped first.
     */
    protected function setUp(): void
    {
        AccessRules::resetCacheState();
        parent::setUp();
    }

    /**
     * Loads config from the file of the package, not from a copy. A key added to config/access.php
     * then reaches the tests without anybody remembering to mirror it here.
     */
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('access',
            require __DIR__.'/../config/access.php'
        );

    }

    /**
     * Creates tables of the package after RefreshDatabase has done its part and, on a real server,
     * inside the transaction of the test.
     *
     * Creating them earlier, while the application boots, works on SQLite in memory only. On
     * PostgreSQL the first "migrate:fresh" drops them again, and whatever survives piles up from
     * test to test. Inside the transaction every test starts empty and leaves nothing behind.
     */
    protected function afterRefreshingDatabase()
    {
        $migration = require __DIR__.'/../database/migrations/create_access_rules_tables.php.stub';
        $migration->up();
    }

    /**
     * Names the models before the provider registers, as an application with a published config does.
     */
    protected function getPackageProviders($app)
    {
        $app['config']->set('access.models', [
            'rule'        => Rule::class,
            'inheritance' => Inheritance::class,
            'permission'  => Permission::class,
            'owner'       => Owner::class,
        ]);

        return [
            AccessRulesServiceProvider::class,
        ];
    }

    /**
     * A new object per call. Tests of version 2 get their entry point this way, and sharing one
     * object between owners is the mistake that ownerIsolationTest guards against.
     */
    protected function getAccessRules()
    {
        return new AccessRules;
    }
}

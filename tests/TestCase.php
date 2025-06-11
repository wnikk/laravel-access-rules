<?php

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Base test case for package tests.
 *
 * Sets up the environment and runs required migrations for testing.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Set up the test environment.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('access',
            require __DIR__.'/../config/access.php'
        );

        // Run migrations from database/migrations
        $migration = require __DIR__.'/../database/migrations/create_access_rules_tables.php.stub';
        $migration->up();
    }

    /**
     * Get package service providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        $app['config']->set('access.models', [
            'rule'        => \Wnikk\LaravelAccessRules\Models\Rule::class,
            'inheritance' => \Wnikk\LaravelAccessRules\Models\Inheritance::class,
            'permission'  => \Wnikk\LaravelAccessRules\Models\Permission::class,
            'owner'       => \Wnikk\LaravelAccessRules\Models\Owner::class,
        ]);
        return [
            \Wnikk\LaravelAccessRules\AccessRulesServiceProvider::class,
        ];
    }
}

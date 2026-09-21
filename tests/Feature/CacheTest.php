<?php

namespace Tests\Feature;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\TestUser;
use Wnikk\LaravelAccessRules\AccessRules;
use Wnikk\LaravelAccessRules\Facades\Access;

/**
 * What config/access.php and docs/basic-usage.md promise about the cache: permissions survive
 * a request, a change shows at once, and a store that is down slows authorization without
 * taking it offline.
 *
 * A broken store is a cache driver of Laravel that throws on every call, configured the way an
 * application configures a store. "The next request" is what Octane does between requests:
 * services that live as long as a request are forgotten.
 */
class CacheTest extends FeatureTestCase
{
    /** @var TestUser */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [TestUser::class, 'Role']);
        AccessRules::newRule('orders.view', 'View orders');
        AccessRules::newRule('orders.update', 'Update orders');

        $this->user = TestUser::factory()->make()->forceFill(['id' => 7]);
        $this->user->addPermission('orders.view');
    }

    public function test_the_next_request_takes_permissions_from_the_store(): void
    {
        $this->assertTrue($this->user->can('orders.view'));

        $this->nextRequest();
        DB::enableQueryLog();

        $this->assertTrue($this->user->can('orders.view'));
        $this->assertNull($this->user->hasPermission('orders.update'));
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_a_change_shows_at_once_and_in_the_next_request(): void
    {
        $this->assertNull($this->user->hasPermission('orders.update'));

        $this->user->addPermission('orders.update');
        $this->assertTrue($this->user->hasPermission('orders.update'), 'in the request that made it');

        $this->nextRequest();
        $this->assertTrue($this->user->hasPermission('orders.update'));

        $this->user->remPermission('orders.update');
        $this->nextRequest();
        $this->assertNull($this->user->hasPermission('orders.update'));
    }

    public function test_rows_changed_past_the_package_need_a_flush(): void
    {
        $this->assertTrue($this->user->can('orders.view'));

        DB::table(config('access.table_names.permission'))->delete();
        $this->nextRequest();
        $this->assertTrue($this->user->can('orders.view'), 'the cache knows nothing about plain SQL');

        Access::flush();
        $this->nextRequest();
        $this->assertFalse($this->user->can('orders.view'));
    }

    public function test_without_the_cache_every_request_reads_the_database_and_checks_inside_of_it_stay_free(): void
    {
        Config::set('access.cache.enabled', false);
        $this->nextRequest();

        DB::enableQueryLog();
        $this->assertTrue($this->user->can('orders.view'));
        $first = count(DB::getQueryLog());

        $this->assertTrue($this->user->can('orders.view'));
        $this->assertNull($this->user->hasPermission('orders.update'));

        $this->assertGreaterThan(0, $first);
        $this->assertCount($first, DB::getQueryLog(), 'the second and third check of a request cost nothing');
    }

    public function test_a_store_that_is_down_slows_authorization_and_does_not_break_it(): void
    {
        $this->useBrokenStore();
        Log::spy();

        $this->assertTrue($this->user->can('orders.view'), 'read from the database');
        $this->assertNull($this->user->hasPermission('orders.update'));

        // Changes go through too, and are seen.
        $this->user->addPermission('orders.update');
        $this->user->addProhibition('orders.view');
        Access::flush();
        $this->nextRequest();

        $this->assertTrue($this->user->hasPermission('orders.update'));
        $this->assertFalse($this->user->hasPermission('orders.view'));

        // One line per process: a store that is down fails on every check, and a line per check would bury the first.
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, '[AccessRules]'));
    }

    public function test_a_typo_in_the_name_of_the_store_costs_speed_and_not_an_outage(): void
    {
        Config::set('access.cache.store', 'no-such-store');
        $this->nextRequest();

        $this->assertTrue($this->user->can('orders.view'));
        $this->assertNull($this->user->hasPermission('orders.update'));
    }

    /**
     * Two applications that share one store read each other's permissions unless their prefixes differ.
     */
    public function test_the_prefix_keeps_applications_apart(): void
    {
        $this->assertTrue($this->user->can('orders.view'));

        Config::set('access.cache.key', 'another.application');
        DB::table(config('access.table_names.permission'))->delete();
        $this->nextRequest();

        $this->assertFalse($this->user->can('orders.view'), 'nothing cached under this prefix, so the database is asked');
    }

    private function nextRequest(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function useBrokenStore(): void
    {
        Cache::extend('broken', fn () => new Repository(new class extends ArrayStore
        {
            public function get($key)
            {
                throw new \RuntimeException('the cache server is down');
            }

            public function put($key, $value, $seconds)
            {
                throw new \RuntimeException('the cache server is down');
            }

            public function forever($key, $value)
            {
                throw new \RuntimeException('the cache server is down');
            }
        }));

        Config::set('cache.stores.broken', ['driver' => 'broken']);
        Config::set('access.cache.store', 'broken');
        $this->nextRequest();
    }
}

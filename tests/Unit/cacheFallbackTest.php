<?php

namespace Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\TestUser;
use Tests\TestCase;
use Wnikk\LaravelAccessRules\Authorization\DecisionPoint;
use Wnikk\LaravelAccessRules\Authorization\GateHook;
use Wnikk\LaravelAccessRules\Authorization\Permissions;
use Wnikk\LaravelAccessRules\Storage\PermissionCache;

/**
 * The cache of compiled permissions never gets in the way of authorization.
 *
 * Two promises are held here. Nothing touches the cache store while the application boots,
 * because commands and workers construct the package without checking a permission. And
 * a store that is down costs speed, never an exception: permissions come from the database
 * and the log gets one line.
 */
class cacheFallbackTest extends TestCase
{
    /** @var TestUser */
    protected $user;

    protected function setUp(): void
    {
        // TestCase::setUp() already calls AccessRules::resetCacheState().
        parent::setUp();

        Config::set('access.owner_types', [TestUser::class]);

        $this->getAccessRules()->newRule('cache-test-rule', 'Cache Test Rule');

        $this->user = TestUser::factory()->make();
        $this->user->addPermission('cache-test-rule');

        PermissionCache::resetState();
    }

    /**
     * Swaps the cache and forgets everything that lives as long as a request. Services resolved
     * earlier hold the previous cache, and a test would talk to a store that nobody reads.
     */
    private function useCache(array $config, ?Repository $store = null): void
    {
        $this->app->scoped(PermissionCache::class, fn () => new PermissionCache(
            $config + config('access.cache'),
            $store ? fn () => $store : null
        ));

        // Everything that lives as long as a request holds the previous cache
        $this->app->forgetScopedInstances();
    }

    private function brokenStore(): Repository
    {
        $stub = $this->createStub(Repository::class);
        foreach (['has', 'get', 'put', 'set', 'forget', 'forever'] as $method) {
            $stub->method($method)->willThrowException(new \RuntimeException('Cache unavailable'));
        }

        return $stub;
    }

    /**
     * A working store that records its calls. A mock would say which calls happen but not whether
     * a value written by one request reaches the next, and two tests need exactly that.
     */
    private function spyStore(): Repository
    {
        return new class(new ArrayStore) extends Repository
        {
            public array $calls = ['get' => [], 'put' => [], 'forever' => []];

            public function get($key, $default = null): mixed
            {
                $this->calls['get'][] = $key;

                return parent::get($key, $default);
            }

            public function put($key, $value, $ttl = null)
            {
                $this->calls['put'][] = $key;

                return parent::put($key, $value, $ttl);
            }

            public function forever($key, $value)
            {
                $this->calls['forever'][] = $key;

                return parent::forever($key, $value);
            }
        };
    }

    public function test_constructor_does_not_access_cache_store(): void
    {
        $resolved = 0;
        $this->app->scoped(PermissionCache::class, function () use (&$resolved) {
            return new PermissionCache(config('access.cache'), function () use (&$resolved) {
                $resolved++;

                return new Repository(new ArrayStore);
            });
        });
        $this->app->forgetScopedInstances();

        // Everything is constructed, nothing is checked yet
        $this->app->make(Permissions::class);
        $this->app->make(DecisionPoint::class);
        $this->app->make(GateHook::class);
        $this->getAccessRules()->setOwner($this->user);

        $this->assertSame(0, $resolved, 'Cache store must not be resolved before the first permission check');
    }

    public function test_cache_disabled_bypasses_cache_entirely(): void
    {
        $store = $this->spyStore();
        $this->useCache(['enabled' => false], $store);

        $this->assertTrue($this->user->hasPermission('cache-test-rule'));
        $this->assertSame(['get' => [], 'put' => [], 'forever' => []], $store->calls);
    }

    public function test_cache_disabled_returns_null_for_missing_permission(): void
    {
        $this->useCache(['enabled' => false]);

        $this->assertNull($this->user->hasPermission('missing-rule'));
    }

    public function test_cache_enabled_no_check_uses_cache_without_smoke_test(): void
    {
        $store = $this->spyStore();
        $this->useCache(['check' => false], $store);

        $this->assertTrue($this->user->hasPermission('cache-test-rule'));

        $touched = [...$store->calls['get'], ...$store->calls['forever']];
        $this->assertNotContains(config('access.cache.key').'.cache_test', $touched);
        $this->assertNotEmpty($store->calls['put'], 'Compiled permissions must be written to the store');
    }

    public function test_cache_enabled_no_check_returns_null_for_missing_permission(): void
    {
        $this->useCache(['check' => false], $this->spyStore());

        $this->assertNull($this->user->hasPermission('missing-rule'));
    }

    public function test_smoke_test_runs_only_once_per_process(): void
    {
        $testKey = config('access.cache.key').'.cache_test';

        $first = $this->spyStore();
        $this->useCache([], $first);
        $this->user->hasPermission('cache-test-rule');
        $this->assertContains($testKey, $first->calls['forever']);

        // A new request in the same process: result of the check is remembered
        $second = $this->spyStore();
        $this->useCache([], $second);
        $this->user->hasPermission('cache-test-rule');

        $this->assertNotContains($testKey, $second->calls['get']);
        $this->assertNotContains($testKey, $second->calls['forever']);
    }

    public function test_smoke_test_is_read_only_when_key_already_exists(): void
    {
        $testKey = config('access.cache.key').'.cache_test';

        $store = $this->spyStore();
        $store->forever($testKey, 123.0);
        $store->calls = ['get' => [], 'put' => [], 'forever' => []];

        $this->useCache([], $store);
        $this->user->hasPermission('cache-test-rule');

        $this->assertContains($testKey, $store->calls['get']);
        $this->assertNotContains($testKey, $store->calls['forever']);
    }

    public function test_has_permission_does_not_throw_on_broken_cache(): void
    {
        $this->useCache([], $this->brokenStore());

        $this->assertTrue($this->user->hasPermission('cache-test-rule'));
    }

    public function test_no_permission_does_not_throw_on_broken_cache(): void
    {
        $this->useCache([], $this->brokenStore());

        $this->assertNull($this->user->hasPermission('missing-rule'));
    }

    public function test_warning_logged_once_on_cache_failure(): void
    {
        Log::shouldReceive('warning')->once();
        Log::shouldReceive('channel')->andReturnSelf();

        $this->useCache([], $this->brokenStore());

        $this->user->hasPermission('cache-test-rule');
        $this->user->hasPermission('missing-rule');
        $this->getAccessRules()->setOwner($this->user)->hasPermission('cache-test-rule');
    }

    public function test_fallback_to_db_when_cache_permanently_disabled(): void
    {
        $this->useCache([], $this->brokenStore());
        $this->assertTrue($this->user->hasPermission('cache-test-rule'));

        // Next request of the same process does not even try the store, changes are visible at once
        $this->useCache([], $this->brokenStore());
        $this->user->remPermission('cache-test-rule');

        $this->assertNull($this->user->hasPermission('cache-test-rule'));
    }

    public function test_changes_do_not_throw_when_cache_is_unavailable(): void
    {
        $this->useCache([], $this->brokenStore());

        $this->getAccessRules()->newRule('one-more-rule');
        $this->user->addPermission('one-more-rule');
        $this->getAccessRules()->flush();

        $this->assertTrue($this->user->hasPermission('one-more-rule'));
    }

    public function test_permissions_served_from_memory_on_second_call(): void
    {
        $store = $this->spyStore();
        $this->useCache([], $store);

        $this->user->hasPermission('cache-test-rule');
        $reads = count($store->calls['get']);

        $this->user->hasPermission('cache-test-rule');
        $this->user->hasPermission('missing-rule');
        $this->user->can('cache-test-rule');

        $this->assertSame($reads, count($store->calls['get']), 'The store must be read once per owner within a request');
    }

    public function test_permissions_are_shared_between_requests_through_the_store(): void
    {
        $store = $this->spyStore();
        $this->useCache([], $store);
        $this->user->hasPermission('cache-test-rule');
        $this->assertCount(1, $store->calls['put']);

        // Next request: permissions come from the store, nothing is built and written again
        $this->useCache([], $store);
        $this->assertTrue($this->user->hasPermission('cache-test-rule'));
        $this->assertCount(1, $store->calls['put']);
    }
}

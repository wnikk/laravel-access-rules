<?php
namespace Tests\Unit;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Tests\Fixtures\TestUser;
use Wnikk\LaravelAccessRules\AccessRules;

/**
 * Tests for lazy cache initialisation, the enabled/check config flags,
 * and graceful fallback to DB queries when the cache store is unavailable.
 *
 * Static state ($cacheCheckResult, $cacheWarningLogged) is reset before
 * every test via TestCase::setUp() → AccessRules::resetCacheState().
 *
 * Return-value semantics of hasPermission():
 *   true  — permission explicitly granted
 *   false — permission explicitly denied (prohibition)
 *   null  — no entry at all (Gate abstains)
 *
 * ┌─────────────────────────────────────────────────────────┐
 * │  enabled │ check │ Expected behaviour                   │
 * ├──────────┼───────┼──────────────────────────────────────┤
 * │  false   │  any  │ Always query DB, never touch cache   │
 * │  true    │ false │ Use cache, skip smoke test           │
 * │  true    │ true  │ Smoke test once per process (write   │
 * │          │       │ once; read-only if key exists)       │
 * └─────────────────────────────────────────────────────────┘
 */
class cacheFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        // TestCase::setUp() already calls AccessRules::resetCacheState().
        parent::setUp();

        Config::set('access.owner_types', [TestUser::class]);

        // Creating the rule calls newRule() which does NOT touch the cache,
        // so $cacheCheckResult stays null here.
        $acr = $this->getAccessRules();
        $acr->newRule('cache-test-rule', 'Cache Test Rule');
    }

    // -----------------------------------------------------------------------
    // Reflection helpers
    // -----------------------------------------------------------------------

    private function getProp(object $obj, string $property): mixed
    {
        $ref = new \ReflectionProperty($obj, $property);
        $ref->setAccessible(true);
        return $ref->getValue($obj);
    }

    private function setProp(object $obj, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $property);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }

    private function getStaticProp(string $class, string $property): mixed
    {
        $ref = new \ReflectionProperty($class, $property);
        $ref->setAccessible(true);
        return $ref->getValue(null);
    }

    private function setStaticProp(string $class, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($class, $property);
        $ref->setAccessible(true);
        $ref->setValue(null, $value);
    }

    /**
     * Override specific cache config keys.
     *
     * Must update BOTH the Laravel config and the already-initialised static
     * $cacheParams array, because:
     *  - new AccessRules() calls initializeAccessRulesCache() which re-reads
     *    config('access.cache') and would overwrite a static-only patch;
     *  - instances already constructed have $cacheParams set from the old
     *    config, so we patch the static array for them too.
     */
    private function setCacheConfig(array $overrides): void
    {
        foreach ($overrides as $k => $v) {
            Config::set("access.cache.{$k}", $v);
        }
        $params = $this->getStaticProp(AccessRules::class, 'cacheParams') ?? [];
        foreach ($overrides as $k => $v) {
            $params[$k] = $v;
        }
        $this->setStaticProp(AccessRules::class, 'cacheParams', $params);
    }

    /**
     * Build a Repository stub that throws on every operation (fully broken store).
     *
     * Uses createStub() rather than createMock() because PHPUnit 12 generates
     * a Notice when createMock() is called without any expects() assertions —
     * createStub() is the correct API for pure test doubles with no expectations.
     */
    private function brokenCacheStore(): Repository
    {
        $stub = $this->createStub(Repository::class);
        foreach (['has', 'get', 'put', 'set', 'forget'] as $method) {
            $stub->method($method)
                ->willThrowException(new \RuntimeException('Cache unavailable'));
        }
        return $stub;
    }

    /**
     * Inject a broken store into an AccessRules instance and reset ALL
     * lazy-init flags so ensureCacheInitialized() runs fresh against the stub.
     *
     * IMPORTANT: addPermission() / forgetCachedPermissions() called in setUp()
     * may have already set $cacheCheckResult = true via the real store.
     * This helper resets that static flag so the broken store is actually exercised.
     */
    private function injectBrokenCache(AccessRules $acr): void
    {
        $this->setProp($acr, 'cache', $this->brokenCacheStore());
        $this->setProp($acr, 'cacheAvailable', null);
        // Reset process-level static state so the smoke test re-runs on the stub.
        $this->setStaticProp(AccessRules::class, 'cacheCheckResult', null);
        $this->setStaticProp(AccessRules::class, 'cacheWarningLogged', false);
        // Force check=true so the broken store is exercised by the smoke test.
        $this->setCacheConfig(['check' => true, 'enabled' => true]);
    }

    // -----------------------------------------------------------------------
    // Boot / construction
    // -----------------------------------------------------------------------

    /**
     * The constructor must NOT touch the cache store — boot must be cheap.
     */
    public function test_constructor_does_not_access_cache_store(): void
    {
        $acr = new AccessRules();

        $this->assertNull(
            $this->getProp($acr, 'cache'),
            'Cache store must NOT be resolved during construction'
        );
    }

    // -----------------------------------------------------------------------
    // enabled = false
    // -----------------------------------------------------------------------

    /**
     * When cache.enabled = false every hasPermission() goes to DB;
     * the cache store is never resolved.
     */
    public function test_cache_disabled_bypasses_cache_entirely(): void
    {
        $this->setCacheConfig(['enabled' => false]);

        $user = TestUser::factory()->make();
        $user->addPermission('cache-test-rule');

        $acr = new AccessRules();
        $acr->setOwner($user);

        $result = $acr->hasPermission('cache-test-rule');

        $this->assertTrue($result, 'DB fallback must work when cache is disabled');
        $this->assertNull(
            $this->getProp($acr, 'cache'),
            'Cache store must never be resolved when enabled=false'
        );
        $this->assertFalse(
            $this->getProp($acr, 'cacheAvailable'),
            'cacheAvailable must be false when enabled=false'
        );
        // enabled=false is not a cache failure, so $cacheCheckResult must stay null.
        $this->assertNull(
            $this->getStaticProp(AccessRules::class, 'cacheCheckResult'),
            'cacheCheckResult must stay null when enabled=false (not a failure)'
        );
    }

    /**
     * enabled=false: no permission assigned → hasPermission() returns null (Gate abstains).
     */
    public function test_cache_disabled_returns_null_for_missing_permission(): void
    {
        $this->setCacheConfig(['enabled' => false]);

        $user = TestUser::factory()->make(); // no permissions assigned

        $acr = new AccessRules();
        $acr->setOwner($user);

        // No permission entry → null (Gate abstains), not false (explicit denial).
        $this->assertNull($acr->hasPermission('cache-test-rule'));
    }

    // -----------------------------------------------------------------------
    // enabled = true, check = false
    // -----------------------------------------------------------------------

    /**
     * When check=false the cache is used without a smoke test.
     * $cacheCheckResult is set to true immediately (assumed OK).
     */
    public function test_cache_enabled_no_check_uses_cache_without_smoke_test(): void
    {
        $this->setCacheConfig(['enabled' => true, 'check' => false]);

        $user = TestUser::factory()->make();
        $user->addPermission('cache-test-rule');

        $acr = new AccessRules();
        $acr->setOwner($user);

        $this->assertTrue($acr->hasPermission('cache-test-rule'));

        $this->assertTrue(
            $this->getStaticProp(AccessRules::class, 'cacheCheckResult'),
            'cacheCheckResult must be true when check=false (assumed OK)'
        );
        $this->assertNotNull(
            $this->getProp($acr, 'cache'),
            'Cache store must be resolved when check=false'
        );
    }

    /**
     * check=false: no permission assigned → null (Gate abstains).
     */
    public function test_cache_enabled_no_check_returns_null_for_missing_permission(): void
    {
        $this->setCacheConfig(['enabled' => true, 'check' => false]);

        $user = TestUser::factory()->make();

        $acr = new AccessRules();
        $acr->setOwner($user);

        $this->assertNull($acr->hasPermission('cache-test-rule'));
    }

    // -----------------------------------------------------------------------
    // enabled = true, check = true — broken cache store
    // -----------------------------------------------------------------------

    /**
     * When check=true and the cache store is broken, hasPermission() must
     * NOT throw — it must return the correct result from DB.
     */
    public function test_has_permission_does_not_throw_on_broken_cache(): void
    {
        $user = TestUser::factory()->make();
        $user->addPermission('cache-test-rule');

        $acr = new AccessRules();
        $acr->setOwner($user);
        $this->injectBrokenCache($acr);

        $result = $acr->hasPermission('cache-test-rule');

        $this->assertTrue($result, 'hasPermission() must return true via DB fallback');
        $this->assertFalse(
            $this->getProp($acr, 'cacheAvailable'),
            'cacheAvailable must be false after smoke-test failure'
        );
        $this->assertFalse(
            $this->getStaticProp(AccessRules::class, 'cacheCheckResult'),
            'cacheCheckResult must be false after smoke-test failure'
        );
    }

    /**
     * No permission + broken cache must return null (abstain), not throw.
     */
    public function test_no_permission_does_not_throw_on_broken_cache(): void
    {
        $user = TestUser::factory()->make();

        $acr = new AccessRules();
        $acr->setOwner($user);
        $this->injectBrokenCache($acr);

        // null = Gate abstains (no entry found), not an error.
        $this->assertNull($acr->hasPermission('cache-test-rule'));
    }

    /**
     * A warning prefixed with [AccessRules] must be logged exactly once,
     * even when hasPermission() is called multiple times.
     */
    public function test_warning_logged_once_on_cache_failure(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn(string $msg) => str_contains($msg, '[AccessRules]'));

        $user = TestUser::factory()->make();
        $acr  = new AccessRules();
        $acr->setOwner($user);
        $this->injectBrokenCache($acr);

        // Three calls — warning must fire exactly once.
        $acr->hasPermission('cache-test-rule');
        $acr->hasPermission('cache-test-rule');
        $acr->hasPermission('cache-test-rule');
    }

    // -----------------------------------------------------------------------
    // enabled = true, check = true — process-level static optimisation
    // -----------------------------------------------------------------------

    /**
     * The smoke test (including the write) must run at most once per PHP
     * process. A second AccessRules instance must inherit $cacheCheckResult
     * and never call set() on the cache store again.
     */
    public function test_smoke_test_runs_only_once_per_process(): void
    {
        $user = TestUser::factory()->make();
        $user->addPermission('cache-test-rule');

        // First instance — runs the smoke test (includes the write).
        $acr1 = new AccessRules();
        $acr1->setOwner($user);
        $this->assertTrue($acr1->hasPermission('cache-test-rule'));

        $this->assertTrue(
            $this->getStaticProp(AccessRules::class, 'cacheCheckResult'),
            'cacheCheckResult must be true after first successful smoke test'
        );

        // Second instance — inject a spy that allows get/has/put for normal
        // cache operations but asserts that set() is NEVER called.
        // If the smoke test were to re-run it would call set() → test fails.
        $spy = $this->createMock(Repository::class);
        $spy->expects($this->never())->method('set'); // no smoke-test write
        $spy->method('get')->willReturn(null);          // empty cache
        $spy->method('has')->willReturn(false);
        $spy->method('put')->willReturn(true);

        $acr2 = new AccessRules();
        $acr2->setOwner($user);
        // Pre-set the store; getCacheStoreFromConfig() will be skipped.
        // cacheAvailable is null → ensureCacheInitialized() runs, but must
        // short-circuit via cacheCheckResult=true (already set by acr1).
        $this->setProp($acr2, 'cache', $spy);

        $result = $acr2->hasPermission('cache-test-rule');

        $this->assertTrue(
            $result,
            'Second instance must serve correct result without re-running the smoke test'
        );
        $this->assertTrue(
            $this->getStaticProp(AccessRules::class, 'cacheCheckResult'),
            'cacheCheckResult must remain true after second instance'
        );
    }

    /**
     * When the cache already contains the test key (written by a previous
     * process), ensureCacheInitialized() must pass with a single read.
     * set() must never be called — no redundant write.
     */
    public function test_smoke_test_is_read_only_when_key_already_exists(): void
    {
        // Build the test key the same way the trait does.
        $acr0   = new AccessRules();
        $params = $this->getStaticProp(AccessRules::class, 'cacheParams');
        $testKey = ($params['key'] ?? 'access_rules.cache.') . '.cache_test';

        // Spy: the test key "already exists" (simulates a previous process write).
        $spy = $this->createMock(Repository::class);
        $spy->method('get')->willReturnCallback(
            fn($key) => $key === $testKey ? 12345.0 : null
        );
        $spy->method('has')->willReturn(false);
        $spy->method('put')->willReturn(true);
        $spy->expects($this->never())->method('set'); // read-only: no write allowed

        // Reset static state so the smoke-test path actually runs on the spy.
        $this->setStaticProp(AccessRules::class, 'cacheCheckResult', null);
        $this->setCacheConfig(['enabled' => true, 'check' => true]);

        $acr = new AccessRules();
        $acr->setOwner(TestUser::factory()->make());
        $this->setProp($acr, 'cache', $spy);
        $this->setProp($acr, 'cacheAvailable', null);

        // Trigger ensureCacheInitialized() via a permission check.
        $acr->hasPermission('cache-test-rule');

        $this->assertTrue(
            $this->getStaticProp(AccessRules::class, 'cacheCheckResult'),
            'cacheCheckResult must be true when test key already exists (read-only path)'
        );
    }

    // -----------------------------------------------------------------------
    // Direct cacheAvailable=false fallback
    // -----------------------------------------------------------------------

    /**
     * When cacheAvailable is pre-set to false (after a detected failure),
     * hasPermission() must still resolve correctly from DB.
     */
    public function test_fallback_to_db_when_cache_permanently_disabled(): void
    {
        $user = TestUser::factory()->make();
        $user->addPermission('cache-test-rule');

        $acr = new AccessRules();
        $acr->setOwner($user);
        $this->setProp($acr, 'cacheAvailable', false);

        $this->assertTrue(
            $acr->hasPermission('cache-test-rule'),
            'Permission must resolve from DB when cacheAvailable=false'
        );
    }

    // -----------------------------------------------------------------------
    // In-memory caching after first load
    // -----------------------------------------------------------------------

    /**
     * After the first DB+cache load, $permissions is populated in memory.
     * A second call on the same instance must be served from that array —
     * no further DB or cache access needed.
     */
    public function test_permissions_served_from_memory_on_second_call(): void
    {
        $user = TestUser::factory()->make();
        $user->addPermission('cache-test-rule');

        $acr = new AccessRules();
        $acr->setOwner($user);

        $this->assertTrue($acr->hasPermission('cache-test-rule'));

        $permissions = $this->getProp($acr, 'permissions');
        $this->assertIsArray($permissions);
        $this->assertNotEmpty($permissions, '$permissions must be populated after first call');

        // Second call — $permissions already set → loadPermissions() returns early.
        $this->assertTrue($acr->hasPermission('cache-test-rule'));
    }

    // -----------------------------------------------------------------------
    // Public invalidation methods must never throw
    // -----------------------------------------------------------------------

    /**
     * forgetCachedPermissions() must return false silently when cache is down.
     */
    public function test_forget_cache_does_not_throw_when_unavailable(): void
    {
        $acr = new AccessRules();
        $this->setProp($acr, 'cacheKey', 'access_rules.cache..0.1');
        $this->setProp($acr, 'cacheAvailable', false);

        $this->assertFalse($acr->forgetCachedPermissions());
    }

    /**
     * clearAllCachedPermissions() must return silently when cache is down.
     */
    public function test_clear_all_cache_does_not_throw_when_unavailable(): void
    {
        $acr = new AccessRules();
        $this->setProp($acr, 'cacheAvailable', false);

        $acr->clearAllCachedPermissions();
        $this->assertTrue(true); // no exception — test passes
    }

    /**
     * forgetSelectedCachePermission() must return false silently when cache is down.
     */
    public function test_forget_selected_cache_does_not_throw_when_unavailable(): void
    {
        $acr = new AccessRules();
        $this->setProp($acr, 'cacheAvailable', false);

        $result = $acr->forgetSelectedCachePermission([
            ['type' => 0, 'id' => 1],
        ]);

        $this->assertFalse($result);
    }
}

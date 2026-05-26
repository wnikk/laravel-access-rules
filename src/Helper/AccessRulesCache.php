<?php
namespace Wnikk\LaravelAccessRules\Helper;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;

/**
 * Trait AccessRulesCache
 *
 * This trait provides methods to manage caching of access rules.
 * It allows for setting, getting, and clearing cached permissions.
 *
 * Cache is initialized lazily on the first real permission check,
 * so a broken or unavailable cache store never blocks application startup.
 * If the cache store fails, the package transparently falls back to
 * direct database queries and logs a one-time warning.
 */
trait AccessRulesCache
{
    /** @var \Illuminate\Contracts\Cache\Repository|null */
    protected $cache = null;

    /** @var array{enabled:bool, expiration_time:\DateInterval|int, key:string, store:string, check:bool} */
    protected static $cacheParams;

    /** @var string|null */
    protected $cacheKey;

    /** @var array<string>|null */
    protected $permissions;

    /**
     * Per-instance availability flag.
     *
     * null  = not yet determined for this instance
     * true  = cache is ready to use
     * false = cache unavailable; fall back to DB
     *
     * @var bool|null
     */
    protected $cacheAvailable = null;

    /**
     * Process-level result of the one-time smoke test.
     *
     * Stored statically so the write half of the smoke test happens
     * at most once per PHP worker process (subsequent instances just
     * inherit the result without touching the cache store again).
     *
     * null  = smoke test has not run yet this process
     * true  = smoke test passed (or check=false, assumed OK)
     * false = smoke test failed; all instances fall back to DB
     *
     * @var bool|null
     */
    protected static ?bool $cacheCheckResult = null;

    /** @var bool Ensures the "cache unavailable" warning is logged only once per process */
    protected static bool $cacheWarningLogged = false;

    /**
     * Initialize the trait (lightweight — no cache store access here).
     *
     * The cache store is resolved lazily on the first permission check
     * so that a misconfigured or temporarily unavailable cache cannot
     * block application boot, queue workers, or artisan commands.
     *
     * @return void
     */
    protected function initializeAccessRulesCache()
    {
        self::$cacheParams = config('access.cache', []);
        if (!is_array(self::$cacheParams)) { self::$cacheParams = []; }
        self::$cacheParams += [
            'enabled'         => true,   // master switch; false = always query DB
            'expiration_time' => 24 * 60, // default expiration time in minutes
            'key'             => 'access_rules.cache.',
            'store'           => 'default',
            'check'           => true,   // perform smoke test on first use
        ];
    }

    /**
     * Lazily initialise and (optionally) verify the cache store.
     *
     * Called before every cache read/write operation. Returns true when
     * the cache is ready to use; false when it should be bypassed silently.
     *
     * Guarantees:
     *  - Cache store is never touched during application boot.
     *  - The write half of the smoke test runs at most once per PHP process
     *    (result stored in static $cacheCheckResult).
     *  - When the cache store already contains the test key from a previous
     *    process, only a read is performed — no redundant write.
     *  - Any Throwable is caught; no exception propagates to the caller.
     *
     * @return bool
     */
    protected function ensureCacheInitialized(): bool
    {
        // Fast path — already determined for this instance.
        if ($this->cacheAvailable !== null) {
            return $this->cacheAvailable;
        }

        // Respect the master switch.
        if (empty(self::$cacheParams['enabled'])) {
            return $this->cacheAvailable = false;
        }

        // Resolve the cache store for this instance only when not pre-set
        // (pre-setting $cache allows injecting a test double without it being
        // overwritten by getCacheStoreFromConfig).
        if ($this->cache === null) {
            try {
                $this->cache = $this->getCacheStoreFromConfig();
            } catch (\Throwable $e) {
                $this->logCacheWarning('Cannot initialize cache store', $e);
                return $this->cacheAvailable = self::$cacheCheckResult = false;
            }
        }

        // Reuse the process-level smoke-test result when already known.
        if (self::$cacheCheckResult !== null) {
            return $this->cacheAvailable = self::$cacheCheckResult;
        }

        // check=false → trust the store without verifying.
        if (empty(self::$cacheParams['check'])) {
            return $this->cacheAvailable = self::$cacheCheckResult = true;
        }

        // One-time smoke test.
        // Strategy: read first — if the test key exists from any previous
        // write (this or another process) the check passes with a single read.
        // Only when the key is absent do we write it once and verify.
        try {
            $testKey = self::$cacheParams['key'] . '.cache_test';

            if ($this->cache->get($testKey) !== null) {
                // Key already present — read-only verification passed.
                return $this->cacheAvailable = self::$cacheCheckResult = true;
            }

            // Key absent: write once, then read back to confirm.
            $stamp = microtime(true);
            $this->cache->set($testKey, $stamp);
            if ($this->cache->get($testKey) !== $stamp) {
                throw new \RuntimeException('Cache read/write value mismatch');
            }
        } catch (\Throwable $e) {
            $this->logCacheWarning('Cache is not working, falling back to direct DB queries', $e);
            return $this->cacheAvailable = self::$cacheCheckResult = false;
        }

        return $this->cacheAvailable = self::$cacheCheckResult = true;
    }

    /**
     * Reset all process-level static state.
     *
     * Must be called in test tearDown / setUp so that static flags
     * ($cacheCheckResult, $cacheWarningLogged) do not leak between tests.
     *
     * @internal Only intended for use in automated test suites.
     * @return void
     */
    public static function resetCacheState(): void
    {
        self::$cacheCheckResult  = null;
        self::$cacheWarningLogged = false;
    }

    /**
     * Log a cache-related warning exactly once per process.
     *
     * @param string          $message
     * @param \Throwable|null $e
     * @return void
     */
    protected function logCacheWarning(string $message, \Throwable $e = null): void
    {
        if (self::$cacheWarningLogged) { return; }
        self::$cacheWarningLogged = true;

        try {
            $full = '[AccessRules] ' . $message;
            if ($e) { $full .= ': ' . $e->getMessage(); }
            app('log')->warning($full);
        } catch (\Throwable $ignored) {
            // Logger itself might not be ready — silently skip.
        }
    }

    /**
     * Returns an object for cache Manager
     *
     * @return Repository
     */
    protected function getCacheStoreFromConfig(): Repository
    {
        $cacheManager = app(CacheManager::class);

        // the 'default' fallback here is from the access.php config file,
        // where 'default' means to use config(cache.default)
        $cacheDriver = self::$cacheParams['store'] ?? 'default';

        // when 'default' is specified, no action is required since we already have the default instance
        if ($cacheDriver === 'default') {
            return $cacheManager->store();
        }

        // if an undefined cache store is specified, fallback to 'array' which is Laravel's closest equiv to 'none'
        if (!array_key_exists($cacheDriver, config('cache.stores', []))) {
            $cacheDriver = 'array';
        }

        return $cacheManager->store($cacheDriver);
    }

    /**
     * Set the owner id for cache
     *
     * @param int   $type
     * @param mixed $id
     * @return void
     */
    protected function setOwnerCache(int $type, $id = null)
    {
        $this->cacheKey = $this->makeCacheKey($type, $id);
    }

    /**
     * Make key for cache
     *
     * @param int   $type
     * @param mixed $id
     * @return string
     */
    protected function makeCacheKey(int $type, $id = null): string
    {
        return self::$cacheParams['key'] . '.' . $type . '.' . $id;
    }

    /**
     * Loads the rules from cache.
     * Returns false when cache is unavailable (caller falls back to DB).
     *
     * @return bool
     */
    protected function loadCachePermissions(): bool
    {
        if (!$this->cacheKey || !$this->ensureCacheInitialized()) { return false; }

        try {
            if (!$this->cache->has($this->cacheKey)) { return false; }
            $this->permissions = $this->cache->get($this->cacheKey);
            return true;
        } catch (\Throwable $e) {
            $this->logCacheWarning('Failed to read permissions from cache', $e);
            $this->cacheAvailable = false;
            return false;
        }
    }

    /**
     * Saves previously loaded rules to cache.
     * Silently skips when cache is unavailable.
     *
     * @return bool
     */
    protected function saveCachePermissions(): bool
    {
        if (!$this->cacheKey || !$this->ensureCacheInitialized()) { return false; }

        try {
            $this->updateCacheList();
            return $this->cache->put(
                $this->cacheKey,
                $this->permissions,
                self::$cacheParams['expiration_time'] * 60
            );
        } catch (\Throwable $e) {
            $this->logCacheWarning('Failed to write permissions to cache', $e);
            $this->cacheAvailable = false;
            return false;
        }
    }

    /**
     * Save all keys list, for clean
     *
     * @return bool
     */
    protected function updateCacheList(): bool
    {
        $deadline = time() + self::$cacheParams['expiration_time'] * 60;
        $key = self::$cacheParams['key'] . '_all';

        $all = $this->cache->get($key) ?? [];
        $all[$this->cacheKey] = $deadline;

        foreach ($all as $k => $t) {
            if ($t < time()) { unset($all[$k]); }
        }

        return $this->cache->put(
            $key,
            $all,
            self::$cacheParams['expiration_time'] * 60
        );
    }

    /**
     * Flush the cache for the current owner.
     *
     * @return bool
     */
    public function forgetCachedPermissions(): bool
    {
        if (!$this->cacheKey) { return false; }

        $this->permissions = null;

        if (!$this->ensureCacheInitialized()) { return false; }

        try {
            return $this->cache->forget($this->cacheKey);
        } catch (\Throwable $e) {
            $this->logCacheWarning('Failed to invalidate cached permissions', $e);
            $this->cacheAvailable = false;
            return false;
        }
    }

    /**
     * Clear cache for selected owners.
     *
     * @param array<array{type: int, id: mixed}> $selected
     * @return bool
     */
    public function forgetSelectedCachePermission(array $selected): bool
    {
        if (!$this->ensureCacheInitialized()) { return false; }

        try {
            foreach ($selected as $one) {
                $this->cache->forget($this->makeCacheKey(
                    (string) ($one['type'] ?? $one[0] ?? $one),
                    $one['id'] ?? $one[1] ?? null
                ));
            }
            return true;
        } catch (\Throwable $e) {
            $this->logCacheWarning('Failed to invalidate selected cached permissions', $e);
            $this->cacheAvailable = false;
            return false;
        }
    }

    /**
     * Clear all cached permissions for all owners.
     *
     * @return void
     */
    public function clearAllCachedPermissions(): void
    {
        $this->permissions = null;

        if (!$this->ensureCacheInitialized()) { return; }

        try {
            $key = self::$cacheParams['key'] . '_all';
            $all = $this->cache->get($key) ?? [];
            $all[$key] = 1;

            foreach ($all as $item => $timer) {
                $this->cache->forget($item);
            }
        } catch (\Throwable $e) {
            $this->logCacheWarning('Failed to clear all cached permissions', $e);
            $this->cacheAvailable = false;
        }
    }
}

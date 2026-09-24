<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Protected\Storage;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Keeps compiled permissions of owners between requests and drops all of them on any change.
 *
 * Compiling permissions of one owner costs three queries and a walk through its inheritance.
 * A page with fifty checks cannot pay that on every request, so Authorization\Permissions asks
 * this class first. It belongs to the storage layer and knows nothing about what it stores.
 *
 * Every key carries a generation token, and a change replaces the token. Entries of the old
 * generation stay in the store until their lifetime ends and nobody reads them again. Version 2
 * kept a list of all keys and deleted them one by one: two writers lost each other's keys, and an
 * owner with heirs forced a full scan. Cache tags do the same job, but the file and database
 * drivers do not support them.
 *
 * The class never throws because of the store. A dead Redis must not take authorization down
 * with it, so every failure turns the cache off for this instance, logs one warning per process
 * and lets the caller build from the database.
 *
 * Nothing here remembers values within a request. Authorization\Permissions does that, because
 * it knows when an entry stops being valid for the running process.
 *
 * @internal Implementation of the package, not an entry point for applications. The public API is the facade Access, the traits and the classes outside src/Protected; AGENTS.md lists them.
 */
final class PermissionCache
{
    /**
     * Result of the store check, shared by all instances of a PHP process. A worker that serves
     * a thousand requests checks the store once, not a thousand times. Null means "not checked".
     */
    private static ?bool $storeWorks = null;

    private static bool $warningLogged = false;

    private ?Repository $store = null;

    /** Null until the first read or write: resolving the store is what boot must not pay for. */
    private ?bool $available = null;

    private ?string $generation = null;

    /**
     * Counts generation switches made through this instance. Authorization\Permissions compares
     * it with its own copy to learn that what it remembers for the request is outdated.
     */
    public private(set) int $epoch = 0;

    /** Depth of nested batch() calls. */
    private int $paused = 0;

    private bool $pending = false;

    /** @var array{enabled:bool, expiration_time:int, key:string, store:string, check:bool} */
    private array $config;

    /**
     * @param (Closure(): Repository)|null $storeResolver Replaces the store named in config. Tests use it to hand in a broken or a counting store.
     */
    public function __construct(array $config = [], private ?Closure $storeResolver = null)
    {
        // Lifetime is one day, in minutes. Generations make entries unreachable at once, so the
        // number only decides how long dead entries occupy the store.
        $this->config = $config + [
            'enabled'         => true,
            'expiration_time' => 24 * 60,
            'key'             => 'access_rules.cache',
            'store'           => 'default',
            'check'           => true,
        ];
    }

    /**
     * @param string           $key   Address of an owner, "type.id".
     * @param Closure(): array $build Runs on a miss and when the store is down.
     */
    public function remember(string $key, Closure $build): array
    {
        $cached = $this->read($key);
        if ($cached !== null) {
            return $cached;
        }

        $built = $build();
        $this->write($key, $built);

        return $built;
    }

    /**
     * Makes everything cached so far unreachable.
     *
     * The caller writes to the database first and calls this second. The opposite order opens a
     * window: another request rebuilds from the old rows, caches the result under the new
     * generation, and the stale answer lives for a day.
     *
     * Inside a transaction the window stays open until commit, because other connections still
     * read the old rows. The generation switches now, for this process, and once more after
     * commit, for everyone who cached in between.
     *
     * @param ConnectionInterface|null $db Connection the change went through. Without it the second switch is skipped.
     */
    public function bump(?ConnectionInterface $db = null): void
    {
        if ($this->paused > 0) {
            $this->pending = true;

            return;
        }

        $this->switchGeneration();

        try {
            if ($db && $db->transactionLevel() > 0 && method_exists($db, 'afterCommit')) {
                $db->afterCommit(fn () => $this->switchGeneration());
            }
        } catch (Throwable) {
            // A connection created outside of the database manager has no transactions manager
            // and throws here. The first switch has already happened, so the change is not lost.
        }
    }

    /**
     * Runs many changes and switches the generation once, after the last of them.
     *
     * A seeder that grants two hundred permissions would otherwise write two hundred tokens.
     * The price is visible inside the closure: this process keeps answering from what it
     * compiled before the batch started.
     *
     * No bulk "grant many" exists next to it. Permissions shared by many owners belong to a role
     * that the owners inherit from.
     *
     * @template T
     *
     * @param  Closure(): T $changes
     * @return T
     */
    public function batch(Closure $changes, ?ConnectionInterface $db = null): mixed
    {
        $this->paused++;

        try {
            return $changes();
        } finally {
            // The switch also happens when $changes throws: a part of the changes may be written.
            if (--$this->paused === 0 && $this->pending) {
                $this->pending = false;
                $this->bump($db);
            }
        }
    }

    public function isAvailable(): bool
    {
        return $this->ready();
    }

    /**
     * @internal Tests reset what the process knows about the store, otherwise one broken store poisons every following test.
     */
    public static function resetState(): void
    {
        self::$storeWorks    = null;
        self::$warningLogged = false;
    }

    private function switchGeneration(): void
    {
        $this->epoch++;

        // A random token, not a counter. A counter needs "read, add one, write", and two writers
        // that read the same value produce the same generation. Six random bytes need no read.
        $this->generation = bin2hex(random_bytes(6));

        if (! $this->ready()) {
            return;
        }

        try {
            $this->store->forever($this->config['key'].'.generation', $this->generation);
        } catch (Throwable $e) {
            $this->fail('Failed to switch generation of cached permissions', $e);
        }
    }

    /**
     * Reads the token once per instance. The instance lives as long as a request, so a request
     * sees one consistent generation even if another process switches it meanwhile.
     */
    private function generation(): string
    {
        if ($this->generation !== null) {
            return $this->generation;
        }

        $generation = $this->store->get($this->config['key'].'.generation');
        if (! is_string($generation)) {
            $generation = bin2hex(random_bytes(6));
            $this->store->forever($this->config['key'].'.generation', $generation);
        }

        return $this->generation = $generation;
    }

    private function read(string $key): ?array
    {
        if (! $this->ready()) {
            return null;
        }

        try {
            $value = $this->store->get($this->config['key'].'.'.$this->generation().'.'.$key);

            return is_array($value) ? $value : null;
        } catch (Throwable $e) {
            $this->fail('Failed to read permissions from cache', $e);

            return null;
        }
    }

    private function write(string $key, array $value): void
    {
        if (! $this->ready()) {
            return;
        }

        try {
            $this->store->put(
                $this->config['key'].'.'.$this->generation().'.'.$key,
                $value,
                (int) $this->config['expiration_time'] * 60
            );
        } catch (Throwable $e) {
            $this->fail('Failed to write permissions to cache', $e);
        }
    }

    /**
     * Resolves the store on first use and verifies it once per process.
     *
     * The constructor does not do it. Service providers, queue workers and artisan commands
     * construct this class without ever checking a permission, and a misconfigured store would
     * break all of them at boot.
     */
    private function ready(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        if (empty($this->config['enabled'])) {
            return $this->available = false;
        }

        if ($this->store === null) {
            try {
                $this->store = $this->storeResolver ? ($this->storeResolver)() : $this->storeFromConfig();
            } catch (Throwable $e) {
                return $this->fail('Cannot initialize cache store', $e, true);
            }
        }

        if (self::$storeWorks !== null) {
            return $this->available = self::$storeWorks;
        }

        if (empty($this->config['check'])) {
            return $this->available = self::$storeWorks = true;
        }

        // Read before write. The test key survives between processes, so after the first worker
        // of a deployment every other worker proves the store with one read and no write.
        try {
            $testKey = $this->config['key'].'.cache_test';

            if ($this->store->get($testKey) === null) {
                $stamp = microtime(true);
                $this->store->forever($testKey, $stamp);
                if ($this->store->get($testKey) !== $stamp) {
                    throw new \RuntimeException('Cache read/write value mismatch');
                }
            }
        } catch (Throwable $e) {
            return $this->fail('Cache is not working, falling back to direct DB queries', $e, true);
        }

        return $this->available = self::$storeWorks = true;
    }

    private function storeFromConfig(): Repository
    {
        $manager = app(CacheManager::class);
        $name    = $this->config['store'] ?? 'default';

        if ($name === 'default') {
            return $manager->store();
        }

        // A typo in the store name must not become an exception on every check. The array store
        // keeps values for one request, which is the closest thing to "no cache".
        if (! array_key_exists($name, config('cache.stores', []))) {
            $name = 'array';
        }

        return $manager->store($name);
    }

    /**
     * @param bool $forProcess True when the store itself is proven dead, so other instances of the process skip it too.
     */
    private function fail(string $message, Throwable $e, bool $forProcess = false): bool
    {
        $this->available = false;
        if ($forProcess) {
            self::$storeWorks = false;
        }

        // One line per process. A store that is down fails on every check, and a log line per
        // check would bury the first one, and the rest repeat it.
        if (! self::$warningLogged) {
            self::$warningLogged = true;
            try {
                app('log')->warning('[AccessRules] '.$message.': '.$e->getMessage());
            } catch (Throwable) {
                // The logger can be the thing that is broken. Authorization still has to answer.
            }
        }

        return false;
    }
}

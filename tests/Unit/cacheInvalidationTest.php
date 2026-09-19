<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TestUser;
use Tests\TestCase;
use Wnikk\LaravelAccessRules\Contracts\Rule;

/**
 * Cached permissions are dropped after a change reaches the database, never before it.
 *
 * Version 2 dropped the cache first and wrote second. A request that arrived in between read
 * the old rows and cached them again, and the change stayed invisible until the entry expired
 * a day later. The window is a few milliseconds wide, so no ordinary test ever hits it.
 *
 * These tests hit it on purpose: a hook runs a second "request" at the exact moment before
 * the INSERT or DELETE goes out.
 */
class cacheInvalidationTest extends TestCase
{
    /** @var TestUser */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [
            'Group',
            TestUser::class,
        ]);

        $this->getAccessRules()->newRule('cached-rule', 'Cached Rule');

        $this->user = TestUser::factory()->make();
        $this->user->getOwner();
    }

    /**
     * Plays the concurrent request. Right before the first query that starts with $sqlPrefix,
     * another reader compiles and caches permissions of the user from rows that are still old.
     *
     * beforeExecuting() is the only place that sits between "the package decided to write" and
     * "the database has the row". Model events fire too early for deletes, which the package
     * runs as bulk queries without events.
     */
    protected function cachePermissionsRightBefore(string $sqlPrefix)
    {
        $done = false;

        DB::connection()->beforeExecuting(function ($query) use ($sqlPrefix, &$done) {
            if ($done || stripos(ltrim($query), $sqlPrefix) !== 0) {
                return;
            }
            $done = true;

            $this->checkByNewRequest('cached-rule');
        });
    }

    /**
     * Asks like a request that starts from nothing: everything that lives as long as a request
     * is forgotten, and only the cache store is shared with the code that made the change.
     */
    protected function checkByNewRequest(string $ability)
    {
        $this->app->forgetScopedInstances();

        $acr = $this->getAccessRules();
        $acr->setOwner($this->user);

        return $acr->hasPermission($ability);
    }

    public function test_cache_is_flushed_after_permission_is_added()
    {
        $this->assertNull($this->checkByNewRequest('cached-rule'));

        $this->cachePermissionsRightBefore('insert into');
        $this->user->addPermission('cached-rule');

        $this->assertTrue($this->checkByNewRequest('cached-rule'));
    }

    public function test_cache_is_flushed_after_permission_is_removed()
    {
        $this->user->addPermission('cached-rule');
        $this->assertTrue($this->checkByNewRequest('cached-rule'));

        $this->cachePermissionsRightBefore('delete from');
        $this->user->remPermission('cached-rule');

        $this->assertNull($this->checkByNewRequest('cached-rule'));
    }

    public function test_cache_is_flushed_after_inheritance_is_added()
    {
        $group = $this->getAccessRules();
        $group->newOwner('Group', 'editors', 'Editors');
        $group->addPermission('cached-rule');

        $this->assertNull($this->checkByNewRequest('cached-rule'));

        $this->cachePermissionsRightBefore('insert into');
        $this->user->inheritPermissionFrom($group);

        $this->assertTrue($this->checkByNewRequest('cached-rule'));
    }

    public function test_cache_is_flushed_after_inheritance_is_removed()
    {
        $group = $this->getAccessRules();
        $group->newOwner('Group', 'editors', 'Editors');
        $group->addPermission('cached-rule');
        $this->user->inheritPermissionFrom($group);

        $this->assertTrue($this->checkByNewRequest('cached-rule'));

        $this->cachePermissionsRightBefore('delete from');
        $this->user->remInheritFrom($group);

        $this->assertNull($this->checkByNewRequest('cached-rule'));
    }

    /**
     * The console commands of version 2 changed inheritance and never touched the cache.
     */
    public function test_cache_is_flushed_by_inherit_commands()
    {
        $group = $this->getAccessRules();
        $group->newOwner('Group', 'editors', 'Editors');
        $group->addPermission('cached-rule');

        $arguments = [
            'primary_owner_type' => 'Group',
            'primary_owner_id'   => 'editors',
            'owner_type'         => TestUser::class,
            'owner_id'           => $this->user->getKey(),
        ];

        $this->assertNull($this->checkByNewRequest('cached-rule'));

        $this->artisan('acr:inherit', $arguments)->assertExitCode(0);
        $this->assertTrue($this->checkByNewRequest('cached-rule'));

        $this->artisan('acr:not-inherit', $arguments)->assertExitCode(0);
        $this->assertNull($this->checkByNewRequest('cached-rule'));
    }

    /**
     * Inside a transaction other connections read old rows until commit, so a drop of the cache
     * made inside it is not enough. The test plants what such a reader would cache, under the
     * generation that is current inside the transaction, and expects it to be out of reach after commit.
     */
    public function test_cache_is_flushed_again_after_transaction_commit()
    {
        $stale = null;

        DB::transaction(function () use (&$stale) {
            $this->user->addPermission('cached-rule');

            $generation = Cache::get(config('access.cache.key').'.generation');
            $type       = $this->getAccessRules()->getTypeID(TestUser::class);
            $stale      = config('access.cache.key').'.'.$generation.'.'.$type.'.'.$this->user->getKey();

            Cache::put($stale, ['owner' => null, 'permit' => [], 'deny' => [], 'cond' => []], 600);
        });

        $this->assertNotNull(Cache::get($stale), 'precondition: stale permissions were cached');
        $this->assertTrue($this->checkByNewRequest('cached-rule'));
    }

    /**
     * The rule goes through its model here, the way an admin panel deletes it. A drop of the cache
     * that lived only in RuleCatalog would miss this path.
     */
    public function test_cache_is_flushed_after_rule_is_deleted_and_restored()
    {
        $this->user->addPermission('cached-rule');
        $this->assertTrue($this->checkByNewRequest('cached-rule'));

        $this->getAccessRules()->delRule('cached-rule');
        $this->assertNull($this->checkByNewRequest('cached-rule'));

        app(Rule::class)
            ->withTrashed()->where('guard_name', 'cached-rule')->first()->restore();
        $this->assertTrue($this->checkByNewRequest('cached-rule'));
    }

    public function test_cache_is_flushed_after_rule_is_renamed()
    {
        $this->user->addPermission('cached-rule');
        $this->assertTrue($this->checkByNewRequest('cached-rule'));

        $rule             = app(Rule::class)->where('guard_name', 'cached-rule')->first();
        $rule->guard_name = 'renamed-rule';
        $rule->save();

        $this->assertNull($this->checkByNewRequest('cached-rule'));
        $this->assertTrue($this->checkByNewRequest('renamed-rule'));
    }

    /**
     * Version 2 left permissions of a deleted rule in the list as arrays instead of names.
     * A loose in_array() hid the mistake from checks, and only listings showed it.
     */
    public function test_soft_deleted_rule_is_not_listed_as_permitted()
    {
        $acr = $this->getAccessRules();
        $acr->newRule('deleted-rule', 'Deleted Rule');

        $this->user->addPermission('cached-rule');
        $this->user->addPermission('deleted-rule');

        $acr->delRule('deleted-rule');

        $type = $acr->getTypeID(TestUser::class);

        $this->assertSame(
            ['cached-rule'],
            $acr->getAllPermittedRule($type, $this->user->getKey())
        );
    }

    public function test_soft_deleted_rule_is_not_listed_as_prohibited()
    {
        $acr = $this->getAccessRules();
        $acr->newRule('deleted-rule', 'Deleted Rule');

        $this->user->addProhibition('cached-rule');
        $this->user->addProhibition('deleted-rule');

        $acr->delRule('deleted-rule');

        $type = $acr->getTypeID(TestUser::class);

        $this->assertSame(
            ['cached-rule'],
            $acr->getAllProhibitedRule($type, $this->user->getKey())
        );
    }
}

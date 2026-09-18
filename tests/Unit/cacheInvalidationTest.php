<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Fixtures\TestUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * Tests that cached permissions are invalidated after the database is changed, not before.
 *
 * When cache is flushed before the write, a concurrent request can load
 * old permissions in between and keep them cached until expiration.
 */
class cacheInvalidationTest extends TestCase
{
    /** @var TestUser */
    protected $user;

    /**
     * Set up the test environment.
     */
    public function setUp(): void
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
     * Emulates a concurrent request: right before the first query
     * starting with $sqlPrefix is executed, permissions of the user
     * are loaded (and so cached) by a separate AccessRules instance.
     *
     * @param string $sqlPrefix
     * @return void
     */
    protected function cachePermissionsRightBefore(string $sqlPrefix)
    {
        $done = false;

        DB::connection()->beforeExecuting(function ($query) use ($sqlPrefix, &$done) {
            if ($done || stripos(ltrim($query), $sqlPrefix) !== 0) {return;}
            $done = true;

            $this->checkByNewRequest('cached-rule');
        });
    }

    /**
     * Checks permission the way a new request does: nothing but the cache store is shared.
     *
     * @param string $ability
     * @return bool|null
     */
    protected function checkByNewRequest(string $ability)
    {
        $acr = $this->getAccessRules();
        $acr->setOwner($this->user);
        return $acr->hasPermission($ability);
    }

    /**
     * Permission added while a concurrent request caches permissions is visible afterwards.
     */
    public function test_cache_is_flushed_after_permission_is_added()
    {
        $this->assertNull($this->checkByNewRequest('cached-rule'));

        $this->cachePermissionsRightBefore('insert into');
        $this->user->addPermission('cached-rule');

        $this->assertTrue($this->checkByNewRequest('cached-rule'));
    }

    /**
     * Permission removed while a concurrent request caches permissions is gone afterwards.
     */
    public function test_cache_is_flushed_after_permission_is_removed()
    {
        $this->user->addPermission('cached-rule');
        $this->assertTrue($this->checkByNewRequest('cached-rule'));

        $this->cachePermissionsRightBefore('delete from');
        $this->user->remPermission('cached-rule');

        $this->assertNull($this->checkByNewRequest('cached-rule'));
    }

    /**
     * Inheritance added while a concurrent request caches permissions is visible afterwards.
     */
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

    /**
     * Inheritance removed while a concurrent request caches permissions is gone afterwards.
     */
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
     * Artisan commands changing inheritance flush cached permissions as well.
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
     * Inside a transaction other requests see old data until commit,
     * so whatever they cached meanwhile must be flushed once more after commit.
     */
    public function test_cache_is_flushed_again_after_transaction_commit()
    {
        $type = $this->getAccessRules()->getTypeID(TestUser::class);
        $key  = config('access.cache.key').'.'.$type.'.'.$this->user->getKey();

        DB::transaction(function () use ($key) {
            $this->user->addPermission('cached-rule');

            // concurrent request does not see uncommitted permission and caches list without it
            Cache::put($key, [], 600);
        });

        $this->assertTrue($this->checkByNewRequest('cached-rule'));
    }

    /**
     * Deleted rule is revoked at once, not when cached permissions expire.
     */
    public function test_cache_is_flushed_after_rule_is_deleted_and_restored()
    {
        $this->user->addPermission('cached-rule');
        $this->assertTrue($this->checkByNewRequest('cached-rule'));

        $this->getAccessRules()->delRule('cached-rule');
        $this->assertNull($this->checkByNewRequest('cached-rule'));

        app(\Wnikk\LaravelAccessRules\Contracts\Rule::class)
            ->withTrashed()->where('guard_name', 'cached-rule')->first()->restore();
        $this->assertTrue($this->checkByNewRequest('cached-rule'));
    }

    /**
     * Renamed rule is not permitted by its old name.
     */
    public function test_cache_is_flushed_after_rule_is_renamed()
    {
        $this->user->addPermission('cached-rule');
        $this->assertTrue($this->checkByNewRequest('cached-rule'));

        $rule = app(\Wnikk\LaravelAccessRules\Contracts\Rule::class)->where('guard_name', 'cached-rule')->first();
        $rule->guard_name = 'renamed-rule';
        $rule->save();

        $this->assertNull($this->checkByNewRequest('cached-rule'));
        $this->assertTrue($this->checkByNewRequest('renamed-rule'));
    }

    /**
     * Permissions of soft deleted rule are skipped, list of permitted rules holds names only.
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

    /**
     * Same for prohibited rules.
     */
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

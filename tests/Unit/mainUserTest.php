<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Config;
use Tests\Fixtures\TestUser;
use Tests\TestCase;

/**
 *     The shortest path: a user model gets a permission and $user->can() answers.
 *
 *
 * The file comes from version 2 and its scenarios stay as they were written. It is part of the
 * compatibility contract: when a test here needs a change, docs/upgrade-2-to-3.md needs a line as well.
 */
class mainUserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [
            TestUser::class,
        ]);
        $acr = $this->getAccessRules();
        $acr->newRule(
            'access-for-user',
            'Access for User',
        );
        $acr->newRule(
            'denied-for-user',
            'Denied for User',
        );
    }

    public function test_gate_authorize_user_allows_access()
    {
        $user = TestUser::factory()->make();
        $user->addPermission('access-for-user');

        $this->assertTrue($user->can('access-for-user'));
    }

    public function test_authorize_user_denies_no_rule_access()
    {
        $user = TestUser::factory()->make();

        $this->assertFalse($user->can('access-for-user'));
    }

    /**
     * Own prohibition beats own permission.
     *
     * Version 2 asserted another rule here, one the user never got, so the test could not fail.
     * Its code in fact let the permission win. Version 3 follows what the test name always said.
     */
    public function test_authorize_user_denies_lock_rule_access()
    {
        $user = TestUser::factory()->make();
        $user->addPermission('access-for-user');
        $user->addProhibition('access-for-user');

        $this->assertFalse($user->can('access-for-user'));
    }
}

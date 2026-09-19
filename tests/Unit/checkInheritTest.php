<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Config;
use Tests\Fixtures\TestUser;
use Tests\TestCase;

/**
 *     Permissions travel along inheritance: one level, two levels, and from an owner without a model.
 *
 *
 * The file comes from version 2 and its scenarios stay as they were written. It is part of the
 * compatibility contract: when a test here needs a change, docs/upgrade-2-to-3.md needs a line as well.
 */
class checkInheritTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [
            'Group',
            TestUser::class,
        ]);
        $acr = $this->getAccessRules();
        $acr->newRule(
            'access-for-inherit-user',
            'Access for Inherit User',
        );
    }

    public function test_inherit_user_allows_access()
    {
        $firstUser  = TestUser::factory()->make();
        $secondUser = TestUser::factory()->make();
        $firstUser->addPermission('access-for-inherit-user');

        $secondUser->inheritPermissionFrom($firstUser);

        $this->assertTrue($secondUser->can('access-for-inherit-user'));
    }

    public function test_inherit3_user_allows_access()
    {
        $firstUser  = TestUser::factory()->make();
        $secondUser = TestUser::factory()->make();
        $thirdUser  = TestUser::factory()->make();
        $firstUser->addPermission('access-for-inherit-user');

        $secondUser->inheritPermissionFrom($firstUser);

        $thirdUser->inheritPermissionFrom($secondUser);

        $this->assertTrue($thirdUser->can('access-for-inherit-user'));
    }

    /**
     * The first owner is a group addressed by a text id. Roles and groups have no model, so
     * inheritance must work from an AccessRules object as well as from a model.
     */
    public function test_inherit3_owner_allows_access()
    {
        $acrFirstUser = $this->getAccessRules();
        $acrFirstUser->newOwner('Group', 'out_user_id_'.rand(10000, 99999), 'Out Group User');
        $acrFirstUser->addPermission('access-for-inherit-user');
        $secondUser = TestUser::factory()->make();
        $thirdUser  = TestUser::factory()->make();

        $secondUser->inheritPermissionFrom($acrFirstUser);

        $thirdUser->inheritPermissionFrom($secondUser);

        $this->assertTrue($thirdUser->can('access-for-inherit-user'));
    }

    public function test_inherit_denies_no_rule_access()
    {
        $firstUser  = TestUser::factory()->make();
        $secondUser = TestUser::factory()->make();
        $firstUser->addPermission('access-for-inherit-user');

        $this->assertFalse($secondUser->can('access-for-inherit-user'));
    }

    /**
     * An own prohibition beats an inherited permission.
     */
    public function test_inherit2_denies_lock_rule_access()
    {
        $firstUser  = TestUser::factory()->make();
        $secondUser = TestUser::factory()->make();
        $firstUser->addPermission('access-for-inherit-user');

        $secondUser->inheritPermissionFrom($firstUser);

        $secondUser->addProhibition('access-for-inherit-user');

        $this->assertFalse($secondUser->can('access-for-inherit-user'));
    }

    /**
     * The prohibition of the second user reaches the third one as an inherited prohibition, and that
     * beats the inherited permission of the first user.
     *
     * Version 2 asserted a rule name that did not exist here, so the test passed whatever the
     * package did. It checks the real rule now.
     */
    public function test_inherit3_denies_lock_rule_access()
    {
        $firstUser  = TestUser::factory()->make();
        $secondUser = TestUser::factory()->make();
        $thirdUser  = TestUser::factory()->make();
        $firstUser->addPermission('access-for-inherit-user');

        $secondUser->inheritPermissionFrom($firstUser);
        $secondUser->addProhibition('access-for-inherit-user');

        $thirdUser->inheritPermissionFrom($secondUser);

        $this->assertFalse($thirdUser->can('access-for-inherit-user'));
    }
}

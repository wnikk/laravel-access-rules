<?php
namespace Tests\Unit;

use Illuminate\Support\Facades\Config;
use Wnikk\LaravelAccessRules\AccessRules;
use Tests\TestCase;
use Tests\Fixtures\TestUser;

/**
 * Unit tests for the checkTraitUserTest class.
 *
 * This class tests the authorization functionality for inherit users.
 */
class checkInheritTest extends TestCase
{
    /**
     * Set up the test environment.
     *
     * Configures owner types, creates a test permission
     */
    public function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [
            'Group',
            TestUser::class,
        ]);
        $acr = new AccessRules;
        $acr->newRule(
            'access-for-inherit-user',
            'Access for Inherit User',
        );
    }

    /**
     * Create a user and test if the user can access the permission for inherit user.
     */
    public function test_inherit_user_allows_access()
    {
        $firstUser  = TestUser::factory()->make();
        $secondUser = TestUser::factory()->make();
        $firstUser->addPermission('access-for-inherit-user');

        // Inherit permissions from the first user to the second user
        $secondUser->inheritPermissionFrom($firstUser);

        // Authorize for the user
        $this->assertTrue($secondUser->can('access-for-inherit-user'));
    }

    /**
     * Test that denies access for user without permission for inherit user.
     */
    public function test_inherit3_user_allows_access()
    {
        $firstUser  = TestUser::factory()->make();
        $secondUser = TestUser::factory()->make();
        $thirdUser  = TestUser::factory()->make();
        $firstUser->addPermission('access-for-inherit-user');

        // Inherit permissions from the user
        $secondUser->inheritPermissionFrom($firstUser);

        // Inherit permissions from the second user
        $thirdUser->inheritPermissionFrom($secondUser);

        // Authorize for the user
        $this->assertTrue($thirdUser->can('access-for-inherit-user'));
    }

    /**
     * Test that denies access for user without permission for inherit user.
     */
    public function test_inherit3_owner_allows_access()
    {
        $acrFirstUser = new AccessRules;
        $acrFirstUser->newOwner('Group', 'out_user_id_'.rand(10000,99999), 'Out Group User');
        $acrFirstUser->addPermission('access-for-inherit-user');
        $secondUser = TestUser::factory()->make();
        $thirdUser  = TestUser::factory()->make();

        // Inherit permissions from the user
        $secondUser->inheritPermissionFrom($acrFirstUser);

        // Inherit permissions from the second user
        $thirdUser->inheritPermissionFrom($secondUser);

        // Authorize for the user
        $this->assertTrue($thirdUser->can('access-for-inherit-user'));
    }

    /**
     * Test that denies access for user without permission for inherit user.
     */
    public function test_inherit_denies_no_rule_access()
    {
        $firstUser  = TestUser::factory()->make();
        $secondUser = TestUser::factory()->make();
        $firstUser->addPermission('access-for-inherit-user');

        // Attempt to authorize for a different out user
        $this->assertFalse($secondUser->can('access-for-inherit-user'));
    }

    /**
     * Test that denies access for user without permission for inherit user.
     */
    public function test_inherit2_denies_lock_rule_access()
    {
        $firstUser  = TestUser::factory()->make();
        $secondUser = TestUser::factory()->make();
        $firstUser->addPermission('access-for-inherit-user');

        // Inherit permissions from the first user to the second user
        $secondUser->inheritPermissionFrom($firstUser);

        // Add a prohibition for the second user
        $secondUser->addProhibition('access-for-inherit-user');

        // Attempt to authorize for a different out user
        $this->assertFalse($secondUser->can('access-for-inherit-user'));
    }

    /**
     * Test that denies access for user without permission for inherit user.
     */
    public function test_inherit3_denies_lock_rule_access()
    {
        $firstUser  = TestUser::factory()->make();
        $secondUser = TestUser::factory()->make();
        $thirdUser  = TestUser::factory()->make();
        $firstUser->addPermission('access-for-inherit-user');

        // Inherit permissions from the user
        $secondUser->inheritPermissionFrom($firstUser);
        // Add a prohibition for the second user
        $secondUser->addProhibition('access-for-inherit-user');

        // Add a prohibition for the third user
        $thirdUser->inheritPermissionFrom($secondUser);

        // Authorize for the user
        $this->assertFalse($thirdUser->can('denied-for-inherit-user'));
    }
}
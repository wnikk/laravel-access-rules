<?php
namespace Tests\Unit;

use Illuminate\Support\Facades\Config;
use Wnikk\LaravelAccessRules\AccessRules;
use Tests\TestCase;
use Tests\Fixtures\TestUser;

/**
 * Unit tests for the checkTraitUserTest class.
 *
 * This class tests the authorization functionality for users.
 */
class checkTraitUserTest extends TestCase
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
            TestUser::class,
        ]);
        $acr = new AccessRules;
        $acr->newRule(
            'access-for-user',
            'Access for User',
        );
        $acr->newRule(
            'denied-for-user',
            'Denied for User',
        );
    }

    /**
     * Create a user and a model instance for testing.
     */
    public function test_gate_authorize_user_allows_access()
    {
        $user = TestUser::factory()->make();
        $user->addPermission('access-for-user');

        // Authorize for the user
        $this->assertTrue($user->can('access-for-user'));
    }

    /**
     * Test that the gate denies access for user without permission.
     */
    public function test_authorize_user_denies_no_rule_access()
    {
        $user = TestUser::factory()->make();

        // Attempt to authorize for a different out user
        $this->assertFalse($user->can('access-for-user'));
    }

    /**
     * Test that the gate denies access for user without permission.
     */
    public function test_authorize_user_denies_lock_rule_access()
    {
        $user = TestUser::factory()->make();
        $user->addProhibition('access-for-user');

        // Authorize for the user
        $this->assertFalse($user->can('denied-for-user'));
    }
}
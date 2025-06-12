<?php
namespace Tests\Unit;

use Illuminate\Support\Facades\Config;
use Wnikk\LaravelAccessRules\AccessRules;
use Tests\TestCase;
use Tests\Fixtures\TestUser;
use LogicException;

/**
 * Unit tests for checking rule options in user permissions.
 *
 * This class tests the functionality of access rules with options
 * and ensures that users can only access rules with valid options.
 */
class checkRuleOptionsTest extends TestCase
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
            'access-options-rule',
            'Access with Options Rule',
            'This rule has options for testing',
            null,
            'required|string|in:option1,option2,option3'
        );
    }

    /**
     * Create a user and a model instance for testing.
     */
    public function test_gate_authorize_user_allows_access()
    {
        $user = TestUser::factory()->make();
        $user->addPermission('access-options-rule', 'option1');

        // Authorize for the user
        $this->assertTrue($user->can('access-options-rule.option1'));
    }

    /**
     * Test that wrong option access for user without permission.
     */
    public function test_wrong_option_rule_access()
    {
        $user = TestUser::factory()->make();

        $this->expectException(LogicException::class);
        // Invalid option
        $user->addPermission('access-options-rule', 'option4');

        // If we reach here, the test failed
        $this->assertFalse(true, 'Expected LogicException was not thrown');

    }

    /**
     * Test that denies access for user without permission.
     */
    public function test_authorize_user_denies_no_rule_access()
    {
        $user = TestUser::factory()->make();

        // Attempt to authorize for a different out user
        $this->assertFalse($user->can('access-options-rule.option1'));
    }

    /**
     * Test that denies access for user with prohibition.
     */
    public function test_authorize_user_denies_other_rule_access()
    {
        $user = TestUser::factory()->make();
        $user->addPermission('access-options-rule', 'option2');

        // Authorize for the user
        $this->assertFalse($user->can('access-options-rule.option1'));
    }
}
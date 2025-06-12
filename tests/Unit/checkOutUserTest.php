<?php
namespace Tests\Unit;

use Illuminate\Support\Facades\Config;
use Wnikk\LaravelAccessRules\AccessRules;
use Tests\TestCase;

/**
 * Test class for authorization using out user rules.
 *
 * This test checks if an out user can access a resource based on the defined access rules.
 */
class checkOutUserTest extends TestCase
{
    /**
     * Set up the test environment.
     *
     * This method is called before each test method.
     */
    public function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [
            'Group',
        ]);

        $acr = new AccessRules;
        $acr->newRule(
            'access-for-out-user',
            'Access for Out User',
        );
    }

    /**
     * Create a user and a model instance for testing.
     */
    public function test_gate_authorize_out_user_allows_access()
    {
        $acr = new AccessRules;
        $acr->newOwner('Group', 'out_user_id_122', 'Out User');
        // Add permission for the out user
        $acr->addPermission('access-for-out-user');

        // Authorize for the out user
        $this->assertTrue($acr->can('access-for-out-user'));
    }

    /**
     * Test that the gate denies access for an out user without permission.
     */
    public function test_gate_authorize_out_user_denies_access()
    {
        $acr = new AccessRules;
        $acr->newOwner('Group', 'out_user_id_124', 'Out User');

        // Attempt to authorize for a different out user
        // This should return null since no permission
        // Only for AccessRules object when using out user
        // Otherwise the check will stop and it will not be possible to add other middlewares
        // which work if you check gate, not directly from AccessRules class
        $this->assertNull($acr->can('access-for-out-user'));
    }
}
<?php
namespace Tests\Unit;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Access\AuthorizationException;
use Wnikk\LaravelAccessRules\AccessRules;
use Tests\TestCase;
use Tests\Fixtures\TestUser;

/**
 * Unit tests for Gate::authorize with access rules.
 *
 * Ensures that authorization via Gate works correctly
 * for users with and without the required permission.
 */
class gateAuthorizeTest extends TestCase
{
    /**
     * Set up the test environment.
     *
     * Configures owner types and creates a test permission.
     */
    public function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [
            TestUser::class,
        ]);
        $acr = new AccessRules;
        $acr->newRule(
            'view-gate-authorize',
            'View Dashboard Permission',
        );
    }

    /**
     * Test that a user with the required permission
     * is authorized by the gate.
     *
     * @return void
     */
    public function test_gate_authorize_allows_access()
    {
        $user = TestUser::factory()->make();
        $user->addPermission('view-gate-authorize');

        $this->be($user);

        // Should not throw an exception
        Gate::authorize('view-gate-authorize');
        // Explicitly assert success
        $this->assertTrue(true);
    }

    /**
     * Test that a user without the required permission
     * is denied by the gate and an exception is thrown.
     *
     * @return void
     */
    public function test_gate_authorize_denies_access()
    {
        $user = TestUser::factory()->make();

        $this->be($user);

        $this->expectException(AuthorizationException::class);
        Gate::authorize('view-gate-authorize');
        // If we reach here, the test failed
        $this->assertFalse(true, 'Expected AuthorizationException was not thrown');
    }
}
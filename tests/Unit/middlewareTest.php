<?php
namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Config;
use Wnikk\LaravelAccessRules\AccessRules;
use Tests\TestCase;
use Tests\Fixtures\TestUser;

/**
 * Unit tests for the access rules middleware.
 *
 * Verifies that the middleware correctly allows or denies access
 * based on user permissions.
 */
class middlewareTest extends TestCase
{
    /**
     * Set up the test environment.
     *
     * Configures owner types, creates a test permission,
     * and registers a test route with the middleware.
     */
    public function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [
            TestUser::class,
        ]);
        $acr = new AccessRules;
        $acr->newRule(
            'view-dashboard',
            'View Dashboard Permission',
        );

        Route::get('/dashboard-test', function () {
            return response('OK', 200);
        })->middleware('can:view-dashboard');
    }

    /**
     * Test that a user without the required permission
     * is denied access to the protected route.
     *
     * @return void
     */
    public function test_middleware_allowed_route()
    {
        $user = TestUser::factory()->make();
        $user->addPermission('view-dashboard');

        $response = $this->actingAs($user)->get('/dashboard-test');
        $response->assertStatus(200);
    }

    /**
     * Test that a user without the required permission
     * is denied access to the protected route.
     *
     * @return void
     */
    public function test_middleware_prohibited_route()
    {
        $user = TestUser::factory()->make();

        $response = $this->actingAs($user)->get('/dashboard-test');
        $response->assertStatus(403);
    }
}
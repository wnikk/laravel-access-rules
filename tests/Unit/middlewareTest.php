<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\TestUser;
use Tests\TestCase;

/**
 *     The "can:" route middleware of Laravel asks the package: 200 with the permission, 403 without.
 *
 *
 * The file comes from version 2 and its scenarios stay as they were written. It is part of the
 * compatibility contract: when a test here needs a change, docs/upgrade-2-to-3.md needs a line as well.
 */
class middlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [
            TestUser::class,
        ]);
        $acr = $this->getAccessRules();
        $acr->newRule(
            'view-dashboard',
            'View Dashboard Permission',
        );

        Route::get('/dashboard-test', function () {
            return response('OK', 200);
        })->middleware('can:view-dashboard');
    }

    public function test_middleware_allowed_route()
    {
        $user = TestUser::factory()->make();
        $user->addPermission('view-dashboard');

        $response = $this->actingAs($user)->get('/dashboard-test');
        $response->assertStatus(200);
    }

    public function test_middleware_prohibited_route()
    {
        $user = TestUser::factory()->make();

        $response = $this->actingAs($user)->get('/dashboard-test');
        $response->assertStatus(403);
    }
}

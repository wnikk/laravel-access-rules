<?php

namespace Tests\Unit;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Tests\Fixtures\TestUser;
use Tests\TestCase;

/**
 *     Gate::authorize() of Laravel asks the package, without a policy class and without Gate::define().
 *
 *
 * The file comes from version 2 and its scenarios stay as they were written. It is part of the
 * compatibility contract: when a test here needs a change, docs/upgrade-2-to-3.md needs a line as well.
 */
class gateAuthorizeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [
            TestUser::class,
        ]);
        $acr = $this->getAccessRules();
        $acr->newRule(
            'view-gate-authorize',
            'View Dashboard Permission',
        );
    }

    public function test_gate_authorize_allows_access()
    {
        $user = TestUser::factory()->make();
        $user->addPermission('view-gate-authorize');

        $this->be($user);

        Gate::authorize('view-gate-authorize');
        $this->assertTrue(true);
    }

    public function test_gate_authorize_denies_access()
    {
        $user = TestUser::factory()->make();

        $this->be($user);

        $this->expectException(AuthorizationException::class);
        Gate::authorize('view-gate-authorize');
        $this->assertFalse(true, 'Expected AuthorizationException was not thrown');
    }
}

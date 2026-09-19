<?php

namespace Tests\Unit;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Tests\Fixtures\DummyModel;
use Tests\Fixtures\TestUser;
use Tests\TestCase;

/**
 *     The magic suffix ".self": a permission "x.self" lets the author of a record pass the check of "x".
 *
 *
 * The file comes from version 2 and its scenarios stay as they were written. It is part of the
 * compatibility contract: when a test here needs a change, docs/upgrade-2-to-3.md needs a line as well.
 */
class checkAuthorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [
            TestUser::class,
        ]);
        $acr = $this->getAccessRules();
        $acr->newRule(
            'view-DummyModel-Author.self',
            'View Author Permission for Dummy Model',
        );
    }

    /**
     * The author column is "testuser_id": class name in lower case plus the key name. That is the
     * convention of version 2, and projects have columns named by it.
     */
    protected function makeModels()
    {
        $user               = TestUser::factory()->make();
        $model              = DummyModel::factory()->make();
        $model->testuser_id = $user->id;
        $user->addPermission('view-DummyModel-Author.self');

        $this->be($user);

        return [$user, $model];
    }

    public function test_gate_authorize_self_allows_access()
    {
        [$user, $model] = $this->makeModels();

        $model->testuser_id = $user->id;

        Gate::authorize('view-DummyModel-Author', $model);
        $this->assertTrue(true);
    }

    public function test_gate_authorize_self_denies_access()
    {
        [$user, $model]     = $this->makeModels();
        $model->testuser_id = 0; // Set to a different user id to simulate no ownership

        $this->expectException(AuthorizationException::class);
        Gate::authorize('view-DummyModel-Author', $model);
        $this->assertFalse(true, 'Expected AuthorizationException was not thrown');
    }
}

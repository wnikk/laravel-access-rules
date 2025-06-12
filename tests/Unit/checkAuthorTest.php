<?php
namespace Tests\Unit;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Access\AuthorizationException;
use Wnikk\LaravelAccessRules\AccessRules;
use Tests\TestCase;
use Tests\Fixtures\TestUser;
use Tests\Fixtures\DummyModel;

/**
 * Test class for gate authorization using self-authorization rules.
 *
 * This test checks if a user can access a resource they own
 * based on the defined access rules.
 */
class checkAuthorTest extends TestCase
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
            TestUser::class,
        ]);
        // Rule for self-authorization where the user is the author
        $acr = new AccessRules;
        $acr->newRule(
            'view-DummyModel-Author.self',
            'View Author Permission for Dummy Model',
        );
    }

    /**
     * Create a user and a model instance for testing.
     *
     * This method creates a user and a model instance,
     * sets the testuser_id to the user's id, and assigns
     * the necessary permission to the user.
     *
     * @return array An array containing the user and model instances.
     */
    protected function makeModels()
    {
        $user = TestUser::factory()->make();
        $model = DummyModel::factory()->make();
        $model->testuser_id = $user->id;
        /**
         * This permission is required for the user to be able to access the resource.
         * Very important add permission to the user with "self" rule
         * And check that rule without "self".
         */
        $user->addPermission('view-DummyModel-Author.self');

        $this->be($user);

        return [$user, $model];
    }

    /**
     * Test that a user with the required permission
     * is authorized by the gate.
     *
     * @return void
     */
    public function test_gate_authorize_self_allows_access()
    {
        // Create a user and a model instance
        list($user, $model)  = $this->makeModels();

        // Set the model's testuser_id to the user's id
        $model->testuser_id = $user->id;

        // Should not throw an exception
        Gate::authorize('view-DummyModel-Author', $model);
        // Explicitly assert success
        $this->assertTrue(true);
    }

    /**
     * Test that a user without the required permission
     * is denied by the gate and an exception is thrown.
     *
     * @return void
     */
    public function test_gate_authorize_self_denies_access()
    {
        // Create a user and a model instance
        list($user, $model)  = $this->makeModels();
        $model->testuser_id = 0; // Set to a different user id to simulate no ownership

        $this->expectException(AuthorizationException::class);
        // Should not throw an exception
        Gate::authorize('view-DummyModel-Author', $model);
        // If we reach here, the test failed
        $this->assertFalse(true, 'Expected AuthorizationException was not thrown');
    }
}
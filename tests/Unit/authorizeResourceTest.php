<?php
namespace Tests\Unit;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Config;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Wnikk\LaravelAccessRules\AccessRules;
use Tests\TestCase;
use Tests\Fixtures\TestUser;
use Tests\Fixtures\DummyModel;

/**
 * Test controller using authorizeResource.
 */
class DummyResourceController extends Controller
{
    use AuthorizesRequests;

    public function __construct()
    {
        $this->authorizeResource(DummyModel::class, 'dummy_model');
    }

    public function show(DummyModel $dummy_model)
    {
        return response('OK', 200);
    }
}

/**
 * Unit tests for authorizeResource in controllers.
 */
class authorizeResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [
            TestUser::class,
        ]);
        $acr = new AccessRules;
        $acr->newRule(
            'view',
            'View Resource Permission',
        );

        // Register route for testing
        Route::get('/dummy-resource/{dummy_model}', [DummyResourceController::class, 'show']);
    }

    /**
     * Test authorized access to resource.
     */
    public function test_authorized_user_can_access()
    {
        $user = TestUser::factory()->make();
        $model = DummyModel::factory()->make();
        //$user->addPermission('viewAny');
        $user->addPermission('view');
        //$user->addPermission('create');
        //$user->addPermission('update');
        //$user->addPermission('delete');

        $response = $this->actingAs($user)->get("/dummy-resource/{$model->id}");
        $response->assertStatus(200);
    }

    /**
     * Test unauthorized access to resource.
     */
    public function test_unauthorized_user_cannot_access()
    {
        $user = TestUser::factory()->make();
        $model = DummyModel::factory()->make();

        $response = $this->actingAs($user)->get("/dummy-resource/{$model->id}");
        $response->assertStatus(403);
    }
}
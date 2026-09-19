<?php

namespace Tests\Unit;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\DummyModel;
use Tests\Fixtures\TestUser;
use Tests\TestCase;

/**
 * authorizeResource() maps controller methods to abilities "viewAny", "view", "create" and so on,
 * and passes the model class for methods without a record.
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
 *         Resource controllers work through authorizeResource() without a policy class.
 *
 *
 * The file comes from version 2 and its scenarios stay as they were written. It is part of the
 * compatibility contract: when a test here needs a change, docs/upgrade-2-to-3.md needs a line as well.
 */
class authorizeResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [
            TestUser::class,
        ]);

        $acr = $this->getAccessRules();
        $acr->newRule(
            'view',
            'View Resource Permission',
        );

        Route::get('/dummy-resource/{dummy_model}', [DummyResourceController::class, 'show']);
    }

    /**
     * Only "view" is granted, because "show" asks for nothing else. Granting all five abilities,
     * as the first draft did, hides a wrong mapping between methods and abilities.
     */
    public function test_authorized_user_can_access()
    {
        $user  = TestUser::factory()->make();
        $model = DummyModel::factory()->make();
        $user->addPermission('view');

        $response = $this->actingAs($user)->get("/dummy-resource/{$model->id}");
        $response->assertStatus(200);
    }

    public function test_unauthorized_user_cannot_access()
    {
        $user  = TestUser::factory()->make();
        $model = DummyModel::factory()->make();

        $response = $this->actingAs($user)->get("/dummy-resource/{$model->id}");
        $response->assertStatus(403);
    }
}

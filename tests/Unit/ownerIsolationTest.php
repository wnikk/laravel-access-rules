<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Fixtures\TestUser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Wnikk\LaravelAccessRules\Models\Owner;

/**
 * Regression tests: permissions of one owner must never be served to another.
 *
 * Covers the leak where HasPermissions registered static model listeners
 * on every model instance, so loading other users re-pointed the
 * AccessRules instance of an already loaded user to a foreign owner.
 */
class ownerIsolationTest extends TestCase
{
    /** @var TestUser */
    protected $plainUser;

    /** @var TestUser */
    protected $secretUser;

    /**
     * Set up the test environment.
     *
     * Unlike other tests, users are persisted: the leak only shows up
     * when models are hydrated from the database ("retrieved" event).
     */
    public function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [
            TestUser::class,
        ]);

        Schema::create('test_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        $this->getAccessRules()->newRule('secret-rule', 'Secret Rule');

        $this->plainUser  = TestUser::create(['name' => 'Plain', 'email' => 'plain@example.com']);
        $this->secretUser = TestUser::create(['name' => 'Secret', 'email' => 'secret@example.com']);
        $this->secretUser->addPermission('secret-rule');
    }

    /**
     * Loading other users after the current one must not change its permissions.
     */
    public function test_permissions_do_not_leak_when_other_users_are_retrieved()
    {
        $user = TestUser::find($this->plainUser->id);

        // e.g. admin page with a list of users, user with permission is hydrated last
        TestUser::orderBy('id')->get();

        $this->assertFalse($user->can('secret-rule'));
    }

    /**
     * Loading other users must not take away permissions either.
     */
    public function test_permissions_are_kept_when_other_users_are_retrieved()
    {
        $user = TestUser::find($this->secretUser->id);

        // user without permission is hydrated last
        TestUser::orderByDesc('id')->get();

        $this->assertTrue($user->can('secret-rule'));
    }

    /**
     * Every user of a hydrated collection is checked against its own owner.
     */
    public function test_each_user_of_collection_has_own_permissions()
    {
        $users = TestUser::orderBy('id')->get()->keyBy('id');

        $this->assertFalse($users[$this->plainUser->id]->can('secret-rule'));
        $this->assertTrue($users[$this->secretUser->id]->can('secret-rule'));
    }

    /**
     * Model listeners are registered once per class, not once per model instance.
     */
    public function test_model_listeners_do_not_accumulate()
    {
        $events = $this->app['events'];
        $count  = function () use ($events) {
            $total = 0;
            foreach (['retrieved', 'created', 'deleting'] as $event) {
                $total += count($events->getListeners('eloquent.'.$event.': '.TestUser::class));
            }
            return $total;
        };

        $before = $count();

        TestUser::all();
        TestUser::find($this->plainUser->id);
        new TestUser;

        $this->assertSame($before, $count());
    }

    /**
     * A replica of a model is a new owner without permissions of its source.
     */
    public function test_replicated_user_does_not_share_permissions()
    {
        $source = TestUser::find($this->secretUser->id);
        $this->assertTrue($source->can('secret-rule'));

        $copy = $source->replicate();
        $copy->email = 'copy@example.com';
        $copy->save();

        $this->assertFalse($copy->can('secret-rule'));
        $this->assertTrue($source->can('secret-rule'));
        $this->assertNotSame($source->getOwner()->id, $copy->getOwner()->id);
    }

    /**
     * A clone shares internals with its source, re-keyed clone must be checked as its own owner.
     */
    public function test_cloned_user_with_other_key_does_not_share_permissions()
    {
        $source = TestUser::find($this->secretUser->id);
        $this->assertTrue($source->can('secret-rule'));

        $copy = clone $source;
        $copy->id = $this->plainUser->id;

        $this->assertFalse($copy->can('secret-rule'));
        $this->assertTrue($source->can('secret-rule'));
    }

    /**
     * Same as above, but model is cloned before its permissions were ever checked.
     */
    public function test_user_cloned_before_first_check_does_not_share_permissions()
    {
        $source = TestUser::find($this->plainUser->id);

        $copy = clone $source;
        $copy->id = $this->secretUser->id;

        $this->assertTrue($copy->can('secret-rule'));
        $this->assertFalse($source->can('secret-rule'));

        // check of source must not re-point access rules of its clone
        $this->assertTrue($copy->can('secret-rule'));
    }

    /**
     * Switching owner of AccessRules instance must drop permissions loaded for previous owner.
     */
    public function test_set_owner_resets_loaded_permissions()
    {
        $acr = $this->getAccessRules();

        $acr->setOwner($this->secretUser);
        $this->assertTrue($acr->hasPermission('secret-rule'));

        $acr->setOwner($this->plainUser);
        $this->assertNull($acr->hasPermission('secret-rule'));
    }

    /**
     * Owner is still created together with the model and removed together with it.
     */
    public function test_owner_is_created_and_deleted_with_model()
    {
        $user = TestUser::create(['name' => 'Temp', 'email' => 'temp@example.com']);
        $type = $this->getAccessRules()->getTypeID(TestUser::class);

        $this->assertNotNull($this->getAccessRules()->setOwner(TestUser::class, $user->id)->getOwner());

        $user->delete();

        $this->assertNull($this->getAccessRules()->setOwner(TestUser::class, $user->id)->getOwner());
    }
}

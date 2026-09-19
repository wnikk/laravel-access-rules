<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TestUser;
use Tests\TestCase;

/**
 * Permissions of one owner never answer for another owner.
 *
 * Version 2.3 had this hole: the trait registered model listeners once per loaded model, and
 * loading a list of users re-pointed the permission service of the signed in user at the last
 * user of the list. Whoever came last in an admin table lent their permissions to the viewer.
 *
 * The mechanism is gone in version 3, where models hold nothing. The scenarios stay, because
 * they describe how the hole was reached from outside, and a future optimisation that starts
 * remembering something per model would reopen it the same way.
 */
class ownerIsolationTest extends TestCase
{
    /** @var TestUser */
    protected $plainUser;

    /** @var TestUser */
    protected $secretUser;

    /**
     * Users are saved here, unlike in most tests. The leak needed models loaded from the database,
     * and a model made in memory never fired the event it rode on.
     */
    protected function setUp(): void
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
     * The list is ordered so that the user with the permission loads last: the leak always came
     * from the last loaded model.
     */
    public function test_permissions_do_not_leak_when_other_users_are_retrieved()
    {
        $user = TestUser::find($this->plainUser->id);

        TestUser::orderBy('id')->get();

        $this->assertFalse($user->can('secret-rule'));
    }

    /**
     * The mirror case. A leak can take permissions away as well as give them, and only the second
     * kind gets reported by users.
     */
    public function test_permissions_are_kept_when_other_users_are_retrieved()
    {
        $user = TestUser::find($this->secretUser->id);

        TestUser::orderByDesc('id')->get();

        $this->assertTrue($user->can('secret-rule'));
    }

    public function test_each_user_of_collection_has_own_permissions()
    {
        $users = TestUser::orderBy('id')->get()->keyBy('id');

        $this->assertFalse($users[$this->plainUser->id]->can('secret-rule'));
        $this->assertTrue($users[$this->secretUser->id]->can('secret-rule'));
    }

    /**
     * Counts listeners before and after loading models. Behaviour alone does not show this one:
     * listeners that pile up answer correctly and get slower with every loaded model.
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

    public function test_replicated_user_does_not_share_permissions()
    {
        $source = TestUser::find($this->secretUser->id);
        $this->assertTrue($source->can('secret-rule'));

        $copy        = $source->replicate();
        $copy->email = 'copy@example.com';
        $copy->save();

        $this->assertFalse($copy->can('secret-rule'));
        $this->assertTrue($source->can('secret-rule'));
        $this->assertNotSame($source->getOwner()->id, $copy->getOwner()->id);
    }

    /**
     * A clone copies every property of its source. Anything a model remembers about its owner
     * travels with the clone and keeps pointing at the source after the key changes.
     */
    public function test_cloned_user_with_other_key_does_not_share_permissions()
    {
        $source = TestUser::find($this->secretUser->id);
        $this->assertTrue($source->can('secret-rule'));

        $copy     = clone $source;
        $copy->id = $this->plainUser->id;

        $this->assertFalse($copy->can('secret-rule'));
        $this->assertTrue($source->can('secret-rule'));
    }

    /**
     * Cloned before any check, so nothing was bound yet. The first fix of 2.4.1 passed the test
     * above and failed this one: the last assertion repeats a check after the source was checked.
     */
    public function test_user_cloned_before_first_check_does_not_share_permissions()
    {
        $source = TestUser::find($this->plainUser->id);

        $copy     = clone $source;
        $copy->id = $this->secretUser->id;

        $this->assertTrue($copy->can('secret-rule'));
        $this->assertFalse($source->can('secret-rule'));

        $this->assertTrue($copy->can('secret-rule'));
    }

    /**
     * The entry point of version 2 selects an owner and can select another. Admin screens do it
     * in a loop over owners.
     */
    public function test_set_owner_resets_loaded_permissions()
    {
        $acr = $this->getAccessRules();

        $acr->setOwner($this->secretUser);
        $this->assertTrue($acr->hasPermission('secret-rule'));

        $acr->setOwner($this->plainUser);
        $this->assertNull($acr->hasPermission('secret-rule'));
    }

    public function test_owner_is_created_and_deleted_with_model()
    {
        $user = TestUser::create(['name' => 'Temp', 'email' => 'temp@example.com']);
        $this->assertNotNull($this->getAccessRules()->setOwner(TestUser::class, $user->id)->getOwner());

        $user->delete();

        $this->assertNull($this->getAccessRules()->setOwner(TestUser::class, $user->id)->getOwner());
    }
}

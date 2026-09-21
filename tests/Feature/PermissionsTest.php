<?php

namespace Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Tests\Fixtures\Ability;
use Tests\Fixtures\Shop\Order;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;
use Wnikk\LaravelAccessRules\AccessRules;
use Wnikk\LaravelAccessRules\Contracts\AccessManager;
use Wnikk\LaravelAccessRules\Events\AccessChanged;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;
use Wnikk\LaravelAccessRules\Facades\Access;

/**
 * How compiled permissions turn into answers of Laravel Gate.
 *
 * The catalogue of conditions checks that lists and records agree. This file checks the
 * decisions around them: what is asked (a record, a class, nothing), who wins when permissions
 * collide, what Gate hears back, and what happens to guests, enums and mistakes.
 */
class PermissionsTest extends FeatureTestCase
{
    /** @var TestUser */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [TestUser::class, 'Role']);
        ShopSchema::create();
        ShopSchema::seed();

        $acr = $this->getAccessRules();
        $acr->newRule('orders.view', 'View orders', resource: 'order');
        $acr->newRule('orders.update', 'Update orders', resource: 'order');
        $acr->newRule('orders.update.self', 'Update own orders', resource: 'order');

        $this->user = TestUser::factory()->make()->forceFill(['id' => 7]);
    }

    public function test_without_anything_only_permissions_that_do_not_depend_on_a_record_count(): void
    {
        $this->user->addPermission('orders.view', when: 'order.cost > 100');

        $this->assertFalse($this->user->can('orders.view'), 'as in 2.x: there is nothing to check the condition against');
        $this->assertTrue($this->user->can('orders.view', Order::find(1)));
        $this->assertFalse($this->user->can('orders.view', Order::find(3)));

        $this->user->addPermission('orders.update');
        $this->assertTrue($this->user->can('orders.update'), 'plain permission of 2.x works as before');
    }

    public function test_class_instead_of_record_asks_about_records_in_general(): void
    {
        $this->user->addPermission('orders.view', when: 'order.cost > 100');

        $this->assertTrue($this->user->can('orders.view', Order::class), 'menu: the user may see some orders');
        $this->assertFalse($this->user->can('orders.update', Order::class), 'no permission of any kind');

        $this->user->addPermission('orders.update');
        $this->assertTrue($this->user->can('orders.update', Order::class), 'plain permission counts too');
    }

    public function test_class_instead_of_record_and_prohibitions(): void
    {
        $role = $this->getAccessRules();
        $role->newOwner('Role', 'manager');
        $role->addPermission('orders.update');
        $role->addPermission('orders.view', when: 'order.cost > 100');
        $this->user->inheritPermissionFrom($role);

        $this->user->addProhibition('orders.update', when: 'order.locked');

        $this->assertTrue($this->user->can('orders.update', Order::class), 'locked orders are hidden, the rest is not');
        $this->assertFalse($this->user->can('orders.update', Order::find(4)));
        $this->assertTrue($this->user->can('orders.update', Order::find(1)));
        $this->assertTrue($this->user->can('orders.update'), 'the prohibition depends on a record, the permit does not');

        $this->user->addProhibition('orders.view');
        $this->assertFalse($this->user->can('orders.view', Order::class), 'prohibition without a condition hides everything');
        $this->assertFalse($this->user->can('orders.view', Order::find(1)));
    }

    public function test_self_suffix_without_record_is_as_in_2x(): void
    {
        $this->user->addPermission('orders.update.self');

        $this->assertFalse($this->user->can('orders.update'));
        $this->assertTrue($this->user->can('orders.update', Order::class), 'may update some orders: own ones');
        $this->assertTrue($this->user->can('orders.update', Order::find(1)));
    }

    public function test_condition_about_the_user_only_is_checked_without_record(): void
    {
        $this->user->forceFill(['level' => 1]);
        $this->user->addPermission('orders.view', when: 'user.level >= 3');

        $this->assertFalse($this->user->can('orders.view'));
        $this->assertFalse($this->user->can('orders.view', Order::class));
        $this->assertSame([], Order::query()->allowedTo('orders.view', $this->user)->pluck('id')->all());

        $this->user->forceFill(['level' => 3]);
        $this->assertTrue($this->user->can('orders.view'), 'a condition about the user only needs no record');
    }

    /**
     * As in version 2. The package starts from "everything is forbidden" and hands out permissions;
     * a prohibition takes a permission away and is no veto over the rest of the application.
     */
    public function test_a_prohibition_takes_a_permission_away_and_is_no_veto(): void
    {
        $this->user->addPermission('orders.view');
        $this->assertTrue($this->user->can('orders.view'));

        $this->user->addProhibition('orders.view');
        $this->assertFalse($this->user->can('orders.view'), 'nobody else permits, so Gate denies');
        $this->assertFalse($this->user->hasPermission('orders.view'), 'asked directly, the package says "prohibited"');

        Gate::define('orders.view', fn () => true);
        $this->assertTrue($this->user->can('orders.view'), 'the application still has its word');
    }

    public function test_unknown_ability_is_left_to_laravel_gate(): void
    {
        Gate::define('reports.view', fn () => true);

        $this->assertTrue($this->user->can('reports.view'));
    }

    /**
     * The message of the 403 belongs to Laravel and to the application. What was refused is known
     * the way version 2 told it, and error pages of existing projects read it.
     */
    public function test_the_name_of_what_was_refused_is_known_and_the_message_is_left_alone(): void
    {
        $this->user->addProhibition('orders.view');

        try {
            Gate::forUser($this->user)->authorize('orders.view');
            $this->fail('had to be refused');
        } catch (AuthorizationException $e) {
            $this->assertSame('This action is unauthorized.', $e->getMessage());
        }
        $this->assertSame('orders.view', Access::lastDenied());

        Gate::forUser($this->user)->inspect('orders.update');
        $this->assertSame('orders.update', Access::lastDenied());
        $this->assertSame('orders.update', AccessRules::getLastDisallowPermission(), 'the name of version 2 reads the same value');
    }

    public function test_priority_of_permissions(): void
    {
        $role = $this->getAccessRules();
        $role->newOwner('Role', 'manager');
        $this->user->inheritPermissionFrom($role);

        $this->assertFalse($this->user->can('orders.view'), '1. nothing is said');

        $role->addPermission('orders.view');
        $this->assertTrue($this->user->can('orders.view'), '2. inherited permission');

        $role->addProhibition('orders.view');
        $this->assertFalse($this->user->can('orders.view'), '3. inherited prohibition beats inherited permission');

        $this->user->addPermission('orders.view');
        $this->assertTrue($this->user->can('orders.view'), '4. own permission beats inherited prohibition');

        $this->user->addProhibition('orders.view');
        $this->assertFalse($this->user->can('orders.view'), '5. own prohibition beats everything');
    }

    public function test_priority_of_permissions_with_conditions(): void
    {
        // Both belong to the user. The code of version 2 let the permission win here, which made
        // "everything above 100, except locked ones" impossible to say for one owner.
        $this->user->addPermission('orders.view', when: 'order.cost > 100');
        $this->user->addProhibition('orders.view', when: 'order.locked');

        $this->assertTrue($this->user->can('orders.view', Order::find(1)));
        $this->assertFalse($this->user->can('orders.view', Order::find(4)), 'cost is fine, but the order is locked');
        $this->assertSame([1, 2, 5, 6], Order::query()->allowedTo('orders.view', $this->user)->orderBy('id')->pluck('id')->all());
    }

    public function test_self_suffix_is_a_permission_of_its_step(): void
    {
        $role = $this->getAccessRules();
        $role->newOwner('Role', 'manager');
        $role->addProhibition('orders.update');
        $this->user->inheritPermissionFrom($role);

        $this->user->addPermission('orders.update.self');

        $this->assertTrue($this->user->can('orders.update', Order::find(1)), 'own order: own permission beats inherited prohibition');
        $this->assertFalse($this->user->can('orders.update', Order::find(4)), 'order of somebody else');
        $this->assertTrue($this->user->can('orders.update.self'), 'the permission itself is given');
        $this->assertSame([1, 2, 3], Order::query()->allowedTo('orders.update', $this->user)->orderBy('id')->pluck('id')->all());

        $this->user->addProhibition('orders.update');
        $this->assertFalse($this->user->can('orders.update', Order::find(1)), 'own prohibition beats everything, the author too');
        $this->assertSame([], Order::query()->allowedTo('orders.update', $this->user)->pluck('id')->all());
    }

    public function test_author_key_can_be_configured(): void
    {
        Config::set('access.author_key', 'department_id');   // another integer column

        $this->user->forceFill(['id' => 2]);
        $this->user->addPermission('orders.update.self');
        $this->assertSame([3, 5], Order::query()->allowedTo('orders.update', $this->user)->orderBy('id')->pluck('id')->all());
        $this->assertTrue($this->user->can('orders.update', Order::find(5)));
    }

    public function test_condition_of_rule_is_valid_for_every_holder(): void
    {
        $this->getAccessRules()->newRule('orders.export', 'Export', resource: 'order', when: 'not order.locked');

        $this->user->addPermission('orders.export', when: 'order.cost >= 150');

        $this->assertSame([1, 2, 6], Order::query()->allowedTo('orders.export', $this->user)->orderBy('id')->pluck('id')->all());
        $this->assertFalse($this->user->can('orders.export', Order::find(4)), 'cost is fine, but the order is locked');
    }

    public function test_guest_is_an_owner_from_config(): void
    {
        $guest = $this->getAccessRules();
        $guest->newOwner('Role', 'guest', 'Guests');
        $guest->addPermission('orders.view', when: "order.status == 'published'");

        $this->assertFalse(Gate::allows('orders.view', Order::find(3)), 'guests have no permissions until configured');

        Config::set('access.guest', ['type' => 'Role', 'id' => 'guest']);

        $this->assertTrue(Gate::allows('orders.view', Order::find(3)));
        $this->assertFalse(Gate::allows('orders.view', Order::find(1)));
        $this->assertSame([3], Order::query()->allowedTo('orders.view')->pluck('id')->all());

        $this->assertFalse($this->user->can('orders.view', Order::find(3)), 'a user does not get permissions of guests by itself');
        $this->user->inheritPermissionFrom($guest);
        $this->assertTrue($this->user->can('orders.view', Order::find(3)));
    }

    public function test_rule_tree_inheritance_is_an_option(): void
    {
        $acr    = $this->getAccessRules();
        $parent = $acr->newRule('reports', 'Reports');
        $child  = $acr->newRule('reports.sales', 'Sales reports', null, $parent);
        $acr->newRule('reports.sales.daily', 'Daily sales reports', null, $child);

        $this->user->addPermission('reports');
        $this->assertFalse($this->user->can('reports.sales.daily'), 'off by default: the tree only groups rules');

        Config::set('access.rule_tree_inheritance', true);
        $this->getAccessRules()->flush();

        $this->assertTrue($this->user->can('reports.sales'));
        $this->assertTrue($this->user->can('reports.sales.daily'));

        $this->user->addProhibition('reports.sales');
        $this->assertFalse($this->user->can('reports.sales.daily'), 'a prohibition goes down the tree too');
        $this->assertTrue($this->user->can('reports'));

        $this->user->addPermission('reports.sales.daily');
        $this->assertTrue($this->user->can('reports.sales.daily'), 'what is said about a rule itself is stronger than what comes from above');
    }

    public function test_model_that_is_not_an_owner_is_left_to_laravel_gate(): void
    {
        Config::set('access.owner_types', ['Role']);

        $this->assertFalse($this->user->can('orders.view'));
    }

    public function test_ability_can_be_an_enum(): void
    {
        $this->user->addPermission(Ability::OrdersView, when: 'order.cost > 100');
        $this->user->addProhibition(Ability::OrdersUpdate);

        $this->assertTrue($this->user->can(Ability::OrdersView, Order::find(1)));
        $this->assertTrue($this->user->hasPermission(Ability::OrdersView, Order::find(1)));
        $this->assertFalse($this->user->hasPermission(Ability::OrdersUpdate));
        $this->assertSame([1, 2, 4, 5, 6], Order::query()->allowedTo('orders.view', $this->user)->orderBy('id')->pluck('id')->all());

        $this->assertTrue($this->user->remPermission(Ability::OrdersView));
        $this->assertFalse($this->user->can(Ability::OrdersView, Order::find(1)));
    }

    public function test_two_owner_types_with_the_same_type_id_are_refused(): void
    {
        // The database keeps CRC-16 of the name of a type, and these two names share one: 25282.
        // Two types with one number would share permissions without a word.
        [$a, $b] = ['Type2885', 'Type8660'];
        Config::set('access.owner_types', [$a, $b]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('get the same id');

        $this->getAccessRules()->getTypeID($a);
    }

    public function test_manager_keeps_nothing_between_calls(): void
    {
        $manager = app(AccessManager::class);

        $editors = $manager->for('Role', 'editor');
        $editors->create('Editors');
        $editors->allow('orders.view', when: 'order.cost > 100');

        $this->assertNull($manager->for('Role', 'viewer')->can('orders.view', Order::find(1)), 'another owner is another object');
        $this->assertTrue($manager->for('Role', 'editor')->can('orders.view', Order::find(1)));
        $this->assertTrue(Access::for('Role', 'editor')->can('orders.view', Order::class));

        $this->assertTrue($this->user->access()->inheritFrom($editors));
        $this->assertTrue($this->user->can('orders.view', Order::find(1)));
        $this->assertTrue($this->user->access()->stopInheritingFrom('Role', 'editor'));
        $this->assertFalse($this->user->can('orders.view', Order::find(1)));
    }

    public function test_exception_tells_what_went_wrong_by_code(): void
    {
        $codes = [];
        foreach ([
            fn () => $this->user->addPermission('no.such.rule'),
            fn () => $this->user->addPermission('orders.view', 'unexpected-option'),
            fn () => [$this->user->addPermission('orders.view'), $this->user->addPermission('orders.view')],
            fn () => $this->getAccessRules()->setOwner('Role', 'nobody')->addPermission('orders.view'),
            fn () => $this->getAccessRules()->addPermission('orders.view'),
            fn () => $this->getAccessRules()->setOwner('Unknown', 1),
            fn () => $this->user->addPermission('orders.update', when: 'order.nothing.here == 1'),
        ] as $mistake) {
            try {
                $mistake();
                $codes[] = null;
            } catch (AccessRulesException $e) {
                $this->assertInstanceOf(\LogicException::class, $e, 'code written for 2.x catches LogicException');
                $codes[] = $e->getCode();
            }
        }

        $e = AccessRulesException::class;
        $this->assertSame([$e::RULE_NOT_FOUND, $e::INVALID_OPTION, $e::DUPLICATE_PERMISSION, $e::OWNER_NOT_FOUND, $e::OWNER_NOT_SELECTED, $e::UNKNOWN_OWNER_TYPE, $e::INVALID_CONDITION], $codes);
    }

    public function test_changes_are_announced_and_cache_flush_can_be_postponed(): void
    {
        $seen = [];
        Event::listen(AccessChanged::class, function ($event) use (&$seen) {
            $seen[] = $event->action;
        });

        $this->assertFalse($this->user->can('orders.view'));

        Access::batch(function () {
            $this->getAccessRules()->newRule('reports.view');
            $this->user->addPermission('orders.view');
            $this->user->addPermission('reports.view');
            $this->user->addProhibition('orders.update');

            // The price of one drop of the cache, as the documentation says: inside of a batch this
            // process keeps answering from what it compiled before.
            $this->assertFalse($this->user->can('orders.view'));
        });

        $this->assertTrue($this->user->can('orders.view'), 'the changes show as soon as the batch is over');
        $this->assertTrue($this->user->can('reports.view'));

        $this->user->remPermission('reports.view');
        $this->getAccessRules()->delRule('reports.view');

        $this->assertSame(['rule.created', 'permission.granted', 'permission.granted', 'permission.granted', 'permission.revoked', 'rule.deleted'], $seen);
    }

    public function test_inheritance_loop_is_rejected(): void
    {
        $a = $this->getAccessRules();
        $a->newOwner('Role', 'a');
        $b = $this->getAccessRules();
        $b->newOwner('Role', 'b');
        $c = $this->getAccessRules();
        $c->newOwner('Role', 'c');

        $b->inheritFrom($a);
        $c->inheritFrom($b);

        $this->expectException(\LogicException::class);
        $a->inheritFrom($c);
    }
}

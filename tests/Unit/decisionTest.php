<?php

namespace Tests\Unit;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Tests\Fixtures\Ability;
use Tests\Fixtures\Shop\Order;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;
use Tests\TestCase;
use Wnikk\LaravelAccessRules\Administration\TypeRegistry;
use Wnikk\LaravelAccessRules\Contracts\AccessManager;
use Wnikk\LaravelAccessRules\Events\AccessChanged;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;
use Wnikk\LaravelAccessRules\Facades\Access;
use Wnikk\LaravelAccessRules\Storage\PermissionCache;

/**
 * How compiled permissions turn into answers of Laravel Gate.
 *
 * The catalogue of conditions checks that lists and records agree. This file checks the
 * decisions around them: what is asked (a record, a class, nothing), who wins when permissions
 * collide, what Gate hears back, and what happens to guests, enums and mistakes.
 */
class decisionTest extends TestCase
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

    public function test_prohibition_is_final_for_laravel_gate(): void
    {
        Gate::define('orders.view', fn () => true);
        $this->user->addProhibition('orders.view');

        $this->assertFalse($this->user->can('orders.view'));

        Config::set('access.deny_is_final', false);
        $this->assertTrue($this->user->can('orders.view'), 'as in 2.x: a prohibition only takes the permission away');
    }

    public function test_unknown_ability_is_left_to_laravel_gate(): void
    {
        Gate::define('reports.view', fn () => true);

        $this->assertTrue($this->user->can('reports.view'));
    }

    public function test_denial_names_the_ability(): void
    {
        $this->be($this->user);

        try {
            Gate::authorize('orders.view');
            $this->fail('AuthorizationException expected');
        } catch (AuthorizationException $e) {
            $this->assertSame('Action "orders.view" is unauthorized.', $e->getMessage());
        }

        $this->user->addProhibition('orders.update');
        $this->assertSame('Action "orders.update" is unauthorized.', Gate::inspect('orders.update')->message());

        Config::set('access.denial_message', null);
        $this->assertNull(Gate::inspect('orders.view')->message(), 'default message of Laravel is kept');
    }

    /**
     * One step at a time, from the weakest to the strongest, so a failure names the step that broke.
     */
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
        Config::set('access.author_key', 'department_id');   // just another integer column

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
        // Found by brute force below. Two names with one number would share permissions without a word.
        [$a, $b] = self::collidingNames();
        Config::set('access.owner_types', [$a, $b]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('get the same id');

        $this->getAccessRules()->getTypeID($a);
    }

    private static function collidingNames(): array
    {
        $seen = [];
        for ($i = 0; ; $i++) {
            $name = 'Type'.$i;
            $id   = TypeRegistry::crc16($name);
            if (isset($seen[$id])) {
                return [$seen[$id], $name];
            }
            $seen[$id] = $name;
        }
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

        $cache = app(PermissionCache::class);
        $this->user->can('orders.view');
        $epoch = $cache->epoch;

        Access::batch(function () use ($cache, $epoch) {
            $this->getAccessRules()->newRule('reports.view');
            $this->user->addPermission('orders.view');
            $this->user->addPermission('reports.view');
            $this->user->addProhibition('orders.update');

            $this->assertSame($epoch, $cache->epoch, 'cache is not touched inside of a batch');
            $this->assertFalse($this->user->can('orders.view'), 'and this process keeps answering from what it has compiled');
        });

        // Not "exactly one more": the test runs inside a transaction, and there the cache repeats
        // its drop after commit, which the test transaction manager runs at once.
        $this->assertGreaterThan($epoch, $cache->epoch, 'the flush happens when the batch is over');
        $this->assertTrue($this->user->can('orders.view'));
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

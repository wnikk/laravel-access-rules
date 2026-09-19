<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Fixtures\Shop\Order;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;
use Tests\TestCase;
use Wnikk\LaravelAccessRules\AccessRules;
use Wnikk\LaravelAccessRules\Facades\Access;

/**
 * Debug mode, the support session: an administrator looks at the application as the user who
 * complains, ticks "debug", and every refusal says why while every filtered list says by what.
 *
 * Two things are guarded besides the texts. The mode is off unless somebody turned it on, because
 * an explanation shows rules of other owners to whoever reads the 403. And a permitted check
 * runs the same number of queries with the mode on, because production data is where it is used.
 */
class debugModeTest extends TestCase
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
        $acr->newRule('orders.delete', 'Delete orders', resource: 'order');

        $role = $this->getAccessRules();
        $role->newOwner('Role', 'manager', 'Managers');
        $role->addPermission('orders.view', when: 'order.cost > 100 && order.items.count < 3');

        $this->user = TestUser::factory()->make()->forceFill(['id' => 7, 'name' => 'Ann']);
        $this->user->inheritPermissionFrom($role);
        $this->user->addProhibition('orders.view', when: 'order.locked');
    }

    public function test_a_refusal_says_nothing_extra_until_the_mode_is_on(): void
    {
        $response = Gate::forUser($this->user)->inspect('orders.view', Order::find(4));

        $this->assertTrue($response->denied());
        $this->assertSame('Action "orders.view" is unauthorized.', $response->message());
        $this->assertSame(['denials' => [], 'lists' => []], Access::debugLog());
    }

    public function test_a_prohibition_explains_itself_in_the_message_of_the_refusal(): void
    {
        Access::debug();

        $message = Gate::forUser($this->user)->inspect('orders.view', Order::find(4))->message();

        $this->assertStringStartsWith('Action "orders.view" is unauthorized.', $message);
        $this->assertStringContainsString('PROHIBITED', $message);
        $this->assertStringContainsString('=> prohibit, own', $message);
        $this->assertStringContainsString('when order.locked == true', $message);
        $this->assertStringContainsString('order.locked = ', $message);
    }

    public function test_silence_of_the_package_explains_itself_too(): void
    {
        Access::debug();

        // Order 3 fails the condition of the role, so nothing is said and Gate denies after everybody kept silent.
        $message = Gate::forUser($this->user)->inspect('orders.view', Order::find(3))->message();
        $this->assertStringContainsString('NOTHING IS SAID', $message);
        $this->assertStringContainsString('Role manager (Managers)', $message);
        $this->assertStringContainsString('order.cost = ', $message);

        $message = Gate::forUser($this->user)->inspect('orders.delete', Order::find(1))->message();
        $this->assertStringContainsString('Neither the owner nor anybody it inherits from', $message);
    }

    public function test_the_mode_works_without_a_configured_message(): void
    {
        Config::set('access.denial_message', null);
        Access::debug();

        $message = Gate::forUser($this->user)->inspect('orders.delete')->message();

        $this->assertStringStartsWith('orders.delete for TestUser 7, asked about no record', $message);
    }

    public function test_config_turns_the_mode_on_for_everybody(): void
    {
        Config::set('access.debug', true);

        $this->assertStringContainsString('PROHIBITED', Gate::forUser($this->user)->inspect('orders.view', Order::find(4))->message());
    }

    public function test_the_mode_can_be_turned_off_for_a_request_despite_config(): void
    {
        Config::set('access.debug', true);
        Access::debug(false);

        $this->assertSame('Action "orders.view" is unauthorized.', Gate::forUser($this->user)->inspect('orders.view', Order::find(4))->message());
    }

    public function test_every_refusal_of_the_request_is_kept_including_direct_checks(): void
    {
        Access::debug();

        Gate::forUser($this->user)->allows('orders.view', Order::find(1));   // permitted, not kept
        Gate::forUser($this->user)->allows('orders.view', Order::find(4));
        $this->user->hasPermission('orders.delete');

        $denials = Access::debugLog()['denials'];

        $this->assertSame(['orders.view', 'orders.delete'], array_column($denials, 'ability'));
        $this->assertSame([false, null], array_column($denials, 'decision'));
        $this->assertSame('orders.delete', AccessRules::getLastDisallowPermission());
    }

    public function test_the_name_of_the_last_refusal_is_known_without_the_mode(): void
    {
        $this->assertNull(AccessRules::getLastDisallowPermission());

        Gate::forUser($this->user)->allows('orders.view', Order::find(4));
        $this->assertSame('orders.view', AccessRules::getLastDisallowPermission());

        Gate::forUser($this->user)->allows('orders.delete');
        $this->assertSame('orders.delete', AccessRules::getLastDisallowPermission());
    }

    public function test_a_short_list_says_what_narrowed_it(): void
    {
        Access::debug();

        $visible = Order::allowedTo('orders.view', $this->user)->pluck('id')->all();
        $list    = Access::debugLog()['lists'][0];

        $this->assertSame(['orders.view', Order::class, 'filtered'], [$list['ability'], $list['model'], $list['outcome']]);
        $this->assertSame(['type' => TestUser::class, 'id' => 7], $list['owner']);
        $this->assertSame(
            [['prohibit', 'order.locked == true', true], ['permit', 'order.cost > 100 && count(order.items) < 3', false]],
            array_map(fn ($entry) => [$entry['effect'], $entry['condition'], $entry['own']], $list['entries'])
        );

        // The recorded SQL is the constraint itself: applied alone, it gives the same list.
        $this->assertEqualsCanonicalizing($visible, Order::query()->whereRaw($list['sql'], $list['bindings'])->pluck('id')->all());
    }

    public function test_an_empty_list_says_that_nothing_permits(): void
    {
        Access::debug();

        $this->assertSame([], Order::allowedTo('orders.delete', $this->user)->pluck('id')->all());

        $list = Access::debugLog()['lists'][0];
        $this->assertSame(['nothing', []], [$list['outcome'], $list['entries']]);
    }

    public function test_a_guest_without_an_owner_gets_the_reason_too(): void
    {
        Access::debug();

        $message = Gate::forUser(null)->inspect('orders.view')->message();

        $this->assertStringContainsString('The request has no owner', $message);
    }

    public function test_a_permitted_check_costs_the_same_with_the_mode_on(): void
    {
        $order = Order::find(1)->load('items');
        Gate::forUser($this->user)->allows('orders.view', $order);

        Access::debug();
        DB::enableQueryLog();
        $this->assertTrue(Gate::forUser($this->user)->allows('orders.view', $order));

        $this->assertCount(0, DB::getQueryLog(), 'the mode is looked at only on the way to a refusal');
        $this->assertSame([], Access::debugLog()['denials']);
    }
}

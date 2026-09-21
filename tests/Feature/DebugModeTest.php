<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Fixtures\Shop\Order;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;
use Wnikk\LaravelAccessRules\Facades\Access;

/**
 * Debug mode, the support session: an administrator looks at the application as the user who
 * complains, ticks "debug", and every refusal is explained while every filtered list says by what.
 *
 * Three things are guarded besides the texts. The mode is off unless somebody turned it on, because
 * an explanation shows rules of other owners. The mode observes and never takes part: decisions
 * and messages of Gate are the same with it and without it. And a permitted check runs the same
 * number of queries with the mode on, because production data is where it is used.
 */
class DebugModeTest extends FeatureTestCase
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

    public function test_nothing_is_kept_until_the_mode_is_on(): void
    {
        $this->assertFalse(Gate::forUser($this->user)->allows('orders.view', Order::find(4)));
        $this->assertSame(['denials' => [], 'lists' => []], Access::debugLog());
    }

    public function test_a_refusal_is_explained_in_the_log(): void
    {
        Access::debug();

        $this->assertFalse(Gate::forUser($this->user)->allows('orders.view', Order::find(4)));

        $denial = Access::debugLog()['denials'][0];

        $this->assertSame(['orders.view', false, true], [$denial['ability'], $denial['decision'], $denial['by_gate']]);
        $this->assertStringContainsString('PROHIBITED', $denial['text']);
        $this->assertStringContainsString('=> prohibit, own', $denial['text']);
        $this->assertStringContainsString('when order.locked == true', $denial['text']);
        $this->assertStringContainsString('order.locked = ', $denial['text']);
        $this->assertStringContainsString('is no veto', $denial['text'], 'says why a prohibition alone does not end the matter');
    }

    public function test_silence_of_the_package_is_explained_too(): void
    {
        Access::debug();

        // Order 3 fails the condition of the role, so nothing is said and Gate denies after everybody kept silent.
        Gate::forUser($this->user)->allows('orders.view', Order::find(3));
        Gate::forUser($this->user)->allows('orders.delete', Order::find(1));

        [$first, $second] = array_column(Access::debugLog()['denials'], 'text');

        $this->assertStringContainsString('NOTHING IS SAID', $first);
        $this->assertStringContainsString('Role manager (Managers)', $first);
        $this->assertStringContainsString('order.cost = ', $first);
        $this->assertStringContainsString('Neither the owner nor anybody it inherits from', $second);
    }

    public function test_the_mode_observes_and_takes_no_part(): void
    {
        $outcome = fn () => [
            Gate::forUser($this->user)->allows('orders.view', Order::find(1)),
            Gate::forUser($this->user)->allows('orders.view', Order::find(4)),
            Gate::forUser($this->user)->inspect('orders.view', Order::find(4))->message(),
            Gate::forUser($this->user)->raw('orders.delete'),
        ];

        $without = $outcome();
        Access::debug();

        $this->assertSame($without, $outcome());
        $this->assertSame([true, false, null, null], $without);
    }

    public function test_a_check_that_somebody_else_permits_is_no_refusal(): void
    {
        Gate::define('orders.delete', fn () => true);
        Access::debug();

        $this->assertTrue(Gate::forUser($this->user)->allows('orders.delete'));
        $this->assertSame([], Access::debugLog()['denials'], 'the package said nothing, a Gate::define() permitted: nothing to explain');
    }

    public function test_config_turns_the_mode_on_for_everybody(): void
    {
        Config::set('access.debug', true);

        Gate::forUser($this->user)->allows('orders.view', Order::find(4));

        $this->assertCount(1, Access::debugLog()['denials']);
    }

    public function test_the_mode_can_be_turned_off_for_a_request_despite_config(): void
    {
        Config::set('access.debug', true);
        Access::debug(false);

        Gate::forUser($this->user)->allows('orders.view', Order::find(4));

        $this->assertSame([], Access::debugLog()['denials']);
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
        $this->assertSame('orders.delete', Access::lastDenied());
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

        $this->assertFalse(Gate::forUser(null)->allows('orders.view'));

        $this->assertStringContainsString('The request has no owner', Access::debugLog()['denials'][0]['text']);
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

<?php

namespace Tests\Feature;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Tests\Fixtures\DummyModel;
use Tests\Fixtures\TestUser;
use Wnikk\LaravelAccessRules\AccessRules;
use Wnikk\LaravelAccessRules\Contracts\AccessRules as AccessRulesContract;
use Wnikk\LaravelAccessRules\Facades\Access;

/**
 * The package is one guest among many at Laravel Gate, and it behaves like one.
 *
 * It answers "yes" or nothing. Everything else keeps working exactly as without the package:
 * the super administrator of the application, policies, Gate::define(), callbacks of other
 * packages, Gate::after. Version 2 behaved this way on purpose. An early draft of version 3
 * answered "no" for a prohibition and named the ability from Gate::after, and every test here
 * is a case that draft would have broken.
 *
 * The provider of a package boots before the providers of the application, so callbacks of the
 * application are registered after ours. The tests register theirs in setUp() for the same order.
 */
class GateIntegrationTest extends FeatureTestCase
{
    /** @var TestUser */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [TestUser::class, 'Role']);

        foreach (['orders.view', 'view', 'update'] as $rule) {
            AccessRules::newRule($rule, $rule);
        }

        Access::for('Role', 'staff')->create('Staff');
        Access::for('Role', 'staff')->deny('orders.view');

        $this->user = TestUser::factory()->make()->forceFill(['id' => 7, 'name' => 'Ann', 'is_admin' => false]);
        $this->user->inheritPermissionFrom('Role', 'staff');
    }

    public function test_the_super_administrator_of_the_application_is_not_locked_out_by_a_prohibition(): void
    {
        Gate::before(fn ($user) => $user->is_admin ? true : null);

        $this->assertFalse($this->user->can('orders.view'));

        $this->user->is_admin = true;
        $this->assertTrue($this->user->can('orders.view'), 'a prohibition inherited from a role is no veto');

        $this->user->addProhibition('orders.view');
        $this->assertTrue($this->user->can('orders.view'), 'nor is an own one');
    }

    public function test_a_rule_named_like_a_policy_method_does_not_close_the_policy(): void
    {
        Gate::policy(DummyModel::class, gatePolicy::class);
        $record = new DummyModel;

        $this->assertTrue($this->user->can('view', $record), 'the policy permits and the package says nothing');

        $this->user->addProhibition('view');
        $this->assertTrue($this->user->can('view', $record), 'a prohibition of "view" is about the rule "view", not about every model');
        $this->assertFalse($this->user->can('update', $record), 'the policy refuses, nobody permits');

        $this->user->addPermission('update');
        $this->assertTrue($this->user->can('update', $record), 'a permission of the package comes first, as Gate::before does');
    }

    public function test_another_package_at_gate_before_gets_its_word(): void
    {
        // The way role packages plug in: "yes" or nothing, never "no".
        Gate::before(fn ($user, $ability) => $ability === 'orders.view' && $user->name === 'Ann' ? true : null);

        $this->assertTrue($this->user->can('orders.view'));
        $this->assertTrue(Gate::forUser($this->user)->any(['nothing.here', 'orders.view']));
        $this->assertFalse(Gate::forUser($this->user)->none(['nothing.here', 'orders.view']));
    }

    public function test_gate_after_of_the_application_still_decides_what_nobody_decided(): void
    {
        Gate::after(fn ($user, $ability, $result) => $result === null && $ability === 'reports.view' ? true : null);

        $this->assertTrue($this->user->can('reports.view'), 'the package registers nothing at Gate::after');
        $this->assertFalse($this->user->can('reports.edit'));
    }

    public function test_what_gate_returns_keeps_its_types(): void
    {
        $this->user->addPermission('view');

        // Code that calls raw() and casts the result got an object, and so "true", from the draft for every prohibition.
        $this->assertTrue(Gate::forUser($this->user)->raw('view'));
        $this->assertNull(Gate::forUser($this->user)->raw('orders.view'));

        $custom = Response::deny('Not on Sundays.');
        Gate::define('orders.view', fn () => $custom);
        $this->assertSame('Not on Sundays.', Gate::forUser($this->user)->inspect('orders.view')->message(), 'the message of the application arrives untouched');
    }

    public function test_the_contract_of_version_2_still_resolves_and_promises_nothing(): void
    {
        $this->assertInstanceOf(AccessRules::class, app(AccessRulesContract::class));
        $this->assertSame([], get_class_methods(AccessRulesContract::class), 'an empty shell: whoever type-hints it sees that it has to go');
        $this->assertStringContainsString('@deprecated', (string) (new \ReflectionClass(AccessRulesContract::class))->getDocComment());
    }
}

class gatePolicy
{
    public function view(): bool
    {
        return true;
    }

    public function update(): bool
    {
        return false;
    }
}

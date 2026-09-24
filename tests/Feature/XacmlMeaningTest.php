<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Shop\Client;
use Tests\Fixtures\Shop\Order;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;
use Tests\Fixtures\Xacml\MiniPdp;
use Wnikk\LaravelAccessRules\AccessRules;
use Wnikk\LaravelAccessRules\Facades\Access;

/**
 * An exported policy has to mean in XACML what the rows mean in the package.
 *
 * The package cannot check that by itself, it has no XACML engine. Tests\Fixtures\Xacml\MiniPdp
 * is one, written from the standard for the part of it the export uses, and it shares no code
 * with the package. Every scenario of the ABAC catalogue is exported, decided by MiniPdp for
 * every record, and compared with the decision of the package.
 *
 * One difference is expected and asserted, not hidden. A record with NULL in a compared column
 * is "unknown" for the package, and the permission is skipped. For XACML the attribute is missing
 * and the decision is Indeterminate, which an enforcement point turns into a refusal. So XACML
 * may refuse where the package decides, and must never permit where the package does not.
 */
class XacmlMeaningTest extends FeatureTestCase
{
    /** @var TestUser */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-18 12:00:00');
        Config::set('access.owner_types', [TestUser::class, 'Team', 'Role']);
        Config::set('access.tenant_types', ['Team']);
        ShopSchema::create();
        ShopSchema::seed();

        $this->user = TestUser::factory()->make()->forceFill(['id' => 7, 'department_id' => 1, 'level' => 3, 'approval_limit' => 500, 'prefix' => 're']);
        foreach ([1, 2] as $team) {
            Access::for('Team', $team)->create('Team '.$team);
            $this->user->inheritPermissionFrom('Team', $team);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function scenarios(): array
    {
        return ConditionsCatalogueTest::scenarios();
    }

    #[DataProvider('scenarios')]
    public function test_the_exported_policy_decides_like_the_package(string $resource, string $condition, array $expected): void
    {
        AccessRules::newRule('scenario.view', 'Scenario', resource: $resource);
        $this->user->addPermission('scenario.view', when: $condition);

        $export  = $this->exportXacml();
        $records = $resource === 'order' ? Order::orderBy('id')->get() : Client::orderBy('id')->get();
        $exact   = 0;

        foreach ($records as $record) {
            $native = $this->user->hasPermission('scenario.view', $record);
            $xacml  = $this->decide($export, 'scenario.view', $record, $resource);

            // Indeterminate becomes a refusal at the enforcement point: stricter than the package at most, never wider.
            if ($xacml === MiniPdp::INDETERMINATE) {
                continue;
            }

            $exact++;
            $this->assertSame($native === true, $xacml === MiniPdp::PERMIT, $condition.' for #'.$record->id.': package '.var_export($native, true).', XACML '.$xacml);
        }

        // A scenario where every record is Indeterminate would pass without checking anything. One scenario
        // is like that by design: its value is no number for any record.
        if (! str_contains($condition, 'user.prefix >')) {
            $this->assertGreaterThan(0, $exact, 'at least one record is decided by both');
        }
        $this->assertSame($expected, $records->filter(fn ($r) => $this->user->hasPermission('scenario.view', $r) === true)->pluck('id')->values()->all());
    }

    public function test_the_five_steps_of_priority_survive_the_export(): void
    {
        AccessRules::newRule('orders.view', 'View', resource: 'order');
        AccessRules::newRule('orders.update', 'Update', resource: 'order');
        AccessRules::newRule('orders.update.self', 'Update own', resource: 'order');

        Access::for('Role', 'staff')->create('Staff');
        Access::for('Role', 'staff')->allow('orders.view');
        Access::for('Role', 'staff')->deny('orders.view', when: 'order.cost > 400');
        Access::for('Role', 'manager')->create('Managers');
        Access::for('Role', 'manager')->inheritFrom('Role', 'staff');
        Access::for('Role', 'manager')->deny('orders.update', when: "order.status == 'published'");

        $this->user->inheritPermissionFrom('Role', 'manager');
        $this->user->addPermission('orders.view', when: 'order.cost > 800');       // own permission beats the inherited prohibition
        $this->user->addProhibition('orders.view', when: "order.status == 'review'"); // own prohibition beats everything
        $this->user->addPermission('orders.update.self');

        $export = $this->exportXacml();

        foreach (['orders.view', 'orders.update'] as $ability) {
            foreach (Order::orderBy('id')->get() as $order) {
                $native = $this->user->hasPermission($ability, $order);
                $xacml  = $this->decide($export, $ability, $order, 'order');

                $this->assertSame([true => MiniPdp::PERMIT, false => MiniPdp::DENY][$native] ?? MiniPdp::NOT_APPLICABLE, $xacml, $ability.' for order #'.$order->id);
            }
        }
    }

    public function test_a_permission_is_never_wider_in_xacml_when_an_attribute_is_missing(): void
    {
        AccessRules::newRule('orders.view', 'View', resource: 'order');
        $this->user->addPermission('orders.view');
        $this->user->addProhibition('orders.view', when: "order.client.city == 'Y'");

        $export = $this->exportXacml();

        // Order 6 has no client. The package skips the prohibition and permits; XACML cannot tell and says so.
        $this->assertTrue($this->user->hasPermission('orders.view', Order::find(6)));
        $this->assertSame(MiniPdp::INDETERMINATE, $this->decide($export, 'orders.view', Order::find(6), 'order'));
        $this->assertSame(MiniPdp::DENY, $this->decide($export, 'orders.view', Order::find(3), 'order'));
        $this->assertSame(MiniPdp::PERMIT, $this->decide($export, 'orders.view', Order::find(1), 'order'));
    }

    /**
     * Identifiers are written out the way docs/xacml.md gives them to whoever connects an XACML
     * engine, not taken from constants of the package: a renamed attribute has to fail here.
     */
    private const OWN = 'urn:wnikk:access:';

    private function decide(array $export, string $ability, Model $record, string $alias): string
    {
        $user  = $this->user;
        $roles = $export['manifest']['roles']['TestUser:7'] ?? [];

        $attributes = function (string $category, string $id) use ($user, $roles, $ability, $record, $alias): array {
            $value = match (true) {
                $id === 'urn:oasis:names:tc:xacml:1.0:subject:subject-id' => '7',
                $id === self::OWN.'subject:type'                          => 'TestUser',
                $id === 'urn:oasis:names:tc:xacml:2.0:subject:role'       => $roles,
                $id === 'urn:oasis:names:tc:xacml:1.0:action:action-id'   => $ability,
                $id === self::OWN.'resource:author'                       => $record->getAttribute('testuser_id'),
                $id === self::OWN.'subject:tenant'                        => array_map(fn ($r) => substr($r, 5), array_filter($roles, fn ($r) => str_starts_with($r, 'Team:'))),
                str_starts_with($id, self::OWN.'subject:')                => data_get($user, substr($id, strlen(self::OWN.'subject:'))),
                str_starts_with($id, self::OWN.'environment:')            => ['weekday' => 5, 'hour' => 12, 'ip' => '127.0.0.1', 'app' => 'testing'][substr($id, strlen(self::OWN.'environment:'))] ?? null,
                str_ends_with($id, ':current-dateTime')                   => '2026-09-18T12:00:00',
                str_ends_with($id, ':current-date')                       => '2026-09-18',
                str_starts_with($id, self::OWN.'resource:'.$alias.':')    => data_get($record, substr($id, strlen(self::OWN.'resource:'.$alias.':'))),
                default                                                   => throw new \RuntimeException('no attribute '.$id),
            };

            if ($value instanceof Collection) {
                return $value->map->getKey()->all();
            }

            $values = $value === null ? [] : (is_array($value) ? $value : [$value]);

            return array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, $values);
        };

        return (new MiniPdp($export['policy']))->decide($attributes, fn (string $text) => $this->askThePackage($text, $record, $alias));
    }

    /**
     * The one function of an export that is not XACML carries text of the package, and only the
     * package can run it. It is asked from outside, like everything else here: the text becomes
     * a permission of the same user, and its opposite another one. Yes to the first is "true",
     * yes to the second is "false", no to both is "unknown".
     */
    private function askThePackage(string $text, Model $record, string $alias): ?bool
    {
        foreach (['xacml.dsl.yes' => $text, 'xacml.dsl.no' => 'not ('.$text.')'] as $rule => $when) {
            AccessRules::newRule($rule, $rule, resource: $alias);
            $this->user->addPermission($rule, when: $when);
        }

        try {
            return match (true) {
                $this->user->hasPermission('xacml.dsl.yes', $record) === true => true,
                $this->user->hasPermission('xacml.dsl.no', $record) === true  => false,
                default                                                       => null,
            };
        } finally {
            AccessRules::delRule('xacml.dsl.yes', true);
            AccessRules::delRule('xacml.dsl.no', true);
        }
    }
}

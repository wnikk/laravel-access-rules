<?php

namespace Tests\Unit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Shop\Order;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;
use Tests\TestCase;
use Wnikk\LaravelAccessRules\Conditions\Cond;
use Wnikk\LaravelAccessRules\Conditions\ConditionCompiler;
use Wnikk\LaravelAccessRules\Conditions\Evaluation\Context;
use Wnikk\LaravelAccessRules\Exceptions\InvalidConditionException;
use Wnikk\LaravelAccessRules\Exceptions\UntranslatableConditionException;

/**
 * A condition is text that may come from an admin panel, and this file treats it as hostile.
 *
 * Two promises. A mistake surfaces when the condition is saved, with a message that names it,
 * and nothing is written. And no text reaches what it should not: methods of models that are
 * not relations, models outside of config access.resources, raw SQL.
 */
class conditionSafetyTest extends TestCase
{
    /** @var TestUser */
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [TestUser::class]);
        ShopSchema::create();
        ShopSchema::seed();

        $this->getAccessRules()->newRule('orders.view', 'View orders', resource: 'order');
        $this->user = TestUser::factory()->make()->forceFill(['id' => 7]);
    }

    public static function invalidConditions(): array
    {
        return [
            'syntax'                             => ['order.cost >'],
            'unclosed bracket'                   => ['(order.cost > 1'],
            'unknown root'                       => ['foo.bar == 1'],
            'column after to-many relation'      => ['order.items.price > 1'],
            'to-many relation compared directly' => ['order.items == 1'],
            'aggregate over a column'            => ['sum(order.cost) > 1'],
            'sum without a column'               => ['sum(order.items) > 1'],
            'count with a column'                => ['count(order.items.price) > 1'],
            'method that is not a relation'      => ['order.dangerous.id == 1'],
            'relation without declared type'     => ['count(order.untyped) > 1'],
            'two resources at once'              => ['order.cost > 1 && client.city == 1'],
            'list with an attribute inside'      => ['order.cost in [1, order.budget]'],
            'root without an attribute'          => ['user == 1'],
        ];
    }

    #[DataProvider('invalidConditions')]
    public function test_invalid_condition_is_rejected_when_it_is_saved(string $condition): void
    {
        $this->expectException(InvalidConditionException::class);

        $this->user->addPermission('orders.view', when: $condition);
    }

    public function test_nothing_is_saved_when_condition_is_invalid(): void
    {
        try {
            $this->user->addPermission('orders.view', when: 'order.nothing.here == 1');
        } catch (InvalidConditionException) {
        }

        $this->assertNull($this->user->hasPermission('orders.view'));
        $this->assertSame(0, $this->user->getOwner()->permission()->count());
    }

    public function test_model_has_to_be_listed_in_resources(): void
    {
        $resources = ShopSchema::RESOURCES;
        unset($resources['item']);
        Config::set('access.resources', $resources);

        $this->expectException(InvalidConditionException::class);
        $this->expectExceptionMessage('is not listed in config access.resources');

        $this->user->addPermission('orders.view', when: 'order.items.count < 3');
    }

    public function test_morph_to_relation_is_rejected(): void
    {
        $this->getAccessRules()->newRule('comments.view', 'View comments', resource: 'comment');

        $this->expectException(InvalidConditionException::class);
        $this->expectExceptionMessage('morphTo');

        $this->user->addPermission('comments.view', when: 'comment.commentable.cost > 1');
    }

    public function test_relation_without_declared_type_can_be_listed_in_config(): void
    {
        Config::set('access.resources', ['order' => ['model' => Order::class, 'relations' => ['untyped']]] + ShopSchema::RESOURCES);

        $this->user->addPermission('orders.view', when: 'count(order.untyped) >= 5');

        $this->assertSame([2], Order::query()->allowedTo('orders.view', $this->user)->pluck('id')->all());
    }

    public function test_values_are_always_bound(): void
    {
        $this->user->addPermission('orders.view', when: "order.status == 'x\\' or 1=1 --'");

        $query = Order::query()->allowedTo('orders.view', $this->user);

        $this->assertStringNotContainsString('1=1', $query->toSql());
        $this->assertContains("x' or 1=1 --", $query->getBindings());
        $this->assertSame([], $query->pluck('id')->all());
    }

    /**
     * An editor sends trees back, so a tree can come from a browser. Names inside it end up in SQL
     * as identifiers, which makes a tree the one place where injection could enter.
     */
    public function test_hand_made_tree_is_checked(): void
    {
        $compiler = app(ConditionCompiler::class);

        foreach ([
            ['eval', 'phpinfo()'],
            ['cmp', 'like', ['val', 1], ['val', 1]],
            ['res', [], 'cost; drop table shop_orders'],
            ['agg', 'avg', [], 'items', null, null],
            ['not'],
        ] as $tree) {
            try {
                $compiler->compile($tree);
                $this->fail('Tree must be rejected: '.json_encode($tree));
            } catch (InvalidConditionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cond_builder_gives_the_same_tree_as_text(): void
    {
        $compiler = app(ConditionCompiler::class);

        $built = Cond::all(
            Cond::attr('order.cost')->gt(100),
            Cond::count('order.items')->lt(3),
            Cond::any(
                Cond::attr('order.client.city')->isNull(),
                Cond::attr('order.client.team_id')->in(Cond::attr('user.tenant')),
            ),
            Cond::not(Cond::exists('order.products', Cond::attr('restricted')->eq(true))),
            Cond::attr('order.created_at')->gte(Cond::call('ago', '24 hours')),
        );

        $text = 'order.cost > 100 && order.items.count < 3 && (order.client.city == null || order.client.team_id in user.tenant)'
            ." && !exists(order.products, restricted == true) && order.created_at >= ago('24 hours')";

        $this->assertSame($compiler->compile($text), $compiler->compile($built));
    }

    public function test_time_functions(): void
    {
        Carbon::setTestNow('2026-09-18 12:00:00');

        $this->user->addPermission('orders.view', when: "order.created_at >= monthStart() && order.created_at < after('1 day') && order.created_at >= yearStart()"
            ." && order.created_at <= now() && env.today == today() && order.created_at < ago('7 days')"
        );

        $this->assertSame([2, 5], Order::query()->allowedTo('orders.view', $this->user)->orderBy('id')->pluck('id')->all());
        $this->assertTrue($this->user->can('orders.view', Order::find(5)));
        $this->assertFalse($this->user->can('orders.view', Order::find(1)));

        Carbon::setTestNow();
    }

    public function test_own_attribute_and_function(): void
    {
        Config::set('access.attributes', ['env.region' => fn (Context $context) => 'eu']);
        Config::set('access.functions', ['limitFor' => fn (array $args, Context $context) => $args[0] === 'eu' ? 120 : 1000]);

        $this->user->addPermission('orders.view', when: 'order.cost <= limitFor(env.region)');

        $this->assertSame([3, 5], Order::query()->allowedTo('orders.view', $this->user)->orderBy('id')->pluck('id')->all());
        $this->assertTrue($this->user->can('orders.view', Order::find(5)));
        $this->assertFalse($this->user->can('orders.view', Order::find(1)));
    }

    /**
     * A PHP function cannot run inside the database. Failing with an exception is the choice:
     * the silent alternatives are an unfiltered list or an empty one.
     */
    public function test_own_function_over_columns_works_for_a_record_but_not_for_a_query(): void
    {
        Config::set('access.functions', ['half' => fn (array $args) => $args[0] / 2]);

        $this->user->addPermission('orders.view', when: 'half(order.cost) > 100');

        $this->assertTrue($this->user->can('orders.view', Order::find(4)));
        $this->assertFalse($this->user->can('orders.view', Order::find(1)));

        $this->expectException(UntranslatableConditionException::class);
        Order::query()->allowedTo('orders.view', $this->user)->get();
    }

    /**
     * A condition that throws must not open access, and must not break the page either.
     */
    public function test_error_inside_condition_denies_access(): void
    {
        Config::set('access.functions', ['boom' => fn () => throw new \RuntimeException('boom')]);
        $this->user->addPermission('orders.view', when: 'order.cost > boom()');

        $this->assertFalse($this->user->can('orders.view', Order::find(1)));
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Shop\Order;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;
use Wnikk\LaravelAccessRules\Exceptions\InvalidConditionException;

/**
 * A condition is text that may come from an admin panel, and this file treats it as hostile.
 *
 * Two promises. A mistake surfaces when the condition is saved, with a message that names it,
 * and nothing is written. And no text reaches what it should not: methods of models that are
 * not relations, models outside of config access.resources, raw SQL.
 */
class ConditionMistakesTest extends FeatureTestCase
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
            'unknown function'                   => ['order.cost > yesterday()'],

            // arithmetic, between, text
            'text column in arithmetic'      => ['order.status * 2 > 1'],
            'text literal in arithmetic'     => ["order.cost + 'many' > 1"],
            'a comparison in arithmetic'     => ['(order.cost > 1) + 1 > 1'],
            'null in arithmetic'             => ['order.cost + null > 1'],
            'text function over a number'    => ["startsWith(order.cost, '1')"],
            'needle that reads the record'   => ['startsWith(order.status, order.status)'],
            'text function without a needle' => ['contains(order.status)'],
            'lower() with two arguments'     => ["lower(order.status, 'x') == 'x'"],
            'between without its second end' => ['order.cost between 1 or 5'],

            // pivot
            'pivot outside of a filter'             => ['order.pivot.quantity > 1'],
            'pivot of a one-to-many relation'       => ['exists(order.items, pivot.quantity > 1)'],
            'pivot column the relation never loads' => ['exists(order.products, pivot.created_at != null)'],
            'sum of such a column'                  => ['sum(order.products.pivot.price) > 1'],

            // numbers against text
            'text literal against a number column' => ["order.cost > 'many'"],
            'text in a list for a number column'   => ["order.cost in [1, 'two']"],
            'number column against a text column'  => ['order.status == order.cost'],
            'arithmetic against a text column'     => ['order.cost * 2 == order.status'],
            'count against a text column'          => ['count(order.items) > order.status'],

            // trees
            'tree function over an unknown model' => ["order.department_id in below('nothing.id', 1)"],
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
        foreach ([
            ['eval', 'phpinfo()'],
            ['cmp', 'like', ['val', 1], ['val', 1]],
            ['res', [], 'cost; drop table shop_orders'],
            ['agg', 'avg', [], 'items', null, null],
            ['math', '%', ['val', 1], ['val', 2]],
            ['not'],
        ] as $tree) {
            try {
                $this->user->addPermission('orders.view', when: $tree);
                $this->fail('Tree must be rejected: '.json_encode($tree));
            } catch (InvalidConditionException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $this->user->getOwner()->permission()->count());
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

<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\Fixtures\Shop\Order;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;
use Wnikk\LaravelAccessRules\Conditions\Cond;
use Wnikk\LaravelAccessRules\Conditions\Context;
use Wnikk\LaravelAccessRules\Exceptions\UntranslatableConditionException;

/**
 * The language of conditions as docs/conditions.md describes it to the person who writes them:
 * the builder says what the text says, a stored condition reads back as text, time and the
 * project's own attributes and functions take part.
 *
 * What conditions select is held by ConditionsCatalogueTest, and what saving refuses by
 * ConditionMistakesTest. This file holds the promises about the two ways of writing a condition
 * and about extending the language.
 */
class ConditionLanguageTest extends FeatureTestCase
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
        $this->user = TestUser::factory()->make()->forceFill(['id' => 7, 'approval_limit' => 500, 'prefix' => 're']);
    }

    /**
     * The builder is for people who dislike strings, not a second language. Both ways are saved
     * and what is stored is compared, which is what decides every check afterwards.
     */
    public function test_the_builder_says_what_the_text_says(): void
    {
        $pairs = [
            'order.cost > 100 && order.items.count < 3 && (order.client.city == null || order.client.team_id in user.tenant)'
                ." && !exists(order.products, restricted == true) && order.created_at >= ago('24 hours')" => Cond::all(
                    Cond::attr('order.cost')->gt(100),
                    Cond::count('order.items')->lt(3),
                    Cond::any(Cond::attr('order.client.city')->isNull(), Cond::attr('order.client.team_id')->in(Cond::attr('user.tenant'))),
                    Cond::not(Cond::exists('order.products', Cond::attr('restricted')->eq(true))),
                    Cond::attr('order.created_at')->gte(Cond::call('ago', '24 hours')),
                ),
            'order.cost * 1.2 - 10 > order.budget'       => Cond::attr('order.cost')->times(1.2)->minus(10)->gt(Cond::attr('order.budget')),
            'order.cost / 4 + 1 <= user.approval_limit'  => Cond::attr('order.cost')->dividedBy(4)->plus(1)->lte(Cond::attr('user.approval_limit')),
            'order.cost between 100 and 200'             => Cond::attr('order.cost')->between(100, 200),
            "startsWith(order.status, 'dr')"             => Cond::attr('order.status')->startsWith('dr'),
            'endsWith(lower(order.status), user.prefix)' => Cond::attr('order.status')->lower()->endsWith(Cond::attr('user.prefix')),
            "contains(order.client.city, 'X')"           => Cond::attr('order.client.city')->contains('X'),
            'exists(order.products, pivot.quantity > 4)' => Cond::exists('order.products', Cond::attr('pivot.quantity')->gt(4)),
            'sum(order.products.pivot.quantity) >= 6'    => Cond::sum('order.products.pivot.quantity')->gte(6),
        ];

        foreach ($pairs as $text => $built) {
            $this->assertSame($this->stored($text), $this->stored($built), $text);
        }
    }

    /**
     * An admin panel shows a condition as text and gets that text from what is stored. Brackets
     * that carry meaning survive, brackets that do not are dropped, a number typed as text next
     * to a number column is shown as the number it is compared as.
     */
    public function test_a_stored_condition_reads_back_as_text(): void
    {
        $shown = [
            "order.cost * '2' > 10"                      => 'order.cost * 2 > 10',
            '(order.cost + 1) * 2 > 10'                  => '(order.cost + 1) * 2 > 10',
            'order.cost - (order.budget - 1) > 10'       => 'order.cost - (order.budget - 1) > 10',
            '(order.cost - order.budget) - 1 > 10'       => 'order.cost - order.budget - 1 > 10',
            'order.cost between 1 and 5'                 => 'order.cost >= 1 && order.cost <= 5',
            'order.cost in [-1, 2]'                      => 'order.cost in [-1, 2]',
            "order.cost >= '100'"                        => 'order.cost >= 100',
            'order.items.count < 3 and not order.locked' => 'count(order.items) < 3 && !(order.locked == true)',
        ];

        foreach ($shown as $typed => $expected) {
            $this->assertSame($expected, $this->conditionText($this->stored($typed), 'order'), $typed);
        }
    }

    public function test_an_explanation_shows_the_numbers_behind_an_expression(): void
    {
        $this->user->addPermission('orders.view', when: 'order.cost * 1.2 > order.budget && order.cost <= user.approval_limit / 2');

        $report = $this->user->access()->explain('orders.view', Order::find(2));

        $this->assertTrue($report['decision']);
        $this->assertEquals(['order.cost' => 150, 'order.budget' => 100, 'user.approval_limit' => 500], $report['values']);
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
     * What saving a condition leaves in the permission, for comparing two ways of writing it.
     */
    private function stored(string|Cond $when): ?array
    {
        $this->user->remPermission('orders.view');
        $this->user->addPermission('orders.view', when: $when);

        return $this->user->getOwner()->permission()->first()->condition;
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Shop\Order;
use Tests\Fixtures\Shop\ShopSchema;
use Tests\Fixtures\TestUser;

/**
 * docs/conditions.md, "Numbers and text": the column decides how a comparison works, for one
 * record and for a list alike.
 *
 * Text that looks like a number is where the two used to part ways. The database compares
 * a varchar as text, '01234' <> '1234' and '10' < '9'; PHP compares two such strings as numbers.
 * Order 1 below was absent from the list of "code == '1234'" and opened by URL. A decimal
 * column is the counterweight: it also arrives as a string and has to stay a number.
 *
 * Every case is asked both ways and compared with ids written by hand.
 */
class NumbersAndTextTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('access.owner_types', [TestUser::class, 'Role']);
        ShopSchema::create();
        Schema::table('shop_orders', function ($table) {
            $table->string('code')->nullable();
            $table->decimal('price', 10, 2)->nullable();
        });
    }

    public static function codesAndPrices(): array
    {
        return [
            'text column, number literal'          => ['order.code == 1234', null, [2]],
            'text column, text literal'            => ["order.code == '1234'", null, [2]],
            'text column is ordered as text'       => ["order.code > '5'", null, [4, 5]],
            'text column, list'                    => ["order.code in ['1234', 9]", null, [2, 4]],
            'text column, not in list'             => ["order.code not in ['1234', 9]", null, [1, 3, 5]],
            'text column, text of the user'        => ['order.code == user.code', '1234', [2]],
            'text column, number of the user'      => ['order.code == user.code', 1234, [2]],
            'decimal column stays a number'        => ['order.price > 100', null, [2, 5]],
            'decimal column, number given as text' => ["order.price >= '100'", null, [2, 3, 5]],
            'decimal column, text of the user'     => ['order.price < user.code', '100.5', [1, 3, 4]],
            'number column, not a number: unknown' => ['order.price > user.code', 'abc', []],
            'unknown stays unknown under not'      => ['not (order.price > user.code)', 'abc', []],
            'number column, list with a stranger'  => ['order.price in user.list', null, [3]],
        ];
    }

    #[DataProvider('codesAndPrices')]
    public function test_text_that_looks_like_a_number(string $when, mixed $code, array $expected): void
    {
        // Five orders whose code looks like a number and whose price is a decimal, which PDO hands over as text.
        $base = ['client_id' => 1, 'testuser_id' => 7, 'department_id' => 1, 'cost' => 1, 'status' => 'x', 'locked' => false, 'min_level' => 1, 'created_at' => '2026-01-01 00:00:00'];
        foreach ([[1, '01234', '90.00'], [2, '1234', '150.00'], [3, '10', '100.00'], [4, '9', '9.50'], [5, 'abc', '1000.00']] as [$id, $orderCode, $price]) {
            DB::table('shop_orders')->insert($base + ['id' => $id, 'code' => $orderCode, 'price' => $price]);
        }

        $this->getAccessRules()->newRule('orders.typed', 'Typed', resource: 'order');
        $user = TestUser::factory()->make()->forceFill(['id' => 7, 'code' => $code, 'list' => ['100', 'abc']]);
        $user->addPermission('orders.typed', when: $when);

        [$listed, $opened] = $this->listedAndOpened(Order::class, 'orders.typed', $user);

        $this->assertSame($expected, $listed, 'filter of a list');
        $this->assertSame($expected, $opened, 'check of a record');
    }

    /**
     * Values of one kind against columns of another, on the data of the shop. Each case once gave,
     * or could give, a different answer in the list and for the record on some database.
     */
    public static function mixedKinds(): array
    {
        return [
            'text column = integer of the user'        => ['client', 'client.city == user.id', [3]],
            'text column = integer literal'            => ['client', 'client.city == 7', [3]],
            'integer column = text literal'            => ['client', "client.manager_id == '7'", [1, 2, 4]],
            'integer column in a list of both'         => ['client', "client.manager_id in ['7', 9]", [1, 2, 3, 4]],
            'integer column <= text of the user'       => ['order', 'order.cost <= user.limit', [1, 2, 3, 5]],
            'count against text'                       => ['order', "count(order.items) >= '2'", [1, 2]],
            'sum against a decimal given as text'      => ['client', 'sum(client.payments.amount) > user.limit', [1, 2, 3, 4]],
            'the same with the aggregate on the right' => ['client', 'user.limit < max(client.payments.amount)', [1, 2, 3, 4]],
            'through a relation'                       => ['order', "order.client.manager_id == '9'", [4]],
            'boolean column = integer'                 => ['order', 'order.locked == 1', [4]],
            'boolean column = boolean'                 => ['order', 'order.locked == false && order.cost > 500', [6]],
            'datetime column against a date'           => ['order', "order.created_at >= '2026-09-18'", [1, 3, 6]],
        ];
    }

    #[DataProvider('mixedKinds')]
    public function test_values_of_one_kind_against_columns_of_another(string $resource, string $when, array $expected): void
    {
        ShopSchema::seed();
        DB::table('shop_clients')->where('id', 3)->update(['city' => '7']);   // a text column that holds a number

        $this->getAccessRules()->newRule('shop.see', 'See', resource: $resource);
        $user = TestUser::factory()->make()->forceFill(['id' => 7, 'limit' => '200']);
        $user->addPermission('shop.see', when: $when);

        [$listed, $opened] = $this->listedAndOpened(ShopSchema::RESOURCES[$resource], 'shop.see', $user);

        $this->assertSame($expected, $listed, 'filter of a list');
        $this->assertSame($expected, $opened, 'check of a record');
    }
}

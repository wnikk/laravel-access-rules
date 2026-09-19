<?php

namespace Tests\Fixtures\Shop;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

/**
 * A small shop for tests of conditions: clients with payments, orders with items, products and comments.
 *
 * One data set serves every scenario, so a scenario is one line of text and a list of ids.
 * The price is that rows are chosen with care: each exists to make some scenario tell "yes"
 * from "no", and changing one breaks expectations that are written by hand elsewhere.
 *
 * The models cover every kind of relation conditions support: belongsTo, hasMany, belongsToMany
 * with a pivot table, morphMany and morphOne through a morph map, a table related to itself,
 * hasManyThrough. A relation kind without a model here is a relation kind without a test.
 */
class ShopSchema
{
    public const RESOURCES = [
        'order'   => Order::class,
        'client'  => Client::class,
        'item'    => Item::class,
        'payment' => Payment::class,
        'product' => Product::class,
        'comment' => Comment::class,
    ];

    public static function create(): void
    {
        Config::set('access.resources', self::RESOURCES);
        Relation::morphMap(['order' => Order::class, 'client' => Client::class]);

        Schema::create('shop_clients', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('parent_id')->nullable();
            $t->string('city')->nullable();
            $t->integer('manager_id');
            $t->integer('team_id');
        });
        Schema::create('shop_payments', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('client_id');
            $t->integer('amount');
            $t->dateTime('paid_at');
        });
        Schema::create('shop_orders', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('client_id')->nullable();
            $t->integer('testuser_id');
            $t->integer('department_id');
            $t->integer('cost');
            $t->integer('budget')->nullable();
            $t->string('status');
            $t->boolean('locked');
            $t->integer('min_level');
            $t->dateTime('created_at');
        });
        Schema::create('shop_items', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('order_id');
            $t->integer('price')->default(10);
        });
        Schema::create('shop_products', function (Blueprint $t) {
            $t->increments('id');
            $t->string('category');
            $t->boolean('restricted')->default(false);
        });
        Schema::create('shop_order_product', function (Blueprint $t) {
            $t->integer('order_id');
            $t->integer('product_id');
        });
        Schema::create('shop_comments', function (Blueprint $t) {
            $t->increments('id');
            $t->string('commentable_type');
            $t->integer('commentable_id');
            $t->string('kind');
            $t->integer('author_id');
        });
    }

    /**
     * Written from the point of view of user 7: department 1, level 3, approval limit 500, teams
     * 1 and 2, at 2026-09-18 12:00, a Friday.
     *
     * The edge cases are deliberate. Client 4 has no city and order 6 has no client, which is
     * where NULL logic shows. Order 2 has five items and order 5 none. Client 1 has a payment of
     * this month that "last month" must ignore. Client 2 has a comment that comments of order 2
     * must not pick up, although both have id 2.
     */
    public static function seed(): void
    {
        //       id  parent city  manager team
        foreach ([[1, null, 'X',  7, 1],
            [2, 1,    'Y',  7, 1],
            [3, 1,    'X',  9, 3],
            [4, null, null, 7, 2]] as [$id, $parent, $city, $manager, $team]) {
            Client::create(['id' => $id, 'parent_id' => $parent, 'city' => $city, 'manager_id' => $manager, 'team_id' => $team]);
        }

        foreach ([[1, 700, '2026-08-10'], [1, 600, '2026-08-20'], [1, 5000, '2026-09-05'], [2, 2000, '2026-08-15'],
            [3, 900, '2026-08-02'], [3, 900, '2026-07-30'], [4, 1500, '2026-08-31']] as [$client, $amount, $date]) {
            Payment::create(['client_id' => $client, 'amount' => $amount, 'paid_at' => $date.' 10:00:00']);
        }

        foreach ([[1, 'food', false], [2, 'tools', false], [3, 'weapon', true]] as [$id, $category, $restricted]) {
            Product::create(['id' => $id, 'category' => $category, 'restricted' => $restricted]);
        }

        //        id client user dept cost budget status       locked lvl created_at             items products   comments (kind, author)
        $orders = [[1, 1,    7,   1,   150, 200,  'draft',     0,     1,  '2026-09-18 08:00:00', 2,    [1, 2],    [['note', 7]]],
            [2, 1,    7,   1,   150, 100,  'review',    0,     5,  '2026-09-10 09:00:00', 5,    [3],       [['claim', 9], ['note', 7]]],
            [3, 2,    7,   2,   50,  100,  'published', 0,     1,  '2026-09-18 11:00:00', 1,    [],        []],
            [4, 3,    9,   1,   500, 600,  'draft',     1,     3,  '2026-09-17 13:00:00', 1,    [1, 3],    [['claim', 7]]],
            [5, 4,    9,   2,   101, 50,   'draft',     0,     2,  '2026-09-01 09:00:00', 0,    [2],       []],
            [6, null, 9,   1,   900, null, 'review',    0,     3,  '2026-09-18 00:30:00', 1,    [1],       [['note', 9]]]];

        foreach ($orders as [$id, $client, $user, $dept, $cost, $budget, $status, $locked, $level, $created, $items, $products, $comments]) {
            $order = Order::create(['id' => $id, 'client_id' => $client, 'testuser_id' => $user, 'department_id' => $dept, 'cost' => $cost,
                'budget'                 => $budget, 'status' => $status, 'locked' => $locked, 'min_level' => $level, 'created_at' => $created]);

            for ($i = 0; $i < $items; $i++) {
                Item::create(['order_id' => $id, 'price' => 10 * ($i + 1)]);
            }
            $order->products()->attach($products);
            foreach ($comments as [$kind, $author]) {
                $order->comments()->create(['kind' => $kind, 'author_id' => $author]);
            }
        }

        Client::find(2)->comments()->create(['kind' => 'claim', 'author_id' => 9]);
    }
}

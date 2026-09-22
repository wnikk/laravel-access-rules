---
title: Tutorial ABAC step-by-step
weight: 4
---

> 📷 **Cover image.** A wide banner in the style of the first article: the title "ABAC in Laravel: permissions that look at your data", a Laravel logo, and a short line "roles + conditions + filtered lists".

This is the second part. The first one, [How to use Access Control Rules step by step](tutorial-basic-step-by-step.md), built roles and permissions: **RBAC**.
This one goes further: permissions that **look at the data**. That is **ABAC**, _Attribute-Based Access Control_, and it arrived in version **3.x** of "[**wnikk/laravel-access-rules**](https://github.com/wnikk/laravel-access-rules)".

You do not need the first part to follow this one. Everything is built from scratch.

## What we had in 2.x

A short reminder, in case you skipped part one:

- a **rule** is a name of something that can be permitted, `orders.view`;
- an **owner** holds permissions: a user, a role, a group;
- owners **inherit** from each other, which is how roles work;
- an **option** splits one rule into several, `profile.update.email`;
- the magic suffix **`.self`** means "only the author of the record".

All of it still works in 3.x, with the same tables and the same methods.

And here is what it could not say:

- "a manager sees orders **over 100** with **fewer than 3 items**";
- "only orders of **my department**";
- "everything, except clients from **Paris**";
- and the hardest one: "**show me the list** of what I am allowed to see".

In 2.x each of these meant a policy class and a hand-written query, and the two had to agree forever.
In 3.x each of them is **one line**.

```php
$role->addPermission('orders.view', when: 'order.cost > 100 && order.items.count < 3');

$user->can('orders.view', $order);            // one record
Order::allowedTo('orders.view')->paginate();  // the list, filtered by the database
```

## Where will we start?

We build a tiny back office of a shop:

1. **Orders** with items, a client, a category and products.
2. Two users, **Ann** and **Bob**, a role and two teams.
3. Eighteen short examples. Each adds one idea and shows its result on the same six orders.

The data is small on purpose: you can check every result **by eye**.

## Step 1: Create Laravel application
Version 3 needs **Laravel 13** and **PHP 8.4**.
```bash
composer create-project laravel/laravel abac-example
```

## Step 2: Install the package
```bash
composer require wnikk/laravel-access-rules
php artisan vendor:publish --tag=access-config --tag=access-migrations
```

## Step 3: Tell the package who and what
File _config/access.php_. Three lists matter today:
```php
// who can hold permissions
'owner_types' => [
    App\Models\User::class,
    'Role',
    'Team',
],

// models that conditions may read, by the name they have in conditions
'resources' => [
    'order'    => App\Models\Order::class,
    'client'   => App\Models\Client::class,
    'item'     => App\Models\Item::class,
    'category' => App\Models\Category::class,
    'product'  => App\Models\Product::class,
],

// owner types that work as a tenant, see Example 9
'tenant_types' => ['Team'],
```
`resources` is a **whitelist**. A condition can come from an admin panel, and it can only touch models listed here.

## Step 4: Migrations
```bash
php artisan make:migration create_shop_tables
```
```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->integer('department_id')->default(1);
        $table->integer('approval_limit')->default(0);
    });
    Schema::create('clients', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('city')->nullable();
        $table->integer('team_id');
    });
    Schema::create('categories', function (Blueprint $table) {
        $table->id();
        $table->integer('parent_id')->nullable();
        $table->string('name');
    });
    Schema::create('products', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->boolean('restricted')->default(false);
    });
    Schema::create('orders', function (Blueprint $table) {
        $table->id();
        $table->string('number');
        $table->integer('client_id')->nullable();
        $table->integer('user_id');            // the author
        $table->integer('department_id');
        $table->integer('category_id');
        $table->integer('cost');
        $table->integer('budget')->nullable();
        $table->string('status');
        $table->boolean('locked')->default(false);
        $table->dateTime('created_at');
    });
    Schema::create('items', function (Blueprint $table) {
        $table->id();
        $table->integer('order_id');
        $table->integer('price');
    });
    Schema::create('order_product', function (Blueprint $table) {
        $table->integer('order_id');
        $table->integer('product_id');
        $table->integer('quantity')->default(1);
    });
}
```
```bash
php artisan migrate
```

## Step 5: Models
The user gets the usual trait:
```php
use Wnikk\LaravelAccessRules\Traits\HasPermissions;

class User extends Authenticatable
{
    use HasPermissions;
```
The order gets a **new** one. It adds `allowedTo()`, the filter of lists:
```php
use Wnikk\LaravelAccessRules\Traits\HasAccessScope;

class Order extends Model
{
    use HasAccessScope;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['locked' => 'boolean'];

    public function client(): BelongsTo     { return $this->belongsTo(Client::class); }
    public function category(): BelongsTo   { return $this->belongsTo(Category::class); }
    public function items(): HasMany        { return $this->hasMany(Item::class); }
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('quantity');
    }
}
```
**Important:** relations declare their **return type**. That is how the package knows `client` is a relation. It never calls a method to find out, because the method could be `delete()`.

`Client`, `Item`, `Product` are plain models. `Category` points at itself:
```php
class Category extends Model
{
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
}
```

## Step 6: Data
Seed it any way you like. This is what the examples expect. "Today" is a **Friday, 12:00**.

**Users**

| id | name | department_id | approval_limit |
|---|---|---|---|
| 1 | Ann | 1 | 500 |
| 2 | Bob | 2 | 100 |

**Clients**: 1 Acme (Berlin, team 1), 2 Bolt (Paris, team 1), 3 Core (Berlin, team 2), 4 Dune (**no city**, team 2)

**Categories**: 1 Electronics → 2 Phones, 3 Laptops; 4 Furniture

**Products**: 1 Phone, 2 Charger, 3 Battery pack (**restricted**)

**Orders**

| id | number | client | author | dept | category | cost | budget | status | locked | created | items | products × quantity |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | EU-1001 | Acme | Ann | 1 | Phones | 150 | 200 | draft | | today 08:00 | 100, 50 | Phone ×1, Charger ×2 |
| 2 | EU-1002 | Bolt | Ann | 1 | Laptops | 900 | 800 | review | | 10 days ago | 300, 300, 200, 100 | Battery pack ×5 |
| 3 | US-2001 | Core | Bob | 2 | Furniture | 50 | 100 | paid | | today 11:00 | 50 | |
| 4 | EU-1003 | Dune | Bob | 1 | Electronics | 450 | 600 | draft | **yes** | yesterday | 450 | Phone ×1, Battery pack ×1 |
| 5 | US-2002 | Acme | Bob | 2 | Phones | 120 | | draft | | 20 days ago | | |
| 6 | EU-1004 | **none** | Ann | 2 | Furniture | 700 | 700 | review | | today 00:30 | 400, 200, 100 | Charger ×10 |

## Step 7: Rules, a role and two teams
```bash
php artisan make:seeder AccessSeeder
```
```php
use Wnikk\LaravelAccessRules\Facades\Access;

public function run(): void
{
    // "resource" says which model the rule is about
    Access::newRule('orders.view', 'View orders', resource: 'order');
    Access::newRule('orders.approve', 'Approve orders', resource: 'order');

    Access::for('Role', 'manager')->create('Managers');
    Access::for('Team', 1)->create('North');
    Access::for('Team', 2)->create('South');

    User::find(1)->inheritPermissionFrom('Team', 1);   // Ann is in team North
    User::find(2)->inheritPermissionFrom('Team', 2);   // Bob is in team South
}
```
`Access::for(...)` is new in 3.x: a short way to work with an owner that has no model.

One helper route, **for this tutorial only**, to switch users:
```php
Route::get('/login-as/{id}', fn ($id) => Auth::loginUsingId($id) ? redirect('/orders') : abort(404));
```

# Let's move on to the most interesting part ☕
Every example gives Ann or Bob **one permission** and shows which of the six orders it opens.
To try the next one, take the previous permission away: `$user->remPermission('orders.view');`

## Example 1
The first condition. A value of a column **and** a count of related records:
```php
$ann->addPermission('orders.view', when: 'order.cost > 100 && order.items.count < 3');
```
Check one record, the usual Laravel way:
```php
class OrderController extends Controller
{
    public function show(Order $order)
    {
        Gate::authorize('orders.view', $order);

        return Response::json($order);
    }
}
```
Result: orders **1, 4, 5** open. Order 2 costs enough but has four items. Order 3 is too cheap.

A mistake in a condition does not wait for a user to find it. It stops you **when you save**:
```php
$ann->addPermission('orders.view', when: 'order.itms.count < 3');
// InvalidConditionException: "itms" in "order.itms.count" is not a relation of App\Models\Order
// the same for an unknown model (invoice.cost) and an unknown function (yesterday())
```
A mistyped **column** is the one thing saving lets through, because a model may have it as an accessor. Example 15 catches those.

## Example 2
Now the list. **No new rule, no new query**, the same permission:
```php
public function index()
{
    return Response::json(Order::allowedTo('orders.view')->orderBy('id')->paginate());
}
```
The condition became a part of `WHERE`:
```sql
select * from "orders" where (
  "orders"."cost" > ?
  and (select count(*) from "items" where "orders"."id" = "items"."order_id") < ?
)
```
One query. Pagination, sorting and your own `where()` work as always.

The list and the single check **always agree**. You will never show a row that answers 403 when clicked.

> 📷 **Screenshot 1.** Browser with `/orders` opened as Ann: the JSON list with orders 1, 4 and 5.

## Example 3
Compare a record with **the user**:
```php
$ann->addPermission('orders.view', when: 'order.department_id == user.department_id');
```
Ann (department 1) sees **1, 2, 4**. Give Bob the same line and he sees **3, 5, 6**. One permission, a different result for each user. So it belongs on a **role**:
```php
Access::for('Role', 'manager')->allow('orders.approve',
    when: 'order.cost <= user.approval_limit && order.user_id != user.id');

$ann->inheritPermissionFrom('Role', 'manager');
```
"Approve up to your limit, but never your own order." Ann (limit 500) may approve **3, 4, 5**.

`user.` is any attribute of your User model. Plus `user.id`, `user.tenant` (Example 9) and `user.guest`.

## Example 4
Walk through a relation:
```php
$ann->addPermission('orders.view', when: "order.client.city != 'Paris'");
```
Result: **1, 3, 5**. Order 2 is from Paris. But where are 4 and 6?

Client of order 4 has **no city**. Order 6 has **no client**. "Unknown is not Paris" is not _true_, it is _unknown_, exactly as in SQL. So the permission does not apply.

Say it out loud when you mean such records too:
```php
$ann->addPermission('orders.view', when: "order.client.city == null || order.client.city != 'Paris'");
```
Result: **1, 3, 4, 5, 6**.

## Example 5
Prohibitions, and **who wins**. The priority has five steps, from weak to strong:

1. nothing is said: not permitted
2. inherited permission
3. inherited prohibition
4. own permission
5. own prohibition

Watch it work, line by line:
```php
Access::for('Role', 'manager')->allow('orders.view');                       // Ann sees 1 2 3 4 5 6
Access::for('Role', 'manager')->deny('orders.view', when: 'order.locked');  // Ann sees 1 2 3 5 6
$ann->addPermission('orders.view', when: 'order.cost > 400');               // Ann sees 1 2 3 4 5 6
$ann->addProhibition('orders.view', when: "order.status == 'paid'");        // Ann sees 1 2 4 5 6
```
Look at the third line. The role hides locked orders from all its members. Ann's **own** permission is stronger, so she gets order 4 back. No need to clone the role for one person.

A prohibition **takes a permission away** and vetoes nothing. Your `Gate::before` for a super administrator, your policies and other packages keep their word, as in 2.x.

## Example 6
Sometimes there is no record yet. A menu item, a "New order" button. There are **three ways to ask**:
```php
$user->can('orders.view', $order);         // this very order
$user->can('orders.view', Order::class);   // orders in general: is there any order I may see?
$user->can('orders.view');                 // nothing to look at: only permissions without a condition count
```
With the permission of Example 1, the second line is **true** and the third is **false**.
```blade
@can('orders.view', App\Models\Order::class)
    <a href="/orders">Orders</a>
@endcan
```
`authorizeResource()` passes the class for `index` and `create` by itself.

> 📷 **Screenshot 2.** Two menus side by side: for Ann the item "Orders" is visible, for a user without the permission it is not.

## Example 7
Aggregates over related records: `count`, `sum`, `min`, `max`, `exists`. Each can take a **filter**:
```php
'sum(order.items.price) <= user.approval_limit'     // Ann: 1, 3, 4, 5      Bob: 3, 5
'exists(order.items, price >= 300)'                 // 2, 4, 6
```
Inside a filter, names without a prefix are columns of the related model.

And time:
```php
"order.created_at >= ago('7 days')"                 // 1, 3, 4, 6
```
Also `now()`, `today()`, `after('3 days')`, `monthStart(-1)`, `yearStart()`. A permission that **expires by itself**, without a cron job.

## Example 8
Arithmetic, ranges and text:
```php
'order.cost * 1.2 > order.budget'                   // 2, 6      over budget with 20% on top
'order.cost between 100 and 500'                    // 1, 4, 5   both ends included
"startsWith(order.number, 'EU-')"                   // 1, 2, 4, 6
"lower(order.client.city) == 'berlin'"              // 1, 3, 5
```
Also `endsWith()`, `contains()`, `+ - * /` and brackets. Text functions are **exact**, letter case included, on every database. Use `lower()` on both sides to ignore case.

## Example 9
Teams, or **tenants**. Remember `'tenant_types' => ['Team']` from Step 3 and the two teams from Step 7?
A user that inherits from teams gets their ids as the list `user.tenant`:
```php
Access::for('Role', 'manager')->allow('orders.view', when: 'order.client.team_id in user.tenant');
```
Ann (North) sees **1, 2, 5**. Bob (South) sees **3, 4**. Move a user to another team and the list follows. There is no role per team and nothing to keep in sync.

## Example 10
Trees. "**Electronics and everything under it**":
```php
$ann->addPermission('orders.view', when: "order.category_id in belowOrSelf('category.name', 'Electronics')");
```
Result: **1, 2, 4, 5**. Phones and Laptops came along, Furniture stayed out.

There are four of them: `below`, `belowOrSelf`, `above`, `aboveOrSelf`. The tree is walked **once per request**, and the database receives a plain `category_id in (1, 2, 3)`.

## Example 11
Many-to-many, with columns of the **pivot** table:
```php
'not exists(order.products, restricted)'            // 1, 3, 5, 6   no dangerous goods inside
'exists(order.products, pivot.quantity >= 5)'       // 2, 6         wholesale
'sum(order.products.pivot.quantity) >= 3'           // 1, 2, 6
```
The relation has to load the column, `->withPivot('quantity')`. We did that in Step 5. Forget it, and the condition is refused when you save it, with a hint.

Polymorphic relations (`morphMany`, `morphToMany`, morph map) need nothing special. They work the same way, see Example 18.

## Example 12
The environment, and **guests**:
```php
Access::for('Role', 'manager')->allow('orders.approve',
    when: 'env.weekday in [1, 2, 3, 4, 5] && env.hour between 9 and 18');
```
Also `env.ip`, `env.now`, `env.today`, `env.app`. Such a part is computed **before** the query: on a Saturday the database gets `WHERE 0 = 1` and does not read a single row.

Visitors without a login are checked as one owner, named in config:
```php
'guest' => ['type' => 'Role', 'id' => 'guest'],
```
```php
Access::for('Role', 'guest')->create('Guests');
Access::for('Role', 'guest')->allow('catalog.view');

Gate::forUser(null)->allows('catalog.view');   // true
```

## Example 13
Two things for people who dislike strings. Names of rules as an **enum**:
```php
enum Ability: string
{
    case OrdersView = 'orders.view';
}

$ann->addPermission(Ability::OrdersView, when: 'order.cost > 100');
$ann->can(Ability::OrdersView, $order);
Order::allowedTo(Ability::OrdersView)->get();
```
And conditions **built in code**. The result is the same as from text:
```php
use Wnikk\LaravelAccessRules\Conditions\Cond;

$ann->addPermission('orders.view', when: Cond::all(
    Cond::attr('order.cost')->gt(100),
    Cond::count('order.items')->lt(3),
));
```

## Example 14
The question every access system hears most: "**why can't I see it?**"
Take the four lines of Example 5 and ask:
```bash
php artisan acr:explain "App\Models\User" 1 orders.view order:3
```
```
orders.view for User 1, asked about a record: PROHIBITED
=> prohibit, own: User 1 (Ann), rule orders.view, when order.status == 'paid' -> true
 2 permit, own: User 1 (Ann), rule orders.view, when order.cost > 400 -> not reached
 3 prohibit, inherited: Role manager (Managers), rule orders.view, when order.locked == true -> not reached
 4 permit, inherited: Role manager (Managers), rule orders.view -> not reached
   order.status = "paid"
```
Every permission that takes part, strongest first. The arrow marks the one that **decided**, and you see the value it read.

> 📷 **Screenshot 3.** Terminal with the output of `acr:explain` above.

On production it is even simpler. An administrator looks at the application as the user who complains, and the application turns **debug mode** on for that request:
```php
// a middleware of your application
if (session('impersonated_by') && $request->cookie('access_debug')) {
    Access::debug();
}
```
Now every refusal is explained and every filtered list is written down. The mode only **watches**: it changes no decision and no message.
```php
Access::debugLog();
// 'denials' => every refused check, explained; the text from above is under 'text'
// 'lists'   => every allowedTo(): the conditions it used, and the SQL they became
```
Print the last one on your 403 page:
```blade
@if ($denial = last(Access::debugLog()['denials']))
    <pre>{{ $denial['text'] }}</pre>
@endif
```
Keep it off for ordinary users. An explanation shows rules of other people.

> 📷 **Screenshot 4.** A 403 page in the browser. Left: the plain page of Laravel. Right: the same page with debug mode on, the explanation printed under the message.

## Example 15
Conditions are checked when you save them. Then the application changes. Somebody renames `cost` to `total` in a migration, and stored conditions still say `cost`.
```bash
php artisan acr:lint
```
```
| permission #4 of User 1 for orders.view | "cost" is not a column of table "orders": ... a list cannot filter by it |
1 problem(s) found.
```
It exits with code **1**. Put it into your CI and after `migrate` in the deploy script.

> 📷 **Screenshot 5.** Terminal with `acr:lint` reporting one problem, and the same command green after the fix.

## Example 16
Two small things for real projects.

A seeder that grants hundreds of permissions drops the cache **once**, not hundreds of times:
```php
Access::batch(function () use ($users) {
    foreach ($users as $user) {
        $user->inheritPermissionFrom('Role', 'manager');
    }
});
```
And every change fires **one event**. An audit log is a listener:
```php
use Wnikk\LaravelAccessRules\Events\AccessChanged;

Event::listen(AccessChanged::class, function (AccessChanged $event) {
    Log::channel('audit')->info($event->action, $event->details + ['by' => auth()->id()]);
});
```
`$event->action` is `permission.granted`, `permission.revoked`, `inheritance.added` and so on. `$event->details` says for whom, which rule and with what condition.

By the way, about speed. After the first check of a request, a check runs **no queries** and takes a few microseconds. Version 2 ran a query per check.

## Example 17
This one is for the world outside of Laravel. **XACML 3.0** is the standard language of access policies. Auditors ask for it, other systems speak it.
```bash
php artisan acr:xacml:export storage/app/access.zip
```
Inside are `policy.xml`, a valid XACML policy, and `manifest.json` with what XACML has no place for: titles of rules, names of owners, inheritance.

And back. **Look first**, import second:
```bash
php artisan acr:xacml:import storage/app/access.zip --check
```
```
| Kind       | Action  | What                                 | Document         | Database          |
| permission | differs | App\Models\User:1 may orders.view    | order.cost > 400 | order.cost > 1000 |
rule: 1 same
owner: 2 same
permission: 3 same, 1 differs
inheritance: 1 same
```
Nothing was written. You see what an import **would** create, and what differs from the database. Then:
```bash
php artisan acr:xacml:import storage/app/access.zip            # creates what is missing
php artisan acr:xacml:import storage/app/access.zip --replace  # and brings what differs to the document
```
It also reads policies written by **other systems**. What it cannot convert, it reports with an address in the document and writes nothing, because a lost prohibition means wider access.

The same from a controller, streamed to the browser:
```php
use Wnikk\LaravelAccessRules\Xacml\Xacml;

public function download(Xacml $xacml)
{
    return response()->streamDownload(fn () => $xacml->exportArchive('php://output'), 'access-rules.zip');
}

public function preview(Request $request, Xacml $xacml)
{
    return view('access.import', ['report' => $xacml->check($request->file('policy'))]);
}
```

> 📷 **Screenshot 6.** Terminal with `acr:xacml:import ... --check` and its table of differences.
>
> 📷 **Screenshot 7.** `policy.xml` opened in an editor: the root `PolicySet` and one `Rule` with its `Condition`, so the reader sees it is ordinary XACML.

## Example 18
The last one is about **polymorphic relations**. A tag hangs on a product and on a category alike: `morphToMany`
and a morph map. Example 11 said they need nothing special. Two tables, added for this example only:
```php
Schema::create('tags', function (Blueprint $table) {
    $table->id();
    $table->string('name');
});
Schema::create('taggables', function (Blueprint $table) {   // a tag on a product or on a category
    $table->integer('tag_id');
    $table->morphs('taggable');
});
```
The product gets the relation, with its return type as in Step 5. The morph map goes to `AppServiceProvider::boot()`,
and `Tag` is a plain model listed in `resources` of _config/access.php_ as `'tag' => App\Models\Tag::class`:
```php
class Product extends Model
{
    use HasAccessScope;

    public function tags(): MorphToMany { return $this->morphToMany(Tag::class, 'taggable'); }
}

Relation::enforceMorphMap(['product' => Product::class, 'category' => Category::class]);
```
**Data.** Phone is tagged `sale` and `new`, Charger `sale`, Battery pack `new`. And the category **Laptops**, id 3, is
tagged `sale`: it shares its id with the Battery pack, so a subquery that forgot the morph type would let the Battery pack in.
```php
Access::newRule('products.view', 'View products', resource: 'product');
$ann->addPermission('products.view', when: "exists(product.tags, name == 'sale')");            // Phone, Charger

$ann->addPermission('orders.view', when: "exists(order.products, exists(tags, name == 'sale'))");   // orders 1, 4, 6
```
The second line reads the tags from the other side: orders that hold a product on sale. Order 2 holds only the Battery pack.
```sql
select * from "products" where exists (
  select 1 from "tags" inner join "taggables" on "tags"."id" = "taggables"."tag_id"
  where "products"."id" = "taggables"."taggable_id" and "taggables"."taggable_type" = ? and "tags"."name" = ?)
-- bindings: ["product", "sale"]
```
The package writes no joins of its own. For every relation in a condition it asks Eloquent for the subquery that
`whereHas()` builds, so the pivot table, the morph type and the morph map come along. `morphMany` works the same way.
The one relation the package refuses is `morphTo`: its far end is a different model for every row, so no single
subquery describes it. Ask from the other side, `exists(product.tags, ...)` instead of `tag.taggable`.

> 📷 **Screenshot 8.** `/products` as Ann: the JSON list with the Phone and the Charger, and the Battery pack absent.

## Coming from 2.x?
Nothing has to be converted. Tables, names of rules, options, `.self`, the trait and the console commands stay compatible.
```bash
composer require wnikk/laravel-access-rules:^3.0
php artisan vendor:publish --tag=access-migrations-upgrade
php artisan migrate
```
The migration adds a few columns and renames rules that 2.x had soft deleted. One thing to check by hand: `delRule()` in `down()` of your migrations now needs `true` to delete a rule that is still granted. The details are in the [upgrade guide](upgrade-2-to-3.md).

## Final words
We started with roles in part one. Now a permission can look at a **column**, at the **user**, at a **related record**, at a **sum over a relation**, at a **tree**, at the **clock**, through a **polymorphic** relation. And every one of them filters a **list** with the same line of text.

Where to go next:

- [Conditions](conditions.md): the whole language on one page
- [Basic usage](basic-usage.md): rules, roles, options, cache, console
- [XACML](xacml.md): what is exported and how foreign policies convert

---

### Screenshots to add

| # | Where | What to capture |
|---|---|---|
| cover | top | Banner "ABAC in Laravel: permissions that look at your data" |
| 1 | Example 2 | `/orders` as Ann, JSON with orders 1, 4, 5 |
| 2 | Example 6 | Menu with the item "Orders" for Ann, and without it for a user that has no permission |
| 3 | Example 14 | Terminal, `acr:explain` with the arrow on the deciding line |
| 4 | Example 14 | 403 page, plain next to the same page with debug mode on and the explanation printed |
| 5 | Example 15 | Terminal, `acr:lint` with one problem and green after the fix |
| 6 | Example 17 | Terminal, `acr:xacml:import --check` with the table of differences |
| 7 | Example 17 | `policy.xml` in an editor, root `PolicySet` and one `Rule` with a `Condition` |
| 8 | Example 18 | `/products` as Ann: the Phone and the Charger, the Battery pack absent |

---
title: Conditions (ABAC)
weight: 3
---

# Conditions

A permission can have a condition. Then it works only for records the condition is true for:

```php
AccessRules::newRule('orders.view', 'View orders', resource: 'order');

$manager->addPermission('orders.view', when: 'order.cost > 100 && order.items.count < 3');   // model with the trait HasPermissions
$user->addProhibition('clients.view', when: "client.city == 'Y'");
```

The same condition is used in two ways, and both always give the same answer:

```php
$user->can('orders.view', $order);                  // a loaded record is checked in memory, without queries

Order::query()->allowedTo('orders.view')->paginate(); // a list gets the condition as a part of its WHERE, still one query
```

`allowedTo()` comes from the trait `Wnikk\LaravelAccessRules\Traits\HasAccessScope` added to the model of the record.
Its second argument is the user; by default it is the current one (or the guest).

Owners without a model (roles, groups) are managed through the manager, it keeps nothing between calls:

```php
use Wnikk\LaravelAccessRules\Facades\Access;   // or inject Wnikk\LaravelAccessRules\Contracts\AccessManager

Access::for('Role', 'manager')->allow('orders.view', when: 'order.cost > 100');
Access::for('Role', 'manager')->can('orders.view', $order);
```

## Setup

List models conditions may work with in `config/access.php`. The key is the name the model has in conditions:

```php
'resources' => [
    'order'  => App\Models\Order::class,
    'client' => App\Models\Client::class,
    'item'   => App\Models\Item::class,
],
```

A condition can walk through relations only to models that are listed too. A relation is recognised
by the declared return type of its method (`public function client(): BelongsTo`); a method without
a return type has to be listed: `'order' => ['model' => Order::class, 'relations' => ['client']]`.
No other method of a model can ever be called by a condition.

## Language

| | |
|---|---|
| the record | `order.cost`, `resource.cost` |
| related record (belongsTo, hasOne, morphOne, also of the same table) | `order.client.city`, `order.client.parent.city` |
| the user | `user.id`, `user.department_id`, `user.tenant`, `user.roles`, `user.guest` |
| environment | `env.now`, `env.today`, `env.time`, `env.hour`, `env.weekday` (1 - Monday), `env.ip`, `env.app` |
| comparison | `==  !=  >  >=  <  <=`, `in [..]`, `not in [..]`, `x in user.tenant` |
| logic | `&&` `and`, `\|\|` `or`, `!` `not`, brackets |
| values | `100`, `1.5`, `'text'`, `true`, `false`, `null`, `['a', 'b']` |
| time | `now()`, `today()`, `ago('24 hours')`, `after('7 days')`, `monthStart(-1)`, `yearStart()` |
| trees | `below()`, `belowOrSelf()`, `above()`, `aboveOrSelf()`, see below |
| author of the record | `isAuthor()` |

Aggregates over related records (hasMany, belongsToMany, morphMany, hasManyThrough, ...), with an optional
filter. Inside a filter names without a root are columns of the related model:

```
order.items.count < 3                        count(order.items) < 3
exists(order.products, restricted)           not exists(client.orders)
sum(client.payments.amount, paid_at >= monthStart(-1) && paid_at < monthStart(0)) > 1000
max(client.payments.amount) >= 2000          exists(order.comments, author_id == user.id)
```

A filter can hold an aggregate of its own, so a chain of to-many relations is written from the inside out.
Polymorphic relations need nothing special, the morph map included:

```php
// products tagged "sale" that have a comment with more than 10 likes
// Product::comments() is morphMany, Comment::likes() is morphMany, Product::tags() is morphToMany
"exists(product.tags, name == 'sale') && exists(product.comments, likes.count > 10)"
```

A relation keeps conditions of its own, `morphMany(...)->where('hidden', false)`, in lists and in checks alike.
For "likes of all comments together" give the model a relation that reaches them, for example `hasManyThrough`,
and count it: `count(product.commentLikes) > 10`. Every model on the way has to be listed in `resources`.

Instead of text a condition can be built in code, the result is the same:

```php
use Wnikk\LaravelAccessRules\Conditions\Cond;

$role->addPermission('orders.view', when: Cond::all(
    Cond::attr('order.cost')->gt(100),
    Cond::count('order.items')->lt(3),
));
```

A condition is checked when it is saved: a mistake in a name, an unknown function, a model that is not listed,
a wrong use of a relation throw `InvalidConditionException` right away, not later when permissions are checked.
What is stored is a tree, its text for an editor is given by `ConditionCompiler::describe()`.

## Trees: "this category and everything under it"

For a model that points at itself through `parent_id`, four functions give ids of a part of the tree:

| | |
|---|---|
| `below('tag.name', 'sale')` | every tag under "sale", at any depth |
| `belowOrSelf('tag.name', 'sale')` | "sale" and every tag under it |
| `above('tag.id', user.tag_id)` | every tag above the tag of the user |
| `aboveOrSelf('tag.id', user.tag_id)` | that tag and every tag above it |

```php
"exists(product.tags, id in belowOrSelf('tag.name', 'sale'))"
"product.category_id in belowOrSelf('category.slug', 'electronics')"
```

The first argument names the model by its alias from `resources` and the column that identifies the node;
the second is the value, a literal or an attribute of the user. The tree is found through the relation of the
model to itself, `parent()` by default; pass another name as the third argument.

The language itself has no recursion on purpose: every construct has to become one SQL fragment. A function
that does not depend on the record is computed before the query, so the database gets a plain `id in (2, 3, 4)`.
The walk is one `WITH RECURSIVE` query where the server has it, and it runs once per request however many
records are checked.

An application that uses [staudenmeir/laravel-adjacency-list](https://github.com/staudenmeir/laravel-adjacency-list) can use
its relations as they are, `exists(product.tags, exists(ancestors, name == 'sale'))`. Their methods declare no return types,
so list them: `'tag' => ['model' => Tag::class, 'relations' => ['ancestors', 'descendants']]`. The tree is then walked by
the database for every row, and cycles in data need `enableCycleDetection()` of that package; `below()` walks it once and is safe with cycles.

Without the functions a tree can be handled by a fixed depth, `parent.name == 'sale' || parent.parent.name == 'sale'`,
or by a closure table and a nested `exists(product.tags, exists(ancestors, name == 'sale'))`.

## NULL works as in SQL

A comparison with NULL (an empty column, a missing related record) is *unknown*, and a permission
with an unknown condition is not applicable. So `client.city != 'Y'` is not true for a client without a city -
exactly as it would be in a query. Say it explicitly when such records are meant:

```
client.city == null || client.city != 'Y'
```

## A record, a class, or nothing

```php
$user->can('orders.view', $order);         // this very order: conditions are checked against it
$user->can('orders.view', Order::class);   // orders in general (menu, list page): is there any order the user may see?
$user->can('orders.view');                 // nothing to look at: only permissions that do not depend on a record count
```

- With a **class** instead of a record a permit with any condition about the record is enough, a plain permit counts as well.
  A prohibition without a condition still denies; a prohibition with a condition cannot hide all records, so it is skipped.
  `authorizeResource()` passes a class for `viewAny` and `create` by itself.
- With **nothing** the check works as it did in 2.x: a plain permission `orders.view` allows, a permission that depends
  on a record has nothing to be checked against and does not count. The same goes for the suffix `.self`.
- Conditions that are only about the user or the environment (`user.level >= 3`, `env.weekday in [1,2,3,4,5]`)
  need no record and are checked in all three cases.

## Priority of permissions

From the weakest to the strongest, the same with and without conditions:

1. nothing is said - not permitted
2. inherited permission
3. inherited prohibition
4. own permission
5. own prohibition

So "may see clients with turnover above 1000, but not from city Y" is simply a permission and a prohibition of the same owner:

```php
$user->addPermission('clients.view', when: 'sum(client.payments.amount, paid_at >= monthStart(-1)) > 1000');
$user->addProhibition('clients.view', when: "client.city == 'Y'");
```

A permission whose condition is not true (or unknown) is not applicable, the next one by priority is looked at.

## Condition of a rule

```php
AccessRules::newRule('orders.export', 'Export', resource: 'order', when: 'not order.locked');
```

Such a condition is valid for everybody who has the rule, together with conditions of their own permissions.

## Own attributes and functions

```php
// config/access.php
'attributes' => ['env.region' => App\Access\RegionAttribute::class],   // __invoke(Context $context)
'functions'  => ['limitFor' => App\Access\LimitFunction::class],       // __invoke(array $args, Context $context)
```
Use invokable classes, not closures: `php artisan config:cache` cannot store a closure.
```
order.cost <= limitFor(env.region)
```

A function applied to columns of the record (`half(order.cost) > 100`) works for a loaded record,
but cannot become SQL: `allowedTo()` throws `UntranslatableConditionException`.

## Why is this the answer, and is everything still valid

```bash
php artisan acr:explain "App\Models\User" 7 orders.view order:4
```
```
orders.view for User 7, asked about a record: PROHIBITED
=> prohibit, own: User 7 (Ann), rule orders.view, when order.locked == true -> true
 2 permit, inherited: Role manager (Managers), rule orders.view, when order.cost > 100 && count(order.items) < 3 -> not reached
   order.locked = 1
```

The record is `alias:id`, only the alias asks about records in general, nothing asks without a record.
Every permission that takes part is listed, strongest first, with the owner it comes from, and the one that
decided is marked. Values are shown for conditions that ran. The same is available in code,
`$user->access()->explain('orders.view', $order)`, as an array.

The explanation runs the very loop that makes real decisions, so it cannot disagree with a check. It reads
permissions from the database and compares the answer with the cached one: a difference means that something
changed permissions past the package, and the command says so.

### Debug mode: the same explanation on every refusal

Most questions come from production data: a user complains that a page is forbidden or a list is shorter than
it should be. An administrator signs in as that user, ticks "debug", and the application turns the mode on
for the request:

```php
// A middleware of the application. Who may tick the box is its decision, not of the package.
if ($request->session()->get('impersonated_by') && $request->cookie('access_debug')) {
    Access::debug();
}
```

From then on every refusal carries the explanation above in the message of the 403, after the usual
`Action "orders.view" is unauthorized.`, whether it came from `$user->can()`, `@can`, the `can:` middleware
or `authorizeResource()`. Everything is also kept for the request:

```php
Access::debugLog();
// 'denials' => every refused check, as explain() reports it; direct hasPermission() calls included
// 'lists'   => every allowedTo(): ability, model, owner, the permissions used with their conditions as text,
//              outcome "filtered" | "everything" | "nothing", and the SQL with bindings that narrowed the query
```

A short list is read from `lists`: the conditions say what a record must look like. For one missing record ask
`$user->access()->explain('orders.view', $order)`. A debug bar or a block at the bottom of the layout is
a few lines over `debugLog()`.

`'debug' => true` in config (`ACCESS_RULES_DEBUG`) turns the mode on for everybody, which suits a local
environment only: an explanation shows rules of other owners, their conditions and values of attributes.
A permitted check costs the same with the mode on; an explained refusal costs several queries. A policy of
Laravel that answers `false` keeps its own message, the package explains only what it decided or kept silent about.

`AccessRules::getLastDisallowPermission()` of version 2 still names the last refused ability, in any mode.

```bash
php artisan acr:lint
```

Conditions are checked when they are saved, and then the application changes under them: a migration renames
a column, a relation is removed, a model leaves `resources`. `acr:lint` checks every stored condition, rule and
owner against models and config as they are now, and exits with 1 when it finds something. Run it in CI and
after migrations.

## Limits

- `morphTo` cannot be used in a path: it leads to models of different types.
- A path cannot continue after a to-many relation, use an aggregate with a filter.
- Laravel policies and `Gate::define()` are code, `allowedTo()` takes into account only permissions of this package.

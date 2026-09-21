
![Laravel Access Control Rules](https://raw.githubusercontent.com/wnikk/laravel-access-rules/main/docs/art/laravel-access-control-rules-logo.png)

# Access Control Rules: RBAC and ABAC for Laravel

[![tests](https://github.com/wnikk/laravel-access-rules/actions/workflows/tests.yml/badge.svg)](https://github.com/wnikk/laravel-access-rules/actions/workflows/tests.yml) [![Latest Stable Version](https://poser.pugx.org/wnikk/laravel-access-rules/v)](//packagist.org/packages/wnikk/laravel-access-rules) [![PHP Version Require](http://poser.pugx.org/wnikk/laravel-access-rules/require/php)](//packagist.org/packages/wnikk/laravel-access-rules) [![Total Downloads](http://poser.pugx.org/wnikk/laravel-access-rules/downloads)](//packagist.org/packages/wnikk/laravel-access-rules) [![License](https://poser.pugx.org/wnikk/laravel-access-rules/license)](//packagist.org/packages/wnikk/laravel-access-rules)

Roles, groups and inheritance (RBAC), and since version 3 permissions that depend on data (ABAC),
through the standard Laravel Gate.

```php
$user->addPermission('orders.view');                                                      // RBAC: may or may not
$user->addPermission('orders.export', when: 'order.cost > 100 && order.items.count < 3'); // ABAC: depends on the record
```

A check costs **4 µs and no queries** after the first one of a request, and a list is filtered by the database in the
same query that loads it. Speed and flexibility have been the design goals since version 1.

**Background.** The model behind this package, a separate *owner* with dynamic binding, unlimited inheritance,
hybrid rules and options, has been in production since 2013: first on Zend Framework, then on Yii2, and since 2023
as this Laravel package. The Laravel version was written against the grain of heavyweight access-control frameworks:
as few classes as possible, leaning on what Laravel already gives, to get the most RBAC, and now ABAC, for the least
code on the path of a check.

## Contents

| If you want to | read |
|---|---|
| see what it looks like | [What it does](#what-it-does), [What ABAC can do](#what-abac-can-do) |
| decide between packages | [When to choose it, and when not](#when-to-choose-it-and-when-not), [Alternatives](#alternatives), [measured numbers](docs/performance.md) |
| install and start | [Installation](#installation), [Basic usage](docs/basic-usage.md), [Conditions (ABAC)](docs/conditions.md) |
| learn by example | [ABAC step by step](docs/tutorial-abac-step-by-step.md), [RBAC step by step](docs/tutorial-basic-step-by-step.md) |
| come from version 2 | [Upgrade from 2.x to 3.x](docs/upgrade-2-to-3.md): tables and the API stay compatible |
| exchange policies | [XACML 3.0](docs/xacml.md) |
| work with an AI coding agent | [For AI coding agents](#for-ai-coding-agents), [docs/llms.txt](docs/llms.txt) |

## What does Access Control Rules support?

### New in version 3

- **Conditions (ABAC)**: a permission or a prohibition can depend on the record, its relations of any kind
  and their aggregates, on the user and on the environment.
- **Filtering of lists** by the same conditions, in the same query: `Order::query()->allowedTo('orders.view')`.
  A list and a detail page cannot disagree, both read one condition.
- **A language for conditions**: arithmetic, `between`, exact text functions, aggregates with filters over any relation,
  columns of pivot tables, time functions, and trees: "this category and everything under it".
- **Safe to edit in an admin panel**: a condition is checked when it is saved, reads only models listed
  in config and never calls a method of a model that is not a relation.
- **No queries after the first check of a request**: 4 µs per check against 62 µs and a query in version 2;
  permissions compile in 3 queries at any depth of inheritance. See [Performance](docs/performance.md).
- **`acr:explain`** tells why a check answers what it answers, **`acr:lint`** finds stored conditions
  that a migration or a refactoring has broken.
- **Debug mode** for support sessions: `Access::debug()` explains every refusal and records what narrowed every
  `allowedTo()` list. It only observes and changes no decision.
- **XACML 3.0**: export of permissions as a standard policy, import of policies with a plan of what would change.
  No XACML engine on the path of a check.
- Guests, tenants, abilities as enums, the `Access` facade, rules of code and rules of an admin panel,
  event `AccessChanged` for an audit log.
- Guidelines and a skill for AI coding agents through Laravel Boost.

### Since version 2

- Multiple user models, roles, groups and any other owners of permissions, also without a model.
- Permissions and prohibitions, attached to users, groups or roles.
- Permissions can be inherited with unlimited nesting from users, groups and roles.
- Dynamic options (`news.edit.2`) and the magic suffix `.self` for authors of records.
- Laravel gates and policies: `$user->can()`, `@can`, `can:` middleware, `authorizeResource()`.
  The package answers Gate with "yes" or nothing, so policies, a super administrator of the application
  and other packages keep working next to it.
- Permissions caching, with a fallback to the database when the cache store is down.

## What ABAC can do

An example of what the requirements might be:
> *Show the user all products tagged "sale" that have a comment with more than 10 likes.*

Every link is polymorphic: likes belong to comments and comments to products through `morphMany`,
tags reach products through `morphToMany`.

Setting this permission:
```php
$user->addPermission('products.view',
    when: "exists(product.tags, name == 'sale') && exists(product.comments, likes.count > 10)");
```
The check in the controller:
```php
$user->can('products.view', $product);                      // one loaded product, checked in memory
# -- or --
Product::query()->allowedTo('products.view')->paginate();   // the list, filtered by the database:
```
The SQL query generated by the model, taking into account controller parameters and access rights:
```sql
select * from "products" where (
  exists (select 1 from "tags" inner join "taggables" on "tags"."id" = "taggables"."tag_id"
          where "products"."id" = "taggables"."taggable_id" and "taggables"."taggable_type" = ? and "tags"."name" = ?)
  and exists (select 1 from "comments"
          where "products"."id" = "comments"."commentable_id" and "comments"."commentable_type" = ?
            and (select count(*) from "likes"
                 where "comments"."id" = "likes"."likeable_id" and "likes"."likeable_type" = ?) > ?))
-- bindings: ["product", "sale", "product", "comment", 10]
```
The configuration itself that makes such rules possible:
```php
// config/access.php: every model a condition may read
'resources' => ['product' => Product::class, 'comment' => Comment::class, 'like' => Like::class, 'tag' => Tag::class],
```
```php
// Migration or seeder: create the permission with a resource
Access::newRule('products.view', 'View products', resource: 'product');
```

Any tag under "sale" instead of "sale" itself: `exists(product.tags, id in below('tag.name', 'sale'))`.
The language, relations, aggregates and trees are described in [Conditions](docs/conditions.md).

## Visual Interface
The visual interface for managing access control rules and permissions can be found at [this link](https://github.com/wnikk/laravel-access-ui).
It offers an intuitive and user-friendly environment for administrators to define roles, assign permissions, and configure access rules.

For detailed usage examples and instructions, refer to the [example repository](https://github.com/wnikk/-laravel-access-example).

## Documentation

[Installation](docs/installation.md) · [Basic usage](docs/basic-usage.md) · [Conditions (ABAC)](docs/conditions.md) ·
[Performance](docs/performance.md) · [XACML](docs/xacml.md) · [Upgrade from 2.x to 3.x](docs/upgrade-2-to-3.md)

Tutorials: [RBAC step by step](docs/tutorial-basic-step-by-step.md), [ABAC step by step](docs/tutorial-abac-step-by-step.md).

## Versions & Dependencies

| Laravel Access Control Rules | PHP       | Laravel | Model                 |
|------------------------------|-----------|---------|-----------------------|
| 3.x                          | 8.4+      | 13+     | RBAC + ABAC           |
| 2.x                          | 7.4 - 8.4 | 8 - 13  | RBAC + Dynamic option |
| 1.x                          | 7.1 - 7.3 | 5.5 - 8 | RBAC                  |

## Installation

```bash
composer require wnikk/laravel-access-rules
```

For Laravel 12 and older stay on 2.x: `composer require wnikk/laravel-access-rules:^2.4`.

See the [installation page](https://github.com/wnikk/laravel-access-rules/blob/main/docs/installation.md) for detailed.

## What It Does
This package allows you to manage user permissions and groups (instead roles) in a database.

Once installed you can do stuff like this:

```php
use Wnikk\LaravelAccessRules\Facades\Access;

// Add new rule permission
Access::newRule('articles.edit', 'Access to editing articles');
```
```php
// Adding permissions to a user
$user->addPermission('articles.edit');
```


Or you can inherit the rights from another user or groups

```php
// According to the existing user from object
$user->inheritPermissionFrom(User::find(1));

// By identifier
$user->inheritPermissionFrom(User::class, 1);

// From the group
$user->inheritPermissionFrom('Group', 1);
```


Because all permissions will be registered on **Laravel's gate**, you can check if a user has a permission with Laravel's default `can` function:

```php
$user->can('articles.edit'); 
```

Or without model:

```php
$check = Access::for('AnotherAnySystemUser', 'UserID-From-Any-System-FF01')->can('articles.edit');
if (!$check) {abort(403);}
```

Examples of how can be used in more detail described in [Basic Usage](https://github.com/wnikk/laravel-access-rules/blob/main/docs/basic-usage.md) section.

## When to choose it, and when not

Choose it when:

- access **depends on data**: "orders of my department", "up to my approval limit, but not my own", "clients with
  a turnover above 1000, except city Y", and the same rule has to **filter lists**;
- roles **inherit from roles** to any depth, or users inherit from users and groups;
- you need **prohibitions** on top of roles, and one user has to get back what a role took away;
- owners of permissions are **not only users**: roles, groups, teams, API clients, records of another system;
- every request makes **many checks**: menus, tables with buttons per row, API resources;
- an administrator edits access **at run time** and must not be able to break the application with a typo;
- somebody will ask **"why can't I see it?"** and the answer has to be found in a minute;
- policies have to be handed to an auditor or another system as **XACML**.

Look elsewhere when:

- access is a short, fixed list of role names checked in code, and nothing depends on data. A plain role package
  or Laravel policies alone are less to learn;
- you are on Laravel 12 or older and need ABAC. Version 3 needs Laravel 13 and PHP 8.4; version 2 is RBAC only.

## Alternatives

| | this package | spatie | bouncer |
|---|---|---|---|
| Roles and direct permissions | yes | yes | yes |
| Roles inherit from roles | yes, any depth | no, roles are flat | no |
| Prohibitions | yes, with a five-step priority | no | yes (`forbid`) |
| Owners without a model | yes | no | no |
| Permission depends on data of the record | **yes**: columns, relations, aggregates, the user, time | no, write a policy | ownership and single instances |
| Lists filtered by the same rules, in SQL | **yes**, `allowedTo()` | no | no |
| "Why was it refused?" | `acr:explain`, debug mode | no | no |
| Stored rules checked against code | `acr:lint` | no | no |
| XACML export and import | yes | no | no |
| Teams / tenants | yes, through inheritance and `user.tenant` | yes, teams | yes, scopes |

Measured on one machine with the same data (MySQL, 2 000 abilities, the user holds 500 of them, one request of a signed-in user):

| | this package | spatie | bouncer |
|---|---|---|---|
| first check of a request | **0.2 ms, 0 queries, 51 KB** | 5.0 ms, 2 queries, 3.9 MB | 4.2 ms, 0 queries |
| next check, permitted | **4.0 µs** | 28.9 µs | 4 169 µs |
| next check, not permitted | **4.1 µs** | 1 461 µs | 4 198 µs |
| a page with 50 checks | **0.2 ms** | 2.5 ms | 207 ms |

Versions, the method, a smaller data set and what these numbers do not say are in [Performance](docs/performance.md).

If you also need authentication, API tokens or social login, those are other packages: this one decides
what a user may do, not who the user is.

## For AI coding agents

The package ships [Laravel Boost](https://laravel.com/docs/boost) guidelines and a skill in `resources/boost/`;
`php artisan boost:install` adds them to `CLAUDE.md` / `AGENTS.md` of your application. [docs/llms.txt](docs/llms.txt)
is a map of the documentation, and [AGENTS.md](AGENTS.md) is for agents that work on the package itself.
The short version: check through Gate, express data-dependent access as a condition and not as a policy plus
a hand-written query, filter lists with `allowedTo()`, create rules in migrations.

## Opening an Issue

Before opening an issue there are a couple of considerations:
* You are all awesome!
* Pull requests are more than welcome.
* **Read the instructions** and make sure all steps were *followed correctly*.
* **Check** that the issue is not *specific to your development environment* setup.
* **Provide** *duplication steps*.
* **Attempt to look into the issue**, and if you *have a solution, make a pull request*.
* **Show that you have made an attempt** to *look into the issue*.
* **Check** to see if the issue you are *reporting is a duplicate* of a previous reported issue.
* **Following these instructions show me that you have tried.**
* Please be considerate that this is an open source project that I provide to the community for FREE when opening an issue.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

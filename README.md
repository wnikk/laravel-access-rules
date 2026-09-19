
![Laravel Access Control Rules](https://raw.githubusercontent.com/wnikk/laravel-access-rules/main/docs/art/laravel-access-control-rules-logo.png)

# Access Control Rules: RBAC and ABAC for Laravel

[![License](https://poser.pugx.org/wnikk/laravel-access-rules/license)](//packagist.org/packages/wnikk/laravel-access-rules)
[![Code Climate](https://codeclimate.com/github/wnikk/laravel-access-rules/badges/gpa.svg)](//codeclimate.com/github/wnikk/laravel-access-rules)
[![PHP Version Require](http://poser.pugx.org/wnikk/laravel-access-rules/require/php)](//packagist.org/packages/wnikk/laravel-access-rules)
[![Total Downloads](http://poser.pugx.org/wnikk/laravel-access-rules/downloads)](//packagist.org/packages/wnikk/laravel-access-rules)
[![Latest Stable Version](https://poser.pugx.org/wnikk/laravel-access-rules/v)](//packagist.org/packages/wnikk/laravel-access-rules)
[![Latest Unstable Version](http://poser.pugx.org/wnikk/laravel-access-rules/v/unstable)](//packagist.org/packages/wnikk/laravel-access-rules)

Roles, groups and inheritance (RBAC), and since version 3 permissions that depend on data (ABAC),
through the standard Laravel Gate.

```php
$user->addPermission('orders.view');                                                      // RBAC: may or may not
$user->addPermission('orders.export', when: 'order.cost > 100 && order.items.count < 3'); // ABAC: depends on the record
```

## What does Access Control Rules support?

- `[3.x]` **Conditions (ABAC)**: a permission or a prohibition can depend on the record, its relations of any kind
  and their aggregates, on the user and on the environment.
- `[3.x]` **Filtering of lists** by the same conditions, in the same query: `Order::query()->allowedTo('orders.view')`.
  A list and a detail page cannot disagree, both read one condition.
- `[3.x]` **Trees**: "this category and everything under it", `belowOrSelf('category.slug', 'electronics')`.
- `[3.x]` **Safe to edit in an admin panel**: a condition is checked when it is saved, reads only models listed
  in config and never calls a method of a model that is not a relation.
- `[3.x]` **No queries after the first check of a request**: 4 µs per check against 62 µs and a query in version 2;
  permissions compile in 3 queries at any depth of inheritance.
- `[3.x]` **`acr:explain`** tells why a check answers what it answers, **`acr:lint`** finds stored conditions
  that a migration or a refactoring has broken.
- `[3.x]` **Debug mode** for support sessions: `Access::debug()` makes every 403 say which permission refused and why,
  and records what narrowed every `allowedTo()` list
- `[3.x]` Guests, tenants, abilities as enums, the `Access` facade, event `AccessChanged` for an audit log.

- Multiple user models, roles, groups and any other owners of permissions, also without a model.
- Permissions and prohibitions, attached to users, groups or roles.
- Permissions can be inherited with unlimited nesting from users, groups and roles.
- Dynamic options (`news.edit.2`) and the magic suffix `.self` for authors of records.
- Laravel gates and policies: `$user->can()`, `@can`, `can:` middleware, `authorizeResource()`.
- Permissions caching.

### `[3.x]` What ABAC can do

*Show the user all products tagged "sale" that have a comment with more than 10 likes.*
Every link is polymorphic: likes belong to comments and comments to products through `morphMany`,
tags reach products through `morphToMany`.

```php
// config/access.php: every model a condition may read
'resources' => ['product' => Product::class, 'comment' => Comment::class, 'like' => Like::class, 'tag' => Tag::class],
```
```php
AccessRules::newRule('products.view', 'View products', resource: 'product');

$user->addPermission('products.view',
    when: "exists(product.tags, name == 'sale') && exists(product.comments, likes.count > 10)");
```
```php
$user->can('products.view', $product);                      // one loaded product, checked in memory
Product::query()->allowedTo('products.view')->paginate();   // the list, filtered by the database:
```
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

Any tag under "sale" instead of "sale" itself: `exists(product.tags, id in below('tag.name', 'sale'))`.
The language, relations, aggregates and trees are described in [Conditions](docs/conditions.md).

## Visual Interface
The visual interface for managing access control rules and permissions can be found at [this link](https://github.com/wnikk/laravel-access-ui).
It offers an intuitive and user-friendly environment for administrators to define roles, assign permissions, and configure access rules.

For detailed usage examples and instructions, refer to the [example repository](https://github.com/wnikk/-laravel-access-example).

## Documentation, Installation, and Usage Instructions

See the [documentation](https://github.com/wnikk/laravel-access-rules/tree/main/docs) for detailed installation and usage instructions:
[installation](docs/installation.md), [basic usage](docs/basic-usage.md), `[3.x]` [conditions](docs/conditions.md),
`[3.x]` [upgrade from 2.x](docs/upgrade-2-to-3.md), [tutorial step by step](docs/tutorial-basic-step-by-step.md).

## Versions & Dependencies

| Laravel Access Control Rules | PHP       | Laravel | Model                 |
|------------------------------|-----------|---------|-----------------------|
| 3.x                          | 8.4+      | 13+     | RBAC + ABAC           |
| 2.x                          | 7.4 - 8.4 | 8 - 13  | RBAC + Dynamic option |
| 1.x                          | 7.1 - 7.3 | 5.5 - 8 | RBAC                  |

## You can install the package using composer:

```bash
composer require wnikk/laravel-access-rules
```

For Laravel 12 and older stay on 2.x: `composer require wnikk/laravel-access-rules:^2.4`.

See the [installation page](https://github.com/wnikk/laravel-access-rules/blob/main/docs/installation.md) for detailed.

## What It Does
This package allows you to manage user permissions and groups (instead roles) in a database.

Once installed you can do stuff like this:

```php
use Wnikk\LaravelAccessRules\AccessRules;

// Add new rule permission
AccessRules::newRule('articles.edit', 'Access to editing articles');
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
$acr = new AccessRules;
$acr->setOwner('AnotherAnySystemUser', 'UserID-From-Any-System-FF01');
$check = $acr->can('articles.edit');
if (!$check) {abort(403);}
```

Examples of how can be used in more detail described in [Basic Usage](https://github.com/wnikk/laravel-access-rules/blob/main/docs/basic-usage.md) section.

## Upgrading from 2.x to 3.x

Data, tables, config keys, names of rules, options, the suffix `.self`, artisan commands and methods of the
`HasPermissions` trait stay compatible. Nothing has to be converted.

1. PHP 8.4+ and Laravel 13+ are required.
2. Add the new columns, three nullable ones, existing rows stay valid:
   ```bash
   php artisan vendor:publish --tag=access-migrations-upgrade
   php artisan migrate
   ```
3. Clear cached permissions, their format has changed:
   ```bash
   php artisan acr:cache:clear
   ```
4. Compare your `config/access.php` with the new one. New keys have defaults, the file may stay as it is
   until you need conditions (`resources`), guests (`guest`) or tenants (`tenant_types`).
5. Optional: unique indexes and a foreign key that tables of version 2 did not have.
   ```bash
   php artisan vendor:publish --tag=access-migrations-constraints
   ```

Two changes of behaviour to check in your project: an own prohibition now beats an own permission of the same
owner, and a prohibition is final for Laravel policies (`'deny_is_final' => false` brings the old behaviour back).
The full list of changes and removed methods is in the [upgrade guide](docs/upgrade-2-to-3.md).

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

# Introduction

Laravel Access Rules handles roles and permissions inside your application (RBAC) and, since version 3,
permissions that depend on data (ABAC): `order.cost > 100 && order.items.count < 3`.
Everything goes through the standard Laravel Gate.

- [Installation](installation.md)
- [Basic usage](basic-usage.md): rules, permissions and prohibitions, roles and inheritance, options, the suffix `.self`, cache, console
- [Conditions (ABAC)](conditions.md), new in 3.x: permissions with conditions, filtering of lists, trees, `acr:explain`, debug mode, `acr:lint`
- [Upgrade from 2.x to 3.x](upgrade-2-to-3.md)
- [Tutorial step by step](tutorial-basic-step-by-step.md): an application with seven ways to check access, written for 2.x

## In short

```php
use Wnikk\LaravelAccessRules\AccessRules;
use Wnikk\LaravelAccessRules\Facades\Access;

// a rule, a role that holds it, a user that inherits from the role
AccessRules::newRule('news.edit', 'Edit news');
AccessRules::newRule('news.publish', 'Publish news');
AccessRules::newRule('news.delete', 'Delete news');
Access::for('Role', 'editor')->create('Editors');
Access::for('Role', 'editor')->allow('news.edit');
$user->inheritPermissionFrom('Role', 'editor');

// checks are the ones of Laravel
$user->can('news.edit');
Gate::authorize('news.edit', $news);
// Route::...->middleware('can:news.edit'), @can('news.edit'), authorizeResource()

// a permission of the user itself, and a prohibition that is stronger than anything inherited
$user->addPermission('news.publish');
$user->addProhibition('news.delete');
```

Since 3.x a permission can carry a condition, and the same condition filters a list:

```php
Access::for('Role', 'manager')->allow('orders.view', when: 'order.cost > 100 && order.items.count < 3');

$user->can('orders.view', $order);
Order::allowedTo('orders.view')->paginate();
```

When access is refused and it is not clear why:

```bash
php artisan acr:explain "App\Models\User" 7 orders.view order:4
```

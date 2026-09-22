---
name: access-rules-development
description: Build authorization with wnikk/laravel-access-rules - roles and inheritance (RBAC), permissions with conditions on model data, relations and aggregates (ABAC), lists filtered by the same conditions, tenants, tree functions, explain/debug/lint tooling and XACML export/import.
---

# Laravel Access Rules Development

## When to use this skill

Use it when the task involves who may do what in an application that has `wnikk/laravel-access-rules` installed: creating rules, granting or prohibiting, roles and inheritance, "only own / only my department / only cheap" kinds of access, filtering a list by what the user may see, finding out why access was refused, multi-tenant access, or exchanging policies as XACML.

Version 3 needs PHP 8.4 and Laravel 13. Everything below is version 3.

## Mental model

- A **rule** is a name of something that can be permitted: `orders.view`. It lives in the database and is created by code (migration, seeder).
- An **owner** holds permissions: a model with the trait `HasPermissions` (users), or a plain name without a model (`Role`, `Group`, `Team`). Owner types are listed in `config/access.php` under `owner_types`.
- A **permission** or a **prohibition** links an owner to a rule, optionally with an **option** (`profile.update.email`) and a **condition**.
- Owners **inherit** from each other to any depth. That is how roles work.
- The package plugs into `Gate::before` and answers "yes" or nothing. Everything else in Laravel authorization keeps working next to it.

Priority, from the weakest to the strongest:

1. nothing is said: not permitted
2. inherited permission
3. inherited prohibition
4. own permission
5. own prohibition

So a role can hide something from everybody and one user still gets it back with a permission of their own.

## Setup checklist

```php
// config/access.php
'owner_types' => [App\Models\User::class, 'Role', 'Team'],

'resources' => [                       // whitelist of models conditions may read
    'order'  => App\Models\Order::class,
    'client' => App\Models\Client::class,
    'item'   => App\Models\Item::class,
],

'tenant_types' => ['Team'],            // optional: owners of these types become user.tenant
'guest' => ['type' => 'Role', 'id' => 'guest'],   // optional: whom unauthenticated requests are checked as
```

```php
class User extends Authenticatable { use \Wnikk\LaravelAccessRules\Traits\HasPermissions; }
class Order extends Model          { use \Wnikk\LaravelAccessRules\Traits\HasAccessScope; }   // adds allowedTo()
```

Relations used in conditions **must declare their return type**: `public function client(): BelongsTo`. The package never calls a method to find out what it returns.

## Rules

```php
use Wnikk\LaravelAccessRules\Facades\Access;
use Wnikk\LaravelAccessRules\Models\RuleOrigin;

Access::newRule('orders.view', 'View orders', resource: 'order');
Access::newRule('orders.export', 'Export', options: 'required|in:csv,pdf', resource: 'order');
Access::newRule('orders.update.self', 'Update own orders', resource: 'order');       // suffix .self = only the author
Access::newRule('orders.archive', 'Archive', resource: 'order', when: 'not order.locked');   // condition for every holder

// names that code builds at run time, e.g. can('news.edit.'.$category->slug), are created as custom:
Access::newRule('news.edit.sport', 'Edit sport news', origin: RuleOrigin::Custom);

Access::delRule('orders.view');         // throws RULE_IN_USE while somebody holds the rule
Access::delRule('orders.view', true);   // deletes it together with its permissions (rollbacks, tests)
```

Abilities can be a backed enum everywhere a name is accepted.

## Granting

```php
use Wnikk\LaravelAccessRules\Facades\Access;

$user->addPermission('orders.view');
$user->addProhibition('orders.delete');
$user->addPermission('orders.export', 'csv');                       // option
$user->remPermission('orders.view');
$user->inheritPermissionFrom('Role', 'manager');

Access::for('Role', 'manager')->create('Managers');                 // owners without a model
Access::for('Role', 'manager')->allow('orders.view', when: '...');
Access::for('Role', 'manager')->deny('orders.view', when: '...');
Access::for('Role', 'chief')->inheritFrom('Role', 'manager');

Access::batch(fn () => /* hundreds of changes */ null);            // one drop of the cache
```

Mistakes throw `AccessRulesException` with a code: `RULE_NOT_FOUND`, `DUPLICATE_PERMISSION`, `INVALID_OPTION`, `INHERITANCE_LOOP`, `RULE_IN_USE`, `RULE_MANAGED_BY_CODE`. A wrong condition throws `InvalidConditionException` **when it is saved**, not later.

## Conditions

One text, two uses that always agree:

```php
$user->can('orders.view', $order);            // evaluated in memory, no queries for a loaded record
Order::allowedTo('orders.view')->paginate();  // compiled into WHERE, one query; works with your own where(), orderBy(), paginate()
```

| | |
|---|---|
| the record | `order.cost`, `resource.cost` |
| related record (to-one) | `order.client.city`, `order.client.parent.city` |
| the user | `user.id`, `user.department_id`, any attribute; `user.tenant`, `user.guest` |
| environment | `env.now`, `env.today`, `env.hour`, `env.weekday` (1 = Monday), `env.ip`, `env.app` |
| comparison | `== != > >= < <=`, `in [..]`, `not in [..]`, `x in user.tenant`, `between a and b` |
| logic | `&&` `and`, `\|\|` `or`, `!` `not`, brackets |
| arithmetic | `+ - * /`: `order.cost * 1.2 > order.budget` |
| text (exact, case included) | `startsWith(x, 'A-')`, `endsWith()`, `contains()`, `lower(x) == lower(user.email)` |
| time | `now()`, `today()`, `ago('24 hours')`, `after('7 days')`, `monthStart(-1)`, `yearStart()` |
| aggregates over to-many | `order.items.count < 3`, `count()`, `sum()`, `min()`, `max()`, `exists()`, each with an optional filter |
| pivot | `exists(order.products, pivot.quantity > 4)`, `sum(order.products.pivot.quantity)` |
| trees (`parent_id`) | `order.category_id in belowOrSelf('category.slug', 'electronics')`, also `below`, `above`, `aboveOrSelf` |
| author of the record | `isAuthor()`, or the suffix `.self` on the rule |

```php
'order.cost <= user.approval_limit && order.user_id != user.id'                  // approve up to a limit, never your own
"sum(client.payments.amount, paid_at >= monthStart(-1)) > 1000 && client.city != 'Y'"
'order.client.team_id in user.tenant'                                            // multi-tenancy without a role per tenant
"exists(product.tags, name == 'sale') && exists(product.comments, likes.count > 10)"   // polymorphic relations work the same
```

NULL works as in SQL: a comparison with NULL is *unknown* and the permission does not apply. Write `client.city == null || client.city != 'Y'` when such records are meant.

The column decides how a comparison works: a text column compares as text (`'01234'` is not `1234`), a number column as a number.

Conditions in code instead of text, with the same result:

```php
use Wnikk\LaravelAccessRules\Conditions\Cond;

Cond::all(Cond::attr('order.cost')->gt(100), Cond::count('order.items')->lt(3));
```

Own attributes and functions are invokable classes in config `attributes` / `functions` (not closures: `config:cache` cannot store them).

## Performance notes for generated code

- Do not loop over records with `can()` to build a list. Use `allowedTo()`.
- Eager load relations a condition reads when checking many loaded records.
- `count()`, `sum()`, `min()`, `max()` over a relation are correlated subqueries in a list. On big tables with `paginate()` prefer a counter column (`order.items_count < 3`), which is an ordinary attribute for a condition.
- Do not call `Access::flush()` after changes made through the package. It is for rows changed with plain SQL.

## Finding out why

```php
$user->access()->explain('orders.view', $order);   // every permission that took part, strongest first, the one that decided, values it read
```

```bash
php artisan acr:explain "App\Models\User" 7 orders.view order:4
php artisan acr:lint            # exit code 1 when a stored condition no longer matches models or config
php artisan acr:lint --fix      # saves again conditions whose column types changed
```

Debug mode for a support session (turn it on per request, never for ordinary users): `Access::debug()` in a middleware, then `Access::debugLog()` holds every refused check with its explanation under `text`, and for every `allowedTo()` the conditions and the SQL that narrowed the list. The mode only observes; it changes no decision. `Access::lastDenied()` names the last ability that was not permitted, in any mode.

`app(\Wnikk\LaravelAccessRules\Administration\Linter::class)->run()` returns the findings of lint as data, for a health screen.

## Admin panels

Rules that come with code (`origin = code`) may only be reworded and have their options edited; `custom` and `import` rules are fully editable. Use the two methods that respect this:

```php
use Wnikk\LaravelAccessRules\Administration\RuleCatalog;

$catalog->edit('orders.export', ['title' => 'Export orders', 'options' => 'required|in:csv,pdf']);
$catalog->discard('news.edit.sport');     // refuses rules of code (RULE_MANAGED_BY_CODE) and rules somebody holds (RULE_IN_USE)
```

Check a typed condition before saving with `Cond::compile($text, $rule->resource)` (throws `InvalidConditionException` with the message). Show a stored condition as text with `Cond::describe($permission->condition, $rule->resource)`. Never store a condition tree that came from a browser without passing it through `addPermission()` / `allow()`; they validate it.

Every change fires `Wnikk\LaravelAccessRules\Events\AccessChanged` (`$event->action`, `$event->details`): listen to it for an audit log.

## XACML 3.0

```php
use Wnikk\LaravelAccessRules\Xacml\Xacml;

return response()->streamDownload(fn () => $xacml->exportArchive('php://output'), 'access-rules.zip');

$plan = $xacml->check($request->file('policy'));                       // what an import would change, writes nothing
$done = $xacml->import($request->file('policy'), options: ['replace' => true]);
```

```bash
php artisan acr:xacml:export storage/app/access.zip
php artisan acr:xacml:import storage/app/access.zip --check
```

## Testing

```php
$user = User::factory()->create();
$user->addPermission('orders.view', when: 'order.cost > 100');

$this->actingAs($user)->get('/orders/'.$cheap->id)->assertForbidden();
$this->assertSame([$dear->id], Order::allowedTo('orders.view', $user)->pluck('id')->all());
```

Create rules in the test or run the seeder that creates them; a permission for a rule that does not exist throws.

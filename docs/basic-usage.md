---
title: Basic Usage
weight: 2
---

# Basic Usage

Three things exist in the package. A **rule** is a name of something that can be permitted, `articles.edit`.
An **owner** is whoever can hold permissions: a user, or a plain name like a role or a group.
A **permission** links the two and says "may" or "may not". Owners inherit from each other, which is how roles work.

Everything below assumes the [installation](installation.md) is done and the `User` model uses the trait:

```php
use Wnikk\LaravelAccessRules\Traits\HasPermissions;

class User extends Authenticatable
{
    use HasPermissions;
```

## Rules

A rule has to exist before anybody can hold it. Create rules in a migration or a seeder:

```php
use Wnikk\LaravelAccessRules\Facades\Access;

Access::newRule('articles.view', 'View articles');
Access::newRule('articles.edit', 'Edit articles', 'Shown in an admin panel as a hint');

// fields by name
Access::newRule(
    'profile.update',
    title: 'Change profile fields',
    options: 'required|in:name,email,password',   // validation rule of Laravel for the option, see "Options"
);

Access::delRule('articles.edit');               // refused with RULE_IN_USE while somebody holds the rule
Access::delRule('articles.edit', force: true);  // together with every permission and prohibition for it
```

A rule that somebody holds is not deleted by accident: its prohibitions would vanish with it, and nothing
brings them back. Children of a deleted rule move one level up in the tree.

`newRule()` returns the id of the rule. Pass it as the parent of another rule to group rules in an admin panel.
With `'rule_tree_inheritance' => true` in config the tree also passes permissions down: a permission for `reports`
covers `reports.sales`.

### Rules of code and rules of an admin panel

A rule is a name that code asks about, so every rule has an **origin**:

| origin | created by | an admin panel may |
|---|---|---|
| `code`, the default | a migration or a seeder, together with the code that checks it | change title, description, options and the place in the tree (not the place while `rule_tree_inheritance` is on) |
| `custom` | an administrator, for names that code builds at run time: `can('news.edit.'.$category->slug)` | change everything and delete |
| `import` | an import, because a foreign document named it | change everything and delete |

```php
use Wnikk\LaravelAccessRules\Models\RuleOrigin;

Access::newRule('news.edit.sport', 'Edit sport news', origin: RuleOrigin::Custom);
```

Code is trusted with everything: `newRule()`, `delRule()` and migrations do not look at the origin. An admin panel
goes through two methods that do:

```php
use Wnikk\LaravelAccessRules\Administration\RuleCatalog;

$catalog->edit('orders.export', ['title' => 'Export orders', 'options' => 'required|in:csv,pdf']);
$catalog->edit('orders.export', ['guard_name' => 'orders.download']);   // AccessRulesException, RULE_MANAGED_BY_CODE
$catalog->discard('orders.export');                                     // the same
$catalog->discard('news.edit.sport');                                   // deleted, unless somebody holds it: RULE_IN_USE
```

Options are validated when a permission is granted. After `in:csv,pdf` becomes `in:csv`, permissions for `pdf` keep
working, and `php artisan acr:lint` reports them.

Names of rules can be a backed enum, everywhere a name is accepted:

```php
enum Ability: string
{
    case ArticlesEdit = 'articles.edit';
}

$user->addPermission(Ability::ArticlesEdit);
$user->can(Ability::ArticlesEdit);
```

## Permissions and prohibitions

```php
$user->addPermission('articles.edit');
$user->addProhibition('articles.delete');

$user->remPermission('articles.edit');
$user->remProhibition('articles.delete');
```

A missing rule, a repeated permission or a wrong option throw `AccessRulesException`; its code says which one
(`RULE_NOT_FOUND`, `DUPLICATE_PERMISSION`, `INVALID_OPTION`, ...).

## Roles, groups and inheritance

A role is an owner without a model. Name its type in `owner_types` of `config/access.php`, then:

```php
use Wnikk\LaravelAccessRules\Facades\Access;

Access::for('Role', 'editor')->create('Editors');
Access::for('Role', 'editor')->allow('articles.view');
Access::for('Role', 'editor')->allow('articles.edit');

$user->inheritPermissionFrom('Role', 'editor');
$user->remInheritFrom('Role', 'editor');
```

Anything can inherit from anything, to any depth: a user from a role, a role from a role, a user from another user.

```php
$user->inheritPermissionFrom(User::find(1));     // from a model
$user->inheritPermissionFrom(User::class, 1);    // by type and id
Access::for('Role', 'chief-editor')->inheritFrom('Role', 'editor');
```

A link that closes a loop is refused with `AccessRulesException::INHERITANCE_LOOP`.

The object of version 2, `new AccessRules` with `setOwner()`, works as before and seeders written for it need
no changes, see [Upgrade from 2.x to 3.x](upgrade-2-to-3.md). New code uses `Access::for()` and the trait.

### What wins

From the weakest to the strongest:

1. nothing is said: not permitted
2. inherited permission
3. inherited prohibition
4. own permission
5. own prohibition

So a role can prohibit something for everybody, and one user still gets it back with a permission of their own.

## Checking

The package answers the standard Gate of Laravel, so every usual way works:

```php
$user->can('articles.edit');
$user->cannot('articles.edit');
Gate::authorize('articles.edit', $article);
Gate::forUser($moderator)->allows('articles.edit');
```
```php
Route::get('/articles', [ArticleController::class, 'index'])->middleware('can:articles.view');
```
```blade
@can('articles.edit', $article) ... @endcan
```

`authorizeResource()` checks `viewAny`, `view`, `create`, `update` and `delete` for the actions of a resource
controller. Since Laravel 11 the base controller of a fresh application is empty, and `authorizeResource()` needs two
things back: the trait `Illuminate\Foundation\Auth\Access\AuthorizesRequests`, and the parent
`Illuminate\Routing\Controller`, whose `middleware()` it calls. Without the parent the call fails with
"Call to undefined method middleware()".

```php
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController   // app/Http/Controllers/Controller.php
{
    use AuthorizesRequests;
}

class ArticleController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Article::class);
    }
```

The package answers Gate with "yes" or with nothing. When it does not permit, the other `Gate::before` callbacks,
policies and `Gate::define()` decide as usual, so a super administrator of the application, Nova, Filament or another
permission package keep working next to it. A **prohibition takes a permission away and is no veto**: like most
permission systems this one starts from "everything is forbidden", and a policy for the same ability can still permit.

The message of a 403 stays the one of Laravel. What was refused is known to your error page:

```php
Access::lastDenied();   // 'articles.edit'
```

To ask the package alone, past Gate and policies:

```php
$user->hasPermission('articles.edit');             // true, false, or null when nothing is said
Access::for('Role', 'editor')->can('articles.edit');
```

### Reading what an owner holds

For an admin panel or a console: every row that reaches an owner with where it comes from, whom it inherits
from and who inherits from it. Data, not decisions; a few queries, so keep it off the path of a request.

```php
Access::for($user)->permissions();
// [['rule' => 'orders.view', 'rule_id' => 12, 'option' => null, 'effect' => 'deny', 'when' => 'order.locked == true',
//   'own' => false, 'from' => ['type' => 'Role', 'id' => 'manager', 'name' => 'Managers', 'record' => 3],
//   'via' => null, 'via_rule' => null], ...]

Access::for($user)->sources();   // whom it inherits from, at any depth: type, id, name, record, direct, link, through
Access::for('Role', 'manager')->heirs();   // who inherits from it, the same shape
```

Rows are grouped by rule and ordered strongest first inside a rule, by the five steps. An indirect source or
heir names in `through` the record of the direct one it is reached by, the link to change when it should go.
With `rule_tree_inheritance` on, a row on a rule is listed under every rule below it too, with `via` set to
`tree` and `via_rule` naming the rule it sits on.

### Guests

Requests without a user have no permissions unless config names the owner they are checked as:

```php
'guest' => ['type' => 'Role', 'id' => 'guest'],
```

Signed in users do not get permissions of the guest on top of their own. A user that should have them
inherits from that owner like from any role.

## Options

One rule with a list of values instead of a rule per value. The rule declares what values are valid
with a validation rule of Laravel:

```php
Access::newRule('profile.update', options: 'required|in:name,email,password');

$user->addPermission('profile.update', 'email');

$user->can('profile.update.email');      // true
$user->can('profile.update.password');   // false
```

## The author of a record: suffix ".self"

"May edit any comment" and "may edit own comments" are two rules, the second with the suffix:

```php
Access::newRule('comments.edit', 'Edit any comment');
Access::newRule('comments.edit.self', 'Edit own comments');

$user->addPermission('comments.edit.self');
```

The check asks about the main rule and passes the record. The package compares the author by itself:

```php
$user->can('comments.edit', $comment);   // true when $comment->user_id == $user->id
$user->can('comments.edit');             // false: nothing to compare with
```

The column of the author comes from the class and the key of the user: `User` with key `id` gives `user_id`,
`Moderator` with key `uuid` gives `moderator_uuid`. For another name set `author_key` in config for all models,
or declare it on a model:

```php
public function accessAuthorKey(): string
{
    return 'created_by';
}
```

## Conditions and lists

Since 3.x a permission can depend on data, and the same condition filters lists:

```php
$role->addPermission('orders.view', when: 'order.cost > 100 && order.items.count < 3');

$user->can('orders.view', $order);
Order::allowedTo('orders.view')->paginate();
```

This is the subject of [Conditions (ABAC)](conditions.md).

## Cache

Compiled permissions of an owner are cached, and every change through the package drops the cache by itself.
Two cases need a hand:

```php
// a seeder or an import with hundreds of changes: one drop after the last of them
Access::batch(function () use ($users) {
    foreach ($users as $user) {
        $user->inheritPermissionFrom('Role', 'editor');
    }
});

// rows were changed past the package, with plain SQL
Access::flush();
```

```bash
php artisan acr:cache:clear
```

If the cache store is down, the package reads from the database, logs one warning and throws nothing.

## Following changes

Every change dispatches `Wnikk\LaravelAccessRules\Events\AccessChanged` with what changed and for whom.
The package keeps no audit log of its own; a listener of this event writes one.

## Console

| command | |
|---|---|
| `acr:create {rule} {title?} {options?} {description?} {parent_id?} --resource= --when= --origin=` | new rule |
| `acr:delete {rule} --force` | delete a rule; `--force` also removes the permissions that hold it |
| `acr:owners` | list of owners |
| `acr:assign {owner_type} {owner_id} {rule} {option?} {availability?} --when=` | permission; `availability` "no" makes it a prohibition |
| `acr:remove {owner_type} {owner_id} {rule} {option?} {availability?}` | take it away |
| `acr:inherit {primary_owner_type} {primary_owner_id} {owner_type} {owner_id}` | the second owner inherits from the first (primary) one |
| `acr:not-inherit ...` | stop inheriting |
| `acr:explain {owner_type} {owner_id} {ability} {record?}` | why a check answers what it answers |
| `acr:lint --fix` | stored conditions against models and config as they are now; `--fix` saves again those whose column types changed |
| `acr:cache:clear` | drop cached permissions |
| `acr:xacml:export {target}` | permissions as one XACML 3.0 policy document, see [XACML](xacml.md) |
| `acr:xacml:import {source} --check --all --replace --partial --subject-type= --role-type= --everyone=` | show what an XACML 3.0 policy would change, or convert it into permissions |

## When access is refused and it is not clear why

```bash
php artisan acr:explain "App\Models\User" 7 articles.edit
```

or turn on debug mode for a request with `Access::debug()`: every refusal then carries its cause in the message.
Both are described in [Conditions](conditions.md#why-is-this-the-answer-and-is-everything-still-valid).

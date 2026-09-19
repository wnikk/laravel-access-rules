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
use Wnikk\LaravelAccessRules\AccessRules;

AccessRules::newRule('articles.view', 'View articles');
AccessRules::newRule('articles.edit', 'Edit articles', 'Shown in an admin panel as a hint');

// all fields by name
AccessRules::newRule([
    'guard_name' => 'profile.update',
    'title'      => 'Change profile fields',
    'options'    => 'required|in:name,email,password',   // validation rule of Laravel for the option, see "Options"
]);

AccessRules::delRule('articles.edit');               // soft delete: permissions stay and return with the rule
AccessRules::delRule('articles.edit', force: true);  // for good, together with permissions
```

`newRule()` returns the id of the rule. Pass it as the parent of another rule to group rules in an admin panel.
With `'rule_tree_inheritance' => true` in config the tree also passes permissions down: a permission for `reports`
covers `reports.sales`.

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

The object of version 2 works as before, seeders written for it need no changes:

```php
$acr = new AccessRules;
$acr->newOwner('Role', 'editor', 'Editors');
$acr->addPermission('articles.edit');
```

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
controller. The controller needs the trait `Illuminate\Foundation\Auth\Access\AuthorizesRequests`, which the base
controller of a fresh Laravel application no longer has:

```php
class ArticleController extends Controller
{
    use AuthorizesRequests;

    public function __construct()
    {
        $this->authorizeResource(Article::class);
    }
```

A refusal says what was refused, `Action "articles.edit" is unauthorized.`; see `denial_message` in config.
A prohibition of the package is final: policies and `Gate::define()` are not asked after it (`deny_is_final`).
When the package has nothing to say, they decide as usual.

To ask the package alone, past Gate and policies:

```php
$user->hasPermission('articles.edit');             // true, false, or null when nothing is said
Access::for('Role', 'editor')->can('articles.edit');
```

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
AccessRules::newRule([
    'guard_name' => 'profile.update',
    'options'    => 'required|in:name,email,password',
]);

$user->addPermission('profile.update', 'email');

$user->can('profile.update.email');      // true
$user->can('profile.update.password');   // false
```

## The author of a record: suffix ".self"

"May edit any comment" and "may edit own comments" are two rules, the second with the suffix:

```php
AccessRules::newRule('comments.edit', 'Edit any comment');
AccessRules::newRule('comments.edit.self', 'Edit own comments');

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
| `acr:create {rule} {title?} {options?} {description?} {parent_id?} --resource= --when=` | new rule |
| `acr:delete {rule} --force` | delete a rule |
| `acr:owners` | list of owners |
| `acr:assign {owner_type} {owner_id} {rule} {option?} {availability?} --when=` | permission; `availability` "no" makes it a prohibition |
| `acr:remove {owner_type} {owner_id} {rule} {option?} {availability?}` | take it away |
| `acr:inherit {primary_owner_type} {primary_owner_id} {owner_type} {owner_id}` | the second owner inherits from the first (primary) one |
| `acr:not-inherit ...` | stop inheriting |
| `acr:explain {owner_type} {owner_id} {ability} {record?}` | why a check answers what it answers |
| `acr:lint` | stored conditions against models and config as they are now |
| `acr:cache:clear` | drop cached permissions |

## When access is refused and it is not clear why

```bash
php artisan acr:explain "App\Models\User" 7 articles.edit
```

or turn on debug mode for a request with `Access::debug()`: every refusal then carries its cause in the message.
Both are described in [Conditions](conditions.md#why-is-this-the-answer-and-is-everything-still-valid).

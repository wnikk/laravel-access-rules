---
title: Upgrade from 2.x to 3.x
weight: 4
---

# Upgrade from 2.x to 3.x

Data, tables, config keys, names of rules, the magic suffix `.self`, options, artisan commands
and methods of the trait `HasPermissions` are compatible. Nothing has to be converted.

## Steps

1. PHP 8.4+ and Laravel 13+ are required.
2. Upgrade the tables. New columns are added and existing rows stay valid:
   ```bash
   php artisan vendor:publish --tag=access-migrations-upgrade
   php artisan migrate
   ```
   Rules that 2.x had **soft deleted** are renamed to `deprecated__<name>` and the column `deleted_at` is dropped,
   see "Rules are deleted for good" below.

   The same migration adds the unique indexes and the foreign key that tables of 3.x have from the start.
   2.x could store an owner, a link or a permission twice. When such rows exist the migration lists them and stops
   **before it has changed anything**: remove the duplicates and run `migrate` again.
3. Compare your `config/access.php` with the new one: there are new keys (`resources`, `tenant_types`, `guest`,
   `debug`, `rule_tree_inheritance`, `hierarchy`, `auto_create_owner`,
   `author_key`, `attributes`, `functions`, `xacml`). All of them have defaults, the file may stay as it is.
4. `php artisan acr:cache:clear` - format of cached permissions has changed.

Code written for 2.x keeps working. What new code uses instead:

| 2.x, still works | 3.x |
|---|---|
| `AccessRules::newRule()`, `delRule()`, `flush()` | `Access::newRule()`, `Access::delRule()`, `Access::flush()` |
| `newRule(['guard_name' => 'x', 'options' => '...'])` | `Access::newRule('x', options: '...')` |
| `$acr = new AccessRules; $acr->newOwner('Role', 'editor', 'Editors')` | `Access::for('Role', 'editor')->create('Editors')` |
| `$acr->setOwner('Role', 'editor')->addPermission('x')` | `Access::for('Role', 'editor')->allow('x')` |
| `$acr->addProhibition('x')`, `remPermission()`, `remProhibition()` | `->deny('x')`, `->removeAllow()`, `->removeDeny()` |
| `$acr->inheritFrom(...)`, `remInheritFrom(...)` | `->inheritFrom(...)`, `->stopInheritingFrom(...)` |
| `$acr->can('x')` | `Access::for(...)->can('x')` |
| `AccessRules::getLastDisallowPermission()` | `Access::lastDenied()` |

Methods of the trait `HasPermissions` are the same in both versions.

## Changes of behaviour

- **Own prohibition is the strongest.** Priority, from the weakest: inherited permission, inherited prohibition,
  own permission, own prohibition. Code of 2.x let own permission beat own prohibition of the same owner; all other cases are the same.
  The suffix `.self` is a permission of its step now: in 2.x it let the author pass even when the rule itself was prohibited.
- **Laravel Gate is used as before.** The package answers "yes" or nothing, a prohibition takes a permission away
  and is no veto, and `Gate::before` of your application, policies and `Gate::define()` work next to it as in 2.x.
- **`.self` compares keys loosely**: `"5"` and `5` are the same author. In 2.x a key that came as a string was refused.
- **Rules are deleted for good.** 2.x soft deleted a rule: the row stayed, its name stayed taken, and the package had
  no way to bring it back. Now `delRule('x')` deletes, and it refuses with `RULE_IN_USE` while somebody holds the rule,
  because its prohibitions would vanish with it. `delRule('x', true)` and `acr:delete x --force` delete the rule
  together with its permissions. **Check `down()` of your migrations**: a rollback that deletes a granted rule needs `true`.
  Rows that 2.x had soft deleted do not come back to life: the upgrade renames them to `deprecated__<name>`, keeps their
  permissions, frees the name and moves their children one level up. Remove them when convenient.
- **Rules have an origin**, `code` by default and for every rule of 2.x. It matters to admin panels only:
  `RuleCatalog::edit()` and `discard()` let a panel reword a rule of code and edit its options, and nothing else.
  See [Basic usage](basic-usage.md).
- **A model deleted softly keeps its owner and permissions**, they are removed when the model is deleted for real.
- A loop of inheritance is refused with `LogicException`.
- A model whose class is not in `owner_types` is left to Laravel Gate instead of an exception.

## Removed

`AccessRules::getLastDisallowPermission()` stays, new code asks `Access::lastDenied()`. For the whole
cause of a refusal see debug mode in [conditions.md](conditions.md).

- `AccessAuthorizationException` and the global mapping of `AuthorizationException`, which rewrote the message of
  every 403 of the application. The message is the one of Laravel again; print `Access::lastDenied()` on your
  error page to name what was refused, and see debug mode for the cause.
- `AccessRules::getThisPermitMap()`, `checkOwnerPermission()`, `checkUserIsAuthor()`,
  `checkMagicRuleSelf()`, `refreshPermission()`, `forgetCachedPermissions()`, `forgetSelectedCachePermission()`.
  The cache follows every change by itself; `AccessRules::flush()` when it is really needed,
  `Access::batch(fn () => ...)` to flush once after many changes. `clearAllCachedPermissions()` is kept as deprecated:
  migrations written for 2.x end with it.
- Methods of models: `Owner::findOwner()`, `addPermission()`, `addInheritance()` ..., `Rule::findRule()`.
  Use `AccessRules` (`setOwner()`, `addPermission()`, `inheritFrom()` ...) or the trait.
- Cast `PermissionOption`, traits from `Helper\`, class `Aggregator`, property `$accessRules` of the trait.
- Contracts of models were reduced to relations. `Contracts\AccessRules` is replaced by `Contracts\AccessManager`:
  it keeps nothing between calls, `for($owner)` gives permissions of one owner. Class `AccessRules` works as before on top of it.
  The old interface stays as an **empty, deprecated shell**: code that type-hints it still loads and
  `app(Contracts\AccessRules::class)` still answers with the class, while your IDE shows that nothing may be called on it. Removed in 4.0.

## New

- Public methods are typed; an ability can be a backed enum.
- Mistakes throw `AccessRulesException` (a `LogicException`, as before) with a code that tells what went wrong.
- Event `Events\AccessChanged` after every change: the place for an audit log.
- `acr:explain` and debug mode, `acr:lint`, `Access::batch()`, conditions, `allowedTo()`, XACML export and import:
  see [Conditions](conditions.md) and [XACML](xacml.md).

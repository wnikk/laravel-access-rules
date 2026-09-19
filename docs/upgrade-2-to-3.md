---
title: Upgrade from 2.x to 3.x
weight: 4
---

# Upgrade from 2.x to 3.x

Data, tables, config keys, names of rules, the magic suffix `.self`, options, artisan commands
and methods of the trait `HasPermissions` are compatible. Nothing has to be converted.

## Steps

1. PHP 8.4+ and Laravel 13+ are required.
2. Add new columns (nullable, existing data stays valid):
   ```bash
   php artisan vendor:publish --tag=access-migrations-upgrade
   php artisan migrate
   ```
3. Compare your `config/access.php` with the new one: there are new keys (`resources`, `tenant_types`, `guest`,
   `deny_is_final`, `denial_message`, `rule_tree_inheritance`, `hierarchy`, `auto_create_owner`,
   `author_key`, `attributes`, `functions`). All of them have defaults, the file may stay as it is.
4. `php artisan acr:cache:clear` - format of cached permissions has changed.
5. Optional: unique indexes and a foreign key that 2.x did not have
   ```bash
   php artisan vendor:publish --tag=access-migrations-constraints
   ```

## Changes of behaviour

- **Own prohibition is the strongest.** Priority, from the weakest: inherited permission, inherited prohibition,
  own permission, own prohibition. Code of 2.x let own permission beat own prohibition of the same owner; all other cases are the same.
  The suffix `.self` is a permission of its step now: in 2.x it let the author pass even when the rule itself was prohibited.
- **A prohibition is final.** In 2.x a prohibition only took a permission away, and a Laravel policy or `Gate::define()`
  could still allow the ability. Now it denies. Old behaviour: `'deny_is_final' => false`.
- **`.self` compares keys loosely**: `"5"` and `5` are the same author. In 2.x a key that came as a string was refused.
- **A model deleted softly keeps its owner and permissions**, they are removed when the model is deleted for real.
- A loop of inheritance is refused with `LogicException`.
- A model whose class is not in `owner_types` is left to Laravel Gate instead of an exception.

## Removed

`AccessRules::getLastDisallowPermission()` stays and is now a static call without state of its own. For the whole
cause of a refusal see debug mode in [conditions.md](conditions.md).

- `AccessAuthorizationException` and the global mapping of `AuthorizationException`. The message
  `Action "x" is unauthorized.` now comes from the gate response, see `denial_message` in config.
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

## New

- Public methods are typed; an ability can be a backed enum.
- Mistakes throw `AccessRulesException` (a `LogicException`, as before) with a code that tells what went wrong.
- Event `Events\AccessChanged` after every change: the place for an audit log.

# Changelog

All notable changes to `laravel-access-rules` will be documented in this file

## 3.0.0 - 2026-09-19

Rewritten for Laravel 13+ and PHP 8.4+. Data and public API of 2.x stay compatible, see docs/upgrade-2-to-3.md.

- Conditions (ABAC): a permission, a prohibition or a rule can depend on data of the record,
  of its relations and their aggregates, on the user and on the environment; text language and builder `Cond`
- `acr:explain` and `OwnerAccess::explain()`: which permission decided, where it came from, what its condition read, whether the cache agrees
- Debug mode, `Access::debug()` or config `access.debug`: every refusal carries its explanation in the message of the 403, `Access::debugLog()` keeps refusals and the conditions and SQL behind every `allowedTo()` list. A permitted check costs the same with the mode on
- `AccessRules::getLastDisallowPermission()` is kept, now per request instead of a static property
- `acr:lint`: stored conditions, rules and owners checked against current models and config, for CI and deploys
- Trees in conditions: `below()`, `belowOrSelf()`, `above()`, `aboveOrSelf()` for models that point at themselves through `parent_id`
- Filtering of lists by the same conditions in the same query: `Model::query()->allowedTo()`, trait `HasAccessScope`
- A check of a record and a filter of a list always agree: both follow three-valued logic of SQL
- No queries after the first check of a request (2.x made one on every check), 3 queries to build permissions
  at any depth of inheritance (2.x: 12 and growing)
- Inherited owners are collected by one `WITH RECURSIVE` query where the database supports it
- Cache with generations: any change leaves everything cached behind at once, on any driver
- A prohibition is final for Laravel Gate (config `deny_is_final`)
- Guests (config `guest`), tenants / teams (`tenant_types`, `user.tenant`), optional inheritance down the tree of rules
- Message of an authorization error comes from the gate response, global mapping of exceptions is removed
- Migrations: new nullable columns for 2.x tables, optional unique indexes
- Own prohibition is the strongest: inherited permission < inherited prohibition < own permission < own prohibition
- `Contracts\AccessManager` / facade `Access`: entry point that keeps nothing between calls, `for($owner)` gives permissions of one owner;
  class `AccessRules` of 2.x works on top of it
- Typed public API, abilities as backed enums, `AccessRulesException` with codes, event `AccessChanged` for audit,
  `Access::batch()` to flush the cache once after many changes
- Code is split by areas of responsibility: Administration, Authorization, Conditions (Syntax, Evaluation), Storage

## 2.4.2 - 2026-09-18

- Fix: cached permissions are flushed after the changes are written to the database, not before.
- Fix: deleted, restored or renamed rule flushes cached permissions,
  deleted rule was still permitted until the cache expired
- Fix: permissions of soft deleted rules were listed by `getAllPermittedRule()`/`getAllProhibitedRule()`
- Added unit tests for cache invalidation

## 2.4.1 - 2026-09-18

- Security fix: permissions of one user could be served to another.
- Model listeners of `HasPermissions` are registered once per class (`bootHasPermissions`)
- `setOwner()` drops permissions loaded for previous owner
- Added regression tests for owner isolation

## 2.3.0 - 2026-05-26

- Enhance caching mechanism
- Lazyload check of caching,
- Added caching configurable options
- Added unit tests for caching

## 2.2.30 - 2026-03-24

- Check support for Laravel 13.x
- Check support for PHP 8.3
- Update unit tests for Laravel 13.x compatibility

## 2.2.25 - 2025-12-03

- Enhance access rules schema
- Refactor Aggregator to use trait

## 2.2.24 - 2025-07-28

- Optimized function deleting rules and owners

## 2.2.21 - 2025-06-13

- Fix force delete of rules
- Add unit tests for option permission

## 2.2.19 - 2025-06-11

- Optimized cache initialization
- Implemented the creation of a basic admin role,
  assigned a test permission to it,
  and ensured inheritance for the first user within this role.
- Add unit tests for the base functionality
- Add unit tests for the inherit permission functionality

## 2.0.16 - 2025-03-12

- Add optionality to check cache and throw if not working

## 2.0.15 - 2025-03-11

- Update Laravel 12.x Compatibility

## 2.0.14 - 2024-10-15

- Add permission inheritance management via Artisan command

## 2.0.11 - 2024-04-29

- Update Laravel 11.x Compatibility

## 2.0.11 - 2023-04-10

- Add method refreshPermission, forgetSelectedCachePermission

## 2.0.10 - 2023-04-07

- Added clear cache after changing permission

## 2.0.9 - 2023-03-28

- Fix support policies

## 2.0.8 - 2023-03-27

- Add method getLastRule on Exception
- Fix bug of add inherit to new user
- Fix of checkUserIsAuthor

## 2.0.1 - 2023-03-15

- Add support change the list of existing types on a running project
- Add title to rules

## 2.0.0 - 2023-03-11

- Add magic rule "self"
- Fix bug on hasPermission method

## 1.0.4 - 2023-03-10

- Add support OwnerContract on setOwner method
- Fix bug on inheritance

## 1.0.3 - 2023-03-09

- Add getListTypes method to trait AccessRulesTypeOwner
- Update name classes of Aggregator
- Update PermissionOption validator
- Add delRule method to trait AccessRulesPermission

## 1.0.1 - 2023-02-11

- Added the main methods to classes


## 1.0.0 - 2023-02-08

- Everything, initial release

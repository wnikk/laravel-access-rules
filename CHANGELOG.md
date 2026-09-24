# Changelog

All notable changes to `laravel-access-rules` will be documented in this file

## 3.2.2 - 2026-09-24

- XACML: the export is one file
- XACML: the export carries date and plan of a check
- The zip extension is removed

## 3.1.3 - 2026-09-22

- `Cond::compile()`: check a condition and get its tree without saving, for editors of admin panels
- XACML: the plan of `check()` no longer reports `not x` as a difference after a round trip
- `AccessRules`: methods of 2.x carry `@deprecated` with the form of 3.x
- CI runs only when code, tests, config or migrations change
- Improved documentation and examples

## 3.1.1 - 2026-09-22

- Laravel Boost guideline: `@can` rendered as written
- Description of the package on Packagist without a dash

## 3.1.0 - 2026-09-21

- XACML 3.0: `acr:xacml:export`, `acr:xacml:import`, entry class `Xacml\Xacml`
- Origin of a rule: `code`, `custom`, `import`; `RuleCatalog::edit()` and `discard()` for admin panels
- **Breaking:** rules are deleted for good, `delRule()` refuses a rule that has permissions unless forced
- `Administration\Linter`: findings of `acr:lint` as data, for admin panels
- Laravel Boost guidelines and skill, `docs/llms.txt`, `AGENTS.md`; `docs/performance.md` with a measured comparison
- Removed methods and classes are listed in the upgrade guide

## 3.0.0 - 2026-09-19

Rewritten for Laravel 13+ and PHP 8.4+. Tables and the public API of 2.x stay compatible: [upgrade guide](docs/upgrade-2-to-3.md).

- ABAC: conditions on permissions, prohibitions and rules
- Lists filtered by the same conditions: `allowedTo()`, trait `HasAccessScope`
- Conditions read relations, aggregates, pivot columns, trees, time, the user and the environment
- Builder `Cond` as an alternative to the text of a condition
- Priority in five steps, own prohibition is the strongest
- Guests, tenants, optional inheritance down the tree of rules
- Facade `Access`, typed API, abilities as enums, `AccessRulesException` with codes
- Event `AccessChanged`, `Access::batch()`
- No queries after the first check of a request, 3 queries to build permissions
- Inheritance collected by one `WITH RECURSIVE` query
- Cache with generations, fallback to the database when the store is down
- `acr:explain`, debug mode, `acr:lint`
- **Breaking:** global mapping of `AuthorizationException` removed, the message of a 403 is the one of Laravel
- Laravel Gate is used as in 2.x: the package answers "yes" or nothing, other callbacks and policies keep their word
- `Contracts\AccessRules` kept as an empty deprecated interface, replaced by `Contracts\AccessManager`

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

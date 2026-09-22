# AGENTS.md

Instructions for coding agents that work **on this package**. Agents that work on an application which uses the package get their instructions from `resources/boost/` through Laravel Boost.

## What this is

`wnikk/laravel-access-rules`, version 3: RBAC and ABAC for Laravel 13+ / PHP 8.4+. A permitted check costs 4 µs and no queries. A change that adds a query or a container lookup to that path needs a measured reason.

## Commands

```bash
composer install
./vendor/bin/phpunit                        # tests/Unit and tests/Feature, SQLite in memory
./vendor/bin/phpunit --testsuite Bench      # timings and query counts of the hot path
./vendor/bin/pint --test src/Some/File.php  # code style, only for files you created or edited; never the whole project
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=acr_test DB_USERNAME=postgres ./vendor/bin/phpunit
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=acr_test DB_USERNAME=root ./vendor/bin/phpunit
```

Run the suite on SQLite, PostgreSQL and MySQL after any change to SQL, conditions or migrations. The two readers of a condition, the evaluator and the SQL compiler, must give one answer.

## Layout

```
src/                     what an application may rely on
  AccessRules.php          the entry point of version 2, kept compatible; its methods carry @deprecated with the 3.x form
  Facades/Access           the entry point of version 3; Contracts/AccessManager is what it resolves
  Traits/                  HasPermissions for owners, HasAccessScope for models whose lists are filtered
  Administration/          OwnerAccess (one owner), RuleCatalog (edit and discard for admin panels), Linter
  Conditions/              Cond (builder, and describe() for showing a stored condition), Context (argument of own attributes and functions)
  Xacml/Xacml              export, check and import
  Commands/ Contracts/ Events/ Exceptions/ Models/
src/Internal/            everything else, marked @internal; free to change in any release
  Administration/          AccessManager, Owners, TypeRegistry
  Authorization/           Permissions, DecisionPoint, GateHook, Explainer       the path of a check
  Conditions/              ConditionCompiler, Normalizer, ResourceRegistry, Syntax/, Evaluation/
  Storage/                 PermissionCache, HierarchyQuery
  Xacml/                   Exporter, Importer, Expressions, Vocabulary
resources/boost          guidelines and a skill for agents inside applications; keep them true to the code
docs/                    user documentation; docs/llms.txt is its map for agents
_dev/                    research notes and decisions (Russian), 08-decisions.md is the log of why
```

Nothing in `tests/Feature`, `docs/` or `resources/` names a class of `src/Internal`. When a test or a page needs one,
add what is missing to the public surface, as `Cond::describe()` was added.

`src/Internal/Authorization` and `src/Internal/Storage/PermissionCache.php` are the hot path. A change there comes with
`./vendor/bin/phpunit --testsuite Bench` before and after.

## Rules of this repository

- **tests/Unit holds the original tests of version 2** and is the proof of backward compatibility. Do not reformat them, do not rewrite their comments, do not "fix" their assertions. Change one only where it calls something version 3 removed or deprecated; delete one only when it tests behaviour that exists in version 2 alone.
- **New tests go to tests/Feature and treat src/ as a black box.** Test what the documentation promises through what an application uses: traits, the `Access` facade, Gate, artisan commands, config, events, `Xacml\Xacml`. Assert what an application can observe: a decision, a list, a query count, a documented table, command output. One promise, one test.
- **Comments and PHPDoc are English and carry decisions**: what was chosen, what was rejected and why, what breaks otherwise, where a number comes from. No restating of signatures.
- **Prose is plain**, in comments, `docs/` and this file: no dashes as pauses, no decorative adverbs ("really", "simply", "silently"), no metaphors ("door", "guest"), no closing one-liners written to be quoted, no "not X, it is Y" unless X is an alternative that was rejected. Name who does what: "the package refuses", "you run".
- No classes that only carry two fields or forward calls. No DTO or value object without a reason that survives review.
- `Gate::before` answers `true` or `null`. The package denies nothing through Gate and registers nothing at `Gate::after`.
- Every change of access goes through `Administration\Owners` or `RuleCatalog`: write the row, switch the cache generation, dispatch `AccessChanged`, in that order.
- Conditions are parsed and validated when they are saved. Nothing parses text during a check.
- Documentation is part of the change: `docs/`, `resources/boost/`, `CHANGELOG.md` (short phrases), and a decision in `_dev/08-decisions.md` when behaviour changes.
- Commits and tags are made by the owner. Do not commit, push or touch the index.

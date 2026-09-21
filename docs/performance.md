---
title: Performance
weight: 6
---

# Performance

Speed has been a design goal of this package since version 1. This page says where it comes from, what was measured,
and what is expensive, so you can judge it for your own project.

## Why a check is cheap

- **A check is an array lookup.** Permissions of an owner, everything it inherits included, are compiled once into
  a plain array: `isset($compiled['permit']['orders.view'])`. No models, no collections, no loop over roles.
- **Only one owner is loaded.** A request reads the compiled permissions of the user it serves, one cache entry
  of a few kilobytes. The package never loads "all permissions of the application".
- **No queries after the first check of a request**, and none at all when the entry is in the cache.
  Building an entry takes **3 queries at any depth of inheritance**: the owner, its ancestors in one
  `WITH RECURSIVE` query, permissions joined with rules.
- **Nothing happens at boot.** The service provider registers one `Gate::before` callback. It resolves no services,
  opens no cache and runs no query until somebody asks.
- **Conditions are parsed when they are saved, never during a check.** The database and the cache hold a tree.
  Evaluating five predicates over a loaded record costs about 12 µs and no queries.
- **A list is one query.** `allowedTo()` adds the condition to the `WHERE` of your query. Parts that do not depend on
  the record (the user, the clock, the IP) are computed before the query: on a Saturday
  `env.weekday in [1,2,3,4,5] && ...` reaches the database as `0 = 1`.
- **The cache has generations instead of a list of keys.** A change replaces one token, and everything cached before
  becomes unreachable at once, on any cache driver, without tags and without scanning.
- **A store that is down slows checks and breaks nothing**: the package reads from the database and logs one warning.

## Measured

### The package alone

`./vendor/bin/phpunit --testsuite Bench`, 200 rules, a chain of 5 roles with 40 permissions each, through real Laravel Gate:

| | version 3 | version 2.3 |
|---|---|---|
| first check of a request, nothing cached | 3 queries, about 1 ms | 12 queries, 9.2 ms |
| every next check, permitted | **4.2 µs, 0 queries** | 62 µs, 1 query |
| every next check, not permitted | 4.2 µs, 0 queries | |
| check with a condition of 5 predicates over a loaded record | 11.9 µs, 0 queries | |
| collecting inheritance 50 levels deep (MySQL) | 1 query, 0.16 ms | 51 queries, 4.9 ms (a query per level, as version 2 walked it) |

For scale, Laravel Gate itself spends about 2 µs on a check that the package is not part of.

### Next to other packages

One machine, one run, the same data for everybody, **MySQL 9.7**, PHP 8.4.23, Laravel 13, Apple M5 Pro on mains power,
file cache store, every package with its default config. Measured on 2026-09-21 with Spatie 8.3.0 and Bouncer 1.0.4;
Bouncer ran with its cross-request cache turned on.

What was measured is **one request of a signed-in user**, in a PHP process of its own, after a warm-up request had
filled the caches: the first `Gate::allows()` of the request, then the cost of every next one. Classes were loaded
before the clock started, as they are under PHP-FPM with opcache. Every check went through `Gate::forUser($user)->allows()`.
Three runs each; the table shows the median.

**200 abilities, 5 roles with 40 each, the user holds 4 roles (160 permissions), 1 000 other users:**

| | this package | Spatie | Bouncer |
|---|---|---|---|
| first check of a request | **0.2 ms, 0 queries**, 27 KB | 2.0 ms, 2 queries, 428 KB | 1.4 ms, 0 queries |
| next check, ability the user holds | **4.0 µs** | 27.8 µs | 1 735 µs |
| next check, ability the user does not hold | **4.1 µs** | 170 µs | 1 896 µs |
| next check, an ability of a policy (`update`), which is no permission at all | **4.1 µs** | 152 µs | 1 828 µs |
| a page with 50 different checks | **0.2 ms** | 2.4 ms | 81 ms |

**2 000 abilities, 20 roles with 100 each, the user holds 5 roles (500 permissions), 5 000 other users:**

| | this package | Spatie | Bouncer |
|---|---|---|---|
| first check of a request | **0.2 ms, 0 queries**, 51 KB | 5.0 ms, 2 queries, 3.9 MB | 4.2 ms, 0 queries |
| next check, ability the user holds | **4.0 µs** | 28.9 µs | 4 169 µs |
| next check, ability the user does not hold | **4.1 µs** | 1 461 µs | 4 198 µs |
| next check, an ability of a policy | **4.1 µs** | 1 426 µs | 4 187 µs |
| a page with 50 different checks | **0.2 ms** | 2.5 ms | 207 ms |

How to read it:

- The cost of this package does not grow with the size of the application, because a request loads the permissions
  of one owner. Spatie loads the whole list of permissions into memory on every request, which is
  the 3.9 MB and the 5 ms above, and looks an unknown or not-held name up in that list.
- The third line matters in every application that also uses policies. `Gate::before` of a permission package is
  called for **every** ability, `$user->can('update', $post)` included, so that cost is paid on checks that have
  nothing to do with permissions.
- Bouncer reads the abilities of the user from the cache store on every check. With the file store that is a file
  read and an unserialize per check; with Redis it is a network round trip per check. Without that cache
  its first check of a request costs about 8 ms and 2 queries, the rest is the same.
- These numbers say nothing about features. The feature table is in the README, under "Alternatives".

The comparison is not shipped with the package: a benchmark harness with two competing packages in its dependencies
is weight that an access control library should not carry. To repeat it, install the three packages into one empty
Laravel application, seed the same roles and abilities, and time `Gate::forUser($user)->allows()` in a process per request.

## What is expensive

- **Aggregates in lists.** `count()`, `sum()`, `min()`, `max()` over a relation become a correlated subquery, which the
  database runs for every row that reaches it. A page or a list narrowed by other conditions pays little; a whole table
  with nothing else to narrow it pays for every row, and `paginate()` asks for exactly that with its `count(*)`.
  Measured on PostgreSQL with 500 thousand comments and 3 million likes, "a comment with `count(likes) > 10`":
  5 ms for a page, 4.8 s for the whole list. A counter column (`comments.likes_count`) makes the question disappear,
  since it is an ordinary attribute for a condition. `simplePaginate()` and `cursorPaginate()` avoid the full count.
- **Aggregates for one loaded record** load the related rows as models. Eager load the relation when you check
  many records in a loop, or better, filter the list with `allowedTo()`.
- **An explained refusal** (`acr:explain`, debug mode) reads permissions from the database again, several queries.
  You run it as a tool or turn it on as a mode; a check does not include it.
- **The first check after a change** recompiles the permissions of the owner: 3 queries.

<?php

use App\Models\User;
use Wnikk\LaravelAccessRules\Models\Inheritance;
use Wnikk\LaravelAccessRules\Models\Owner;
use Wnikk\LaravelAccessRules\Models\Permission;
use Wnikk\LaravelAccessRules\Models\Rule;

return [

    /*
     * Who can hold permissions: model classes, and plain names like "Role" for owners without a model.
     *
     * The database stores a number computed from the name itself, CRC-16 of it. So the order of
     * this list means nothing, and a new entry can go anywhere. Renaming an entry is the dangerous
     * move: the new name gives a new number, and every owner stored under the old one loses its
     * permissions without an error. Rename a class only together with a data migration.
     */
    'owner_types' => [
        User::class,
        'Group',
        'Role',
    ],

    /*
     * Models that conditions may read, by the name they have in conditions:
     *     'order' => App\Models\Order::class   lets a condition say   order.cost > 100 && order.items.count < 3
     *
     * The list is a whitelist, and it covers relations too: "order.client.city" needs both Order
     * and Client here. Conditions can come from an admin panel, and without the list a condition
     * on orders could walk relations to any table of the application.
     *
     * A method counts as a relation when it declares its return type, "public function client(): BelongsTo".
     * Conditions never call a method to find out what it returns, because that method could be
     * delete(). For a relation without a return type, list it by hand:
     *     'order' => ['model' => App\Models\Order::class, 'relations' => ['client', 'items']],
     */
    'resources' => [
        // 'order'  => App\Models\Order::class,
        // 'client' => App\Models\Client::class,
    ],

    /*
     * Owner types that act as a tenant or a team. A user that inherits from owners of these
     * types gets their ids as the list "user.tenant", so a condition can say
     *     order.team_id in user.tenant
     * Membership is ordinary inheritance. The list costs nothing during a check: it is compiled
     * together with permissions of the user.
     */
    'tenant_types' => [
        // App\Models\Team::class,
    ],

    /*
     * The owner that unauthenticated requests are checked as, for example
     *     ['type' => 'Role', 'id' => 'guest']
     * Null means guests have no permissions, which is how version 2 behaved.
     *
     * Signed in users do not get permissions of the guest on top of their own. They are different
     * owners, and a silent merge would make "guests may not see this" impossible to express.
     * A user that should have them inherits from this owner like from any role.
     */
    'guest' => null,

    /*
     * True: every refusal is explained and every list narrowed by allowedTo() is written down, see
     * Access::debugLog(). The mode observes: it changes no decision and no message.
     *
     * Keep it false in production. An explanation shows rules of other owners, their conditions and
     * values of attributes. For a support session turn it on for one request instead: a middleware
     * calls Access::debug() when an administrator who looks at the application as a user has ticked
     * "debug", and the error page prints the last refusal from the log.
     *
     * Env var: ACCESS_RULES_DEBUG
     */
    'debug' => env('ACCESS_RULES_DEBUG', false),

    /*
     * True: what is said about a rule also covers the rules below it in the tree (parent_id),
     * so a permission for "reports" covers "reports.sales". What comes from above is weaker than
     * what is said about a rule itself, so "reports.sales" can still be prohibited for the same owner.
     *
     * False by default, because version 2 used the tree for grouping only, and existing projects
     * have trees that were never meant to pass permissions down. The tree is resolved when
     * permissions are compiled, so the setting does not change the speed of a check.
     */
    'rule_tree_inheritance' => false,

    /*
     * How owners inherited by an owner are collected.
     *   auto  one WITH RECURSIVE query where the server supports it, one query per level elsewhere
     *   cte   always WITH RECURSIVE, and an error if the server refuses it
     *   loop  always level by level, as version 2 did
     *
     * Leave "auto" unless the server reports a version that does not match what it can do.
     * With twenty levels of roles on PostgreSQL the difference is 0.15 ms against 2.2 ms per compile.
     */
    'hierarchy' => 'auto',

    /*
     * True: a model with the HasPermissions trait gets its record of owner when it is created,
     * so every user appears in lists of owners of an admin panel.
     *
     * False saves one insert per new user. Records then appear with the first permission or
     * inheritance, and users that never got one are not listed anywhere.
     */
    'auto_create_owner' => true,

    /*
     * Column that holds the author of a record, for the suffix ".self" and isAuthor().
     * Null means the convention of version 2: class User with key "id" gives "user_id".
     *
     * A value here applies to every model, so it fits projects with one name everywhere, like
     * "created_by". A model with a name of its own declares: public function accessAuthorKey(): string
     */
    'author_key' => null,

    /*
     * Attributes of your own for conditions, by their full name:
     *     'env.region' => App\Access\RegionAttribute::class     an invokable class: __invoke(Context $context)
     *
     * Use class names. "php artisan config:cache" cannot store a closure, and the
     * failure would show up on the deploy that caches config.
     */
    'attributes' => [],

    /*
     * Functions of your own for conditions:
     *     'limitFor' => App\Access\LimitFunction::class          __invoke(array $args, Context $context)
     *
     * A function that takes columns of the record, half(order.cost), works for a loaded record and
     * cannot filter a list: PHP does not run inside the database. allowedTo() throws in that case.
     * Functions of values known before the query, limitFor(env.region), work everywhere.
     */
    'functions' => [],

    'xacml' => [
        /*
         * Names of attributes in XACML documents, for "php artisan acr:xacml:export" and "acr:xacml:import":
         *     'urn:example:order:total' => 'order.cost',
         *     'urn:example:subject:department' => 'user.department_id',
         *
         * Without an entry the export names an attribute "urn:wnikk:access:resource:order:cost", which
         * only this package recognises. A foreign document uses names of its own, and an attribute
         * that is not listed here stops the import of the rule that reads it.
         */
        'attributes' => [],

        /*
         * Names of owner types in XACML documents. A document says "User:7", never the class
         * of the model: a class is a detail of this application, and the import resolves the
         * name back to a type of config owner_types. Optional, and first when present. Without
         * an entry the name is the short name of the class ("App\Models\User" is "User") or the
         * plain name of a type without a model ("Role"). Two types that end up with one name are
         * written by the number the core keeps them under, the CRC-16 of the name, which the
         * import reads as well. A key may be the type or that number:
         *     App\Models\User::class => 'employee',
         *     12345 => 'admin',
         */
        'types' => [],
    ],

    /*
     * True: the package answers $user->can(), @can, the "can:" middleware and authorizeResource().
     * False: nothing is registered on Laravel Gate, and only direct calls like hasPermission() work.
     * That is for projects that put their own logic between Gate and the package.
     */
    'register_permission_check_method' => true,

    /*
     * Models of the package. A replacement extends the original or implements the matching
     * contract from Wnikk\LaravelAccessRules\Contracts, for example to move the tables to
     * another connection. The package writes through its own services, so a replacement needs
     * the relations and columns and no logic.
     */
    'models' => [
        'rule'        => Rule::class,
        'permission'  => Permission::class,
        'owner'       => Owner::class,
        'inheritance' => Inheritance::class,
    ],

    /*
     * Names are those of version 2, so its data is found where it is. Change them before the
     * first migration; afterwards the tables have to be renamed by hand.
     */
    'table_names' => [
        'rule'        => 'access_rules',
        'permission'  => 'access_rules_permission',
        'owner'       => 'access_rules_owner',
        'inheritance' => 'access_rules_inheritance',
    ],

    'cache' => [
        /*
         * Compiling permissions of an owner takes three queries and a walk through its inheritance.
         * The cache pays that once per owner instead of once per request. With false every request
         * compiles again; checks inside of a request stay free either way.
         *
         * Env var: ACCESS_RULES_CACHE_ENABLED
         */
        'enabled' => env('ACCESS_RULES_CACHE_ENABLED', true),

        /*
         * Lifetime of an entry in minutes, one day by default.
         *
         * It does not decide how fast a change becomes visible. Every change switches the cache to
         * a new generation, and old entries are never read again. The lifetime only decides how long
         * those dead entries occupy the store, so there is little reason to lower it.
         */
        'expiration_time' => 24 * 60,

        /*
         * Prefix of cache keys. Give every application its own when several share one store,
         * otherwise they read each other's permissions.
         */
        'key' => 'access_rules.cache',

        /*
         * A store from config/cache.php, or 'default'. An unknown name falls back to the array
         * store, which lives for one request: a typo here slows checks down and breaks nothing.
         */
        'store' => 'default',

        /*
         * True: the first permission check of a PHP process proves that the store reads and writes.
         * If it does not, the package reads from the database, logs one warning and throws nothing,
         * so a Redis outage slows authorization down without taking it offline.
         *
         * The proof is one read for every process after the first, no write. False skips it, for
         * stores you trust; a broken store is then found by the first failing call, with the same fallback.
         *
         * Env var: ACCESS_RULES_CACHE_CHECK
         */
        'check' => env('ACCESS_RULES_CACHE_CHECK', true),
    ],
];

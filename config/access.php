<?php

return [
    /**
     * List of user types.
     * The list can be both the real name of the classes
     * or pseudonyms like "group".
     *
     * It is important in series of elements,
     * after the appointment of rights it is undesirable to change
     */
    'owner_types' => [
        App\Models\User::class,
        'Group',
        'Role',
    ],

    /*
     * When set to true, the method for checking permissions will be registered on the gate.
     * Set this to false, if you want to implement custom logic for checking permissions.
     */
    'register_permission_check_method' => true,


    'models' => [

        /*
         * When using the "hasPermission" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         *
         * The model you want to use as a Permission model needs to implement the
         * `Wnikk\LaravelAccessRules\Contracts\Rule` contract.
         */
        'rule' => Wnikk\LaravelAccessRules\Models\Rule::class,

        /*
         * When using the "hasPermission" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         *
         * The model you want to use as a Role model needs to implement the
         * `Wnikk\LaravelAccessRules\Contracts\Permission` contract.
         */
        'permission' => Wnikk\LaravelAccessRules\Models\Permission::class,

        /*
         * When using the "hasPermission" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         *
         * The model you want to use as a Role model needs to implement the
         * `Wnikk\LaravelAccessRules\Contracts\Owner` contract.
         */
        'owner' => Wnikk\LaravelAccessRules\Models\Owner::class,

        /*
         * When using the "hasPermission" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         *
         * The model you want to use as a Role model needs to implement the
         * `Wnikk\LaravelAccessRules\Contracts\Inheritance` contract.
         */
        'inheritance' => Wnikk\LaravelAccessRules\Models\Inheritance::class,
    ],

    'table_names' => [

        /*
         * When using the "hasPermission" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         */
        'rule' => 'access_rules',

        /*
         * When using the "hasPermission" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         */
        'permission' => 'access_rules_permission',

        /*
         * When using the "hasPermission" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         */
        'owner' => 'access_rules_owner',

        /*
         * When using the "hasPermission" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         */
        'inheritance' => 'access_rules_inheritance',
    ],

    'cache' => [

        /*
         * Master switch for permission caching.
         *
         * true  — permissions are cached (recommended for production; complex
         *         inheritance queries make caching critical for performance).
         * false — every hasPermission() call goes straight to the database.
         *         Useful when you want to disable caching intentionally (e.g.
         *         during debugging or in environments without a cache store).
         *
         * Env var: ACCESS_RULES_CACHE_ENABLED
         */
        'enabled' => env('ACCESS_RULES_CACHE_ENABLED', true),

        /*
         * By default all permissions are cached for 24 hours to speed up performance.
         * When permissions or roles are updated the cache is flushed automatically.
         */
        'expiration_time' => 24*60,

        /*
         * The cache key used to prefix stored permissions.
         */
        'key' => 'access_rules.cache',

        /*
         * You may optionally indicate a specific cache driver to use for permission and
         * role caching using any of the `store` drivers listed in the cache.php config
         * file. Using 'default' here means to use the `default` set in cache.php.
         */
        'store' => 'default',

        /*
         * Lazy smoke-test: on the very first hasPermission() call the package
         * performs a read/write check against the configured cache store.
         *
         * true  — recommended default. If the test key already exists in cache
         *         (written by a previous process) only a read is performed —
         *         no extra write on every worker boot. If the store is down the
         *         package silently falls back to direct DB queries and logs a
         *         one-time warning. No exception is ever thrown.
         * false — skip the smoke-test entirely; assume the cache store works.
         *
         * The test runs at most once per PHP process (result is cached statically).
         *
         * Env var: ACCESS_RULES_CACHE_CHECK
         */
        'check' => env('ACCESS_RULES_CACHE_CHECK', true),
    ],
];

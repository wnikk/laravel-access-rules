<?php

return [
    /**
     * List of user types.
     * The list can be both the real name of the classes
     * or pseudo names like "group".
     *
     * It is important in series of elements,
     * after the appointment of rights it is undesirable to change
     */
    'owner_types' => [
        'Root',
        'Groups',
        'User',
    ],

    /*
     * When set to true, the method for checking permissions will be registered on the gate.
     * Set this to false, if you want to implement custom logic for checking permissions.
     */
    'register_permission_check_method' => true,


    'models' => [

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         *
         * The model you want to use as a Permission model needs to implement the
         * `Wnikk\LaravelAccessRules\Contracts\Role` contract.
         */
        'role' => Wnikk\LaravelAccessRules\Models\Role::class,

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         *
         * The model you want to use as a Role model needs to implement the
         * `Wnikk\LaravelAccessRules\Contracts\Linkage` contract.
         */
        'linkage' => Wnikk\LaravelAccessRules\Models\Linkage::class,

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         *
         * The model you want to use as a Role model needs to implement the
         * `Wnikk\LaravelAccessRules\Contracts\Owners` contract.
         */
        'owners' => Wnikk\LaravelAccessRules\Models\Owners::class,

        /*
         * When using the "HasRoles" trait from this package, we need to know which
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
         * When using the "HasRoles" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         */
        'role' => 'access_rules_role',

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         */
        'linkage' => 'access_rules_linkage',

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         */
        'owners' => 'access_rules_owners',

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         */
        'inheritance' => 'access_rules_inheritance',
    ],

    'cache' => [

        /*
         * By default all permissions are cached for 24 hours to speed up performance.
         * When permissions or roles are updated the cache is flushed automatically.
         */
        'expiration_time' => 24*60,

        /*
         * The cache key used to prefix store list permissions.
         */
        'key' => 'access_rules.cache',

        /*
         * You may optionally indicate a specific cache driver to use for permission and
         * role caching using any of the `store` drivers listed in the cache.php config
         * file. Using 'default' here means to use the `default` set in cache.php.
         */
        'store' => 'default',
    ],
];

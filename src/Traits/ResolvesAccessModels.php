<?php

namespace Wnikk\LaravelAccessRules\Traits;

use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;
use Wnikk\LaravelAccessRules\Contracts\Inheritance as InheritanceContract;
use Wnikk\LaravelAccessRules\Contracts\Permission as PermissionContract;

trait ResolvesAccessModels
{
    /**
     * Returns the owner model instance.
     *
     * @return OwnerContract
     */
    protected static function getOwnerModel()
    {
        return app(OwnerContract::class);
    }

    /**
     * Returns the rule model instance.
     *
     * @return RuleContract
     */
    protected static function getRuleModel()
    {
        return app(RuleContract::class);
    }

    /**
     * Returns the inheritance model instance.
     *
     * @return InheritanceContract
     */
    protected static function getInheritanceModel()
    {
        return app(InheritanceContract::class);
    }

    /**
     * Returns the permission model instance.
     *
     * @return PermissionContract
     */
    protected static function getPermissionModel()
    {
        return app(PermissionContract::class);
    }
}

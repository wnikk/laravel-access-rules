<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Facades;

use Illuminate\Support\Facades\Facade;
use Wnikk\LaravelAccessRules\Contracts\AccessManager;

/**
 * Static door to Contracts\AccessManager.
 *
 * It is called Access, not AccessRules: the class AccessRules of version 2 lives in the root
 * namespace, and two imports with one short name in a seeder would force an alias on every user.
 *
 * @method static \Wnikk\LaravelAccessRules\Administration\OwnerAccess for(mixed $type, string|int|null $id = null)
 * @method static int|false                                            newRule(string|\BackedEnum $guardName, ?string $title = null, ?string $description = null, ?int $parentId = null, ?string $options = null, ?string $resource = null, string|\Wnikk\LaravelAccessRules\Conditions\Cond|array|null $when = null)
 * @method static bool                                                 delRule(string|\BackedEnum $guardName, bool $force = false)
 * @method static void                                                 flush()
 * @method static mixed                                                batch(\Closure $changes)
 * @method static void                                                 debug(bool $on = true)
 * @method static array                                                debugLog()
 *
 * @see \Wnikk\LaravelAccessRules\Administration\AccessManager
 */
class Access extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AccessManager::class;
    }
}

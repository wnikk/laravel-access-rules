<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules;

use BackedEnum;
use Wnikk\LaravelAccessRules\Administration\OwnerAccess;
use Wnikk\LaravelAccessRules\Administration\TypeRegistry;
use Wnikk\LaravelAccessRules\Authorization\Explainer;
use Wnikk\LaravelAccessRules\Authorization\Permissions;
use Wnikk\LaravelAccessRules\Conditions\Cond;
use Wnikk\LaravelAccessRules\Contracts\AccessManager;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;
use Wnikk\LaravelAccessRules\Storage\PermissionCache;

/**
 * The entry point of version 2, kept so that code written for it runs unchanged.
 *
 * Migrations and seeders of existing projects do "new AccessRules", select an owner and work
 * with it. Those files are already published into applications, and a package update cannot
 * rewrite them. So the class stays, with the same method names, as a thin shell over
 * Contracts\AccessManager.
 *
 * New code is better off with the manager or Facades\Access. This class remembers a selected
 * owner between calls, and an instance reused for a second owner without setOwner() works
 * with the first one. The manager has no such memory.
 *
 *     $acr = new AccessRules;
 *     $acr->newRule('orders.view', 'View orders', resource: 'order');
 *     $acr->newOwner('Role', 'manager', 'Managers');
 *     $acr->addPermission('orders.view', when: 'order.cost > 100 && order.items.count < 3');
 *
 * No logic lives here. Every method forwards to the manager or to the OwnerAccess of the
 * selected owner, so the two entry points cannot drift apart.
 */
class AccessRules
{
    private ?OwnerAccess $access = null;

    /**
     * @param  string|BackedEnum|array $guardName Name of the rule. Version 2 also took all fields as one array, and published migrations use that form.
     * @param  string|null             $options   Laravel validation rules for the option, for example "required|in:1,2,3".
     * @param  string|null             $resource  Alias from config access.resources: the model that conditions of the rule talk about.
     * @param  string|Cond|array|null  $when      Condition for everybody who holds the rule.
     * @return int|false               Id of the rule.
     */
    public static function newRule(string|BackedEnum|array $guardName, ?string $title = null, ?string $description = null, ?int $parentRuleID = null, ?string $options = null, ?string $resource = null, string|Cond|array|null $when = null): int|false
    {
        if (is_array($guardName)) {
            return app(AccessManager::class)->newRule(
                $guardName['guard_name'] ?? '',
                $guardName['title'] ?? null,
                $guardName['description'] ?? null,
                isset($guardName['parent_id']) ? (int) $guardName['parent_id'] : null,
                $guardName['options'] ?? null,
                $guardName['resource'] ?? null,
                $guardName['when'] ?? null,
            );
        }

        return app(AccessManager::class)->newRule($guardName, $title, $description, $parentRuleID, $options, $resource, $when);
    }

    /**
     * Soft delete keeps permissions, so the rule can be restored with them; $force removes both for good.
     */
    public static function delRule(string|BackedEnum $guardName, bool $force = false): bool
    {
        return app(AccessManager::class)->delRule($guardName, $force);
    }

    /**
     * Numeric id of a type of owners from config access.owner_types.
     *
     * @throws AccessRulesException when the type is not listed
     */
    public static function getTypeID(int|string $type): int
    {
        return app(TypeRegistry::class)->id($type);
    }

    /**
     * @return array<int, string> id => name
     */
    public static function getListTypes(): array
    {
        return app(TypeRegistry::class)->all();
    }

    /**
     * Leave behind all cached permissions. Changes made through the package do it by themselves.
     */
    public static function flush(): void
    {
        app(AccessManager::class)->flush();
    }

    /**
     * The ability of the last refusal of this request, as in version 2. Error pages use it to say
     * what exactly was refused. Access::debug() gives the whole cause, this gives the name for free.
     */
    public static function getLastDisallowPermission(): ?string
    {
        return app(Explainer::class)->lastDenied;
    }

    /**
     * The migration that version 2 published for the first user ends with this call. Removing the
     * method would break "migrate:fresh" in every project that kept that file.
     */
    #[\Deprecated('cache follows every change by itself, use AccessRules::flush() when it is really needed', '3.0.0')]
    public function clearAllCachedPermissions(): void
    {
        static::flush();
    }

    /**
     * @internal The test base class of version 2 calls it before every test.
     */
    public static function resetCacheState(): void
    {
        PermissionCache::resetState();
    }

    /**
     * Select the owner to work with.
     *
     * @param mixed $type model, record of owner, name or id of a type
     */
    public function setOwner(mixed $type, string|int|null $id = null): static
    {
        $this->access = app(AccessManager::class)->for($type, $id);

        return $this;
    }

    /**
     * Select the owner and create its record when absent.
     */
    public function newOwner(mixed $type, string|int|null $id = null, ?string $name = null): OwnerContract
    {
        return $this->setOwner($type, $id)->access()->create($name);
    }

    public function getOwner(): ?OwnerContract
    {
        return $this->access?->record();
    }

    /**
     * Permissions of the selected owner.
     *
     * @throws AccessRulesException when no owner is selected
     */
    public function access(): OwnerAccess
    {
        return $this->access ?? throw new AccessRulesException('Owner is not selected, call setOwner() or newOwner() first.', AccessRulesException::OWNER_NOT_SELECTED);
    }

    public function addPermission(string|BackedEnum $ability, string|int|null $option = null, string|Cond|array|null $when = null): bool
    {
        return $this->access()->allow($ability, $option, $when);
    }

    public function addProhibition(string|BackedEnum $ability, string|int|null $option = null, string|Cond|array|null $when = null): bool
    {
        return $this->access()->deny($ability, $option, $when);
    }

    public function remPermission(string|BackedEnum $ability, string|int|null $option = null): bool
    {
        return $this->access()->removeAllow($ability, $option);
    }

    public function remProhibition(string|BackedEnum $ability, string|int|null $option = null): bool
    {
        return $this->access()->removeDeny($ability, $option);
    }

    public function inheritFrom(mixed $type, string|int|null $id = null): bool
    {
        return $this->access()->inheritFrom($type, $id);
    }

    public function remInheritFrom(mixed $type, string|int|null $id = null): bool
    {
        return $this->access()->stopInheritingFrom($type, $id);
    }

    /**
     * @return bool|null True permitted, false prohibited, null when the package knows nothing about the ability. Version 2 returned true or null only; false is new and comes from prohibitions.
     */
    public function hasPermission(string|BackedEnum $ability, mixed $record = null): ?bool
    {
        return $this->access()->can($ability, $record);
    }

    /**
     * Same as hasPermission(). Version 2 had both names, and seeders use either.
     */
    public function can(string|BackedEnum $ability, mixed $record = null): ?bool
    {
        return $this->access()->can($ability, $record);
    }

    /**
     * Compiles from the database on every call and skips the cache: listings belong to admin
     * screens, where a stale answer confuses more than a few queries cost.
     *
     * Permissions with a condition are absent. Whether they apply depends on a record, and a
     * flat list of names cannot say that.
     *
     * @return list<string>
     */
    public function getAllPermittedRule(mixed $type = null, string|int|null $id = null): array
    {
        $access = $type === null ? $this->access() : app(AccessManager::class)->for($type, $id);

        return array_keys(app(Permissions::class)->build($access->type, $access->id)['permit']);
    }

    /**
     * @return list<string>
     */
    public function getAllProhibitedRule(mixed $type = null, string|int|null $id = null): array
    {
        $access = $type === null ? $this->access() : app(AccessManager::class)->for($type, $id);

        return array_keys(app(Permissions::class)->build($access->type, $access->id)['deny']);
    }
}

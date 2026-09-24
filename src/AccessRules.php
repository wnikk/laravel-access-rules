<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules;

use BackedEnum;
use Wnikk\LaravelAccessRules\Administration\OwnerAccess;
use Wnikk\LaravelAccessRules\Conditions\Cond;
use Wnikk\LaravelAccessRules\Contracts\AccessManager;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;
use Wnikk\LaravelAccessRules\Models\RuleOrigin;
use Wnikk\LaravelAccessRules\Protected\Administration\TypeRegistry;
use Wnikk\LaravelAccessRules\Protected\Authorization\Explainer;
use Wnikk\LaravelAccessRules\Protected\Authorization\Permissions;
use Wnikk\LaravelAccessRules\Protected\Storage\PermissionCache;

/**
 * The entry point of version 2, kept so that code written for it runs unchanged.
 *
 * Migrations and seeders of existing projects do "new AccessRules", select an owner and work
 * with it. Those files are already published into applications, and a package update cannot
 * rewrite them. So the class stays, with the same method names, as a thin shell over
 * Contracts\AccessManager.
 *
 * New code uses the manager or Facades\Access. This class remembers a selected
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
 *
 * Methods that have a form in version 3 carry the tag "deprecated" with that form. The tag is
 * PHPDoc and not the attribute #[\Deprecated]: the attribute reports at run time, and code of
 * version 2 that works must not fill the log of its project with notices on every seeder run.
 * getTypeID(), getListTypes(), getAllPermittedRule() and getAllProhibitedRule() carry no tag,
 * because version 3 has no other public way to ask for them yet.
 */
class AccessRules implements Contracts\AccessRules
{
    private ?OwnerAccess $access = null;

    /**
     * @param  string|BackedEnum|array $guardName Name of the rule. Version 2 also took all fields as one array, and published migrations use that form.
     * @param  string|null             $options   Laravel validation rules for the option, for example "required|in:1,2,3".
     * @param  string|null             $resource  Alias from config access.resources: the model that conditions of the rule talk about.
     * @param  string|Cond|array|null  $when      Condition for everybody who holds the rule.
     * @param  RuleOrigin|string|null  $origin    Where the rule comes from, see RuleOrigin. Null means code.
     * @return int|false               Id of the rule.
     *
     * @deprecated 3.0.0 Use Facades\Access::newRule(), or Contracts\AccessManager::newRule() where the manager is injected. They take the same arguments; instead of the array form pass arguments by name: Access::newRule('profile.update', options: 'required|in:name,email').
     */
    public static function newRule(string|BackedEnum|array $guardName, ?string $title = null, ?string $description = null, ?int $parentRuleID = null, ?string $options = null, ?string $resource = null, string|Cond|array|null $when = null, RuleOrigin|string|null $origin = null): int|false
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
                $guardName['origin'] ?? null,
            );
        }

        return app(AccessManager::class)->newRule($guardName, $title, $description, $parentRuleID, $options, $resource, $when, $origin);
    }

    /**
     * A rule that still has permissions is not deleted and the call throws RULE_IN_USE; $force deletes both.
     * Version 2 soft deleted here. A migration that rolls a rule back says delRule('x', true).
     *
     * @deprecated 3.0.0 Use Facades\Access::delRule($guardName, $force).
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
     *
     * @deprecated 3.0.0 Use Facades\Access::flush(), and Access::batch() to drop the cache once after many changes.
     */
    public static function flush(): void
    {
        app(AccessManager::class)->flush();
    }

    /**
     * The ability of the last refusal of this request, as in version 2. Error pages use it to say
     * what was refused. Access::debug() gives the cause; the name costs one assignment.
     *
     * @deprecated 3.1.0 Use Facades\Access::lastDenied().
     */
    public static function getLastDisallowPermission(): ?string
    {
        return app(AccessManager::class)->lastDenied();
    }

    /**
     * The migration that version 2 published for the first user ends with this call. Removing the
     * method would break "migrate:fresh" in every project that kept that file.
     */
    #[\Deprecated('cache follows every change by itself, use Access::flush() after changes made straight in the tables', '3.0.0')]
    public function clearAllCachedPermissions(): void
    {
        static::flush();
    }

    /**
     * @internal The test base class of version 2 calls it before every test. It forgets what a PHP process remembers between requests.
     */
    public static function resetCacheState(): void
    {
        PermissionCache::resetState();
        Explainer::$watching = null;
    }

    /**
     * Select the owner to work with.
     *
     * @param mixed $type model, record of owner, name or id of a type
     *
     * @deprecated 3.0.0 Use Facades\Access::for($type, $id). It returns an OwnerAccess bound to that owner for good, so there is no selected owner to forget about.
     */
    public function setOwner(mixed $type, string|int|null $id = null): static
    {
        $this->access = app(AccessManager::class)->for($type, $id);

        return $this;
    }

    /**
     * Select the owner and create its record when absent.
     *
     * @deprecated 3.0.0 Use Facades\Access::for($type, $id)->create($name).
     */
    public function newOwner(mixed $type, string|int|null $id = null, ?string $name = null): OwnerContract
    {
        return $this->setOwner($type, $id)->access()->create($name);
    }

    /**
     * @deprecated 3.0.0 Use Facades\Access::for($type, $id)->record(). A model with the trait HasPermissions has $model->getOwner().
     */
    public function getOwner(): ?OwnerContract
    {
        return $this->access?->record();
    }

    /**
     * Permissions of the selected owner.
     *
     * @throws AccessRulesException when no owner is selected
     *
     * @deprecated 3.0.0 Use Facades\Access::for($type, $id), which returns the same OwnerAccess without a selected owner in between.
     */
    public function access(): OwnerAccess
    {
        return $this->access ?? throw new AccessRulesException('Owner is not selected, call setOwner() or newOwner() first.', AccessRulesException::OWNER_NOT_SELECTED);
    }

    /**
     * @deprecated 3.0.0 Use Facades\Access::for($type, $id)->allow($ability, $option, $when). On a model with the trait HasPermissions, $model->addPermission() stays as it is.
     */
    public function addPermission(string|BackedEnum $ability, string|int|null $option = null, string|Cond|array|null $when = null): bool
    {
        return $this->access()->allow($ability, $option, $when);
    }

    /**
     * @deprecated 3.0.0 Use Facades\Access::for($type, $id)->deny($ability, $option, $when). On a model with the trait HasPermissions, $model->addProhibition() stays as it is.
     */
    public function addProhibition(string|BackedEnum $ability, string|int|null $option = null, string|Cond|array|null $when = null): bool
    {
        return $this->access()->deny($ability, $option, $when);
    }

    /**
     * @deprecated 3.0.0 Use Facades\Access::for($type, $id)->removeAllow($ability, $option).
     */
    public function remPermission(string|BackedEnum $ability, string|int|null $option = null): bool
    {
        return $this->access()->removeAllow($ability, $option);
    }

    /**
     * @deprecated 3.0.0 Use Facades\Access::for($type, $id)->removeDeny($ability, $option).
     */
    public function remProhibition(string|BackedEnum $ability, string|int|null $option = null): bool
    {
        return $this->access()->removeDeny($ability, $option);
    }

    /**
     * @deprecated 3.0.0 Use Facades\Access::for($type, $id)->inheritFrom($parentType, $parentId). On a model with the trait HasPermissions, $model->inheritPermissionFrom() stays as it is.
     */
    public function inheritFrom(mixed $type, string|int|null $id = null): bool
    {
        return $this->access()->inheritFrom($type, $id);
    }

    /**
     * @deprecated 3.0.0 Use Facades\Access::for($type, $id)->stopInheritingFrom($parentType, $parentId).
     */
    public function remInheritFrom(mixed $type, string|int|null $id = null): bool
    {
        return $this->access()->stopInheritingFrom($type, $id);
    }

    /**
     * @return bool|null True permitted, false prohibited, null when the package knows nothing about the ability. Version 2 returned true or null only; false is new and comes from prohibitions.
     *
     * @deprecated 3.0.0 Use Facades\Access::for($type, $id)->can($ability, $record). For a signed in user prefer $user->can() of Laravel, which goes through Gate.
     */
    public function hasPermission(string|BackedEnum $ability, mixed $record = null): ?bool
    {
        return $this->access()->can($ability, $record);
    }

    /**
     * Same as hasPermission(). Version 2 had both names, and seeders use either.
     *
     * @deprecated 3.0.0 Use Facades\Access::for($type, $id)->can($ability, $record).
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

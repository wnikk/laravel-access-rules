<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Traits;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Wnikk\LaravelAccessRules\Administration\OwnerAccess;
use Wnikk\LaravelAccessRules\Conditions\Cond;
use Wnikk\LaravelAccessRules\Contracts\AccessManager;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;

/**
 * Makes a model (User, Client and the like) an owner of permissions.
 * The class has to be listed in config access.owner_types.
 *
 * The trait keeps nothing in the model and does nothing when models are loaded. Version 2
 * created a service object per model instance and registered static listeners from each of
 * them. Loading a list of users then re-pointed the service of the signed in user at the
 * last user of the list, and that user's permissions answered the next check.
 *
 * Every method asks the container for the manager at the moment of the call. That costs
 * a fraction of a microsecond and makes a page that loads ten thousand users pay nothing.
 *
 * @mixin Model
 */
trait HasPermissions
{
    /**
     * "boot", not "initialize": Eloquent runs boot once per class and initialize once per
     * instance. Listeners registered per instance pile up, one set per loaded model.
     */
    public static function bootHasPermissions(): void
    {
        static::created(static function (Model $model) {
            if (config('access.auto_create_owner', true)) {
                $model->getOwner();
            }
        });

        static::deleted(static function (Model $model) {
            // A soft deleted model can come back, and it has to come back with its permissions.
            // Version 2 deleted the owner on any delete, so a restored user returned with nothing.
            if (! method_exists($model, 'isForceDeleting') || $model->isForceDeleting()) {
                $model->access()->delete();
            }
        });
    }

    /**
     * The record of the owner appears with the first change. Most users never get a permission
     * of their own, only a role, and "inherit from" must not fail because nobody created a row first.
     *
     * @throws AccessRulesException With code UNKNOWN_OWNER_TYPE, when the class is missing from config access.owner_types.
     */
    public function access(): OwnerAccess
    {
        return app(AccessManager::class)->for($this)->createdAs(fn () => $this->accessOwnerName());
    }

    public function getOwner(): OwnerContract
    {
        return $this->access()->create();
    }

    /**
     * @param string|BackedEnum      $ability Name of a rule, or "rule.option".
     * @param string|Cond|array|null $when    Condition, for example 'order.cost > 100 && order.items.count < 3'.
     *
     * @throws AccessRulesException Codes RULE_NOT_FOUND, INVALID_OPTION, DUPLICATE_PERMISSION, INVALID_CONDITION.
     */
    public function addPermission(string|BackedEnum $ability, string|int|null $option = null, string|Cond|array|null $when = null): bool
    {
        return $this->access()->allow($ability, $option, $when);
    }

    /**
     * A prohibition of the model itself beats its own permissions and everything inherited.
     *
     * @throws AccessRulesException See addPermission().
     */
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

    /**
     * Another model with this trait gets its record created here when it has none. Version 2 did
     * the same, and seeders rely on it: they link a fresh user to a fresh role in one line.
     *
     * @param mixed $type Model with this trait, AccessRules with a selected owner, a record of an owner, or a type name with $id.
     *
     * @throws AccessRulesException With code INHERITANCE_LOOP.
     */
    public function inheritPermissionFrom(mixed $type, string|int|null $id = null): bool
    {
        if ($type instanceof Model && method_exists($type, 'getOwner')) {
            $type = $type->getOwner();
        }

        return $this->access()->inheritFrom($type, $id);
    }

    public function remInheritFrom(mixed $type, string|int|null $id = null): bool
    {
        return $this->access()->stopInheritingFrom($type, $id);
    }

    /**
     * Asks the package alone. $user->can() goes through Laravel Gate, where policies get their
     * word after a null from here.
     *
     * @param  mixed     $record A record, a class name ("records in general"), or nothing.
     * @return bool|null True permitted, false prohibited, null when the package knows nothing about the ability.
     */
    public function hasPermission(string|BackedEnum $ability, mixed $record = null): ?bool
    {
        return $this->access()->can($ability, $record);
    }

    /**
     * Label of the owner in lists of an admin panel, taken from the first field that looks like
     * a name. Access never depends on it. Override the method when the model names itself differently.
     */
    protected function accessOwnerName(): ?string
    {
        $name = $this->ownerName
            ?? $this->name
            ?? $this->fullname
            ?? $this->realname
            ?? $this->login
            ?? $this->email
            ?? $this->phone
            ?? $this->getKey();

        return $name === null ? null : (string) $name;
    }
}

<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Administration;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Wnikk\LaravelAccessRules\Authorization\DecisionPoint;
use Wnikk\LaravelAccessRules\Authorization\Explainer;
use Wnikk\LaravelAccessRules\Conditions\Cond;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;

use function Illuminate\Support\enum_value;

/**
 * Access of exactly one owner: what it is permitted, what it inherits, what it may do.
 *
 * The owner cannot be changed after construction. Version 2 had one object with setOwner(),
 * and code that forgot to call it again worked with the previous owner. Another owner is
 * another object here, and readonly properties make the compiler enforce it.
 *
 * AccessManager::for() creates these objects, the HasPermissions trait and AccessRules use
 * them. The class holds no logic of its own: it fixes the address of the owner and forwards
 * to Administration\Owners for changes and to Authorization\DecisionPoint for checks.
 *
 *     $access = $manager->for('Role', 'manager');
 *     $access->allow('orders.view', when: 'order.cost > 100 && order.items.count < 3');
 *     $access->can('orders.view', $order);
 */
final class OwnerAccess
{
    /**
     * @param Model|null        $subject  Model of the owner when there is one. Conditions read "user." attributes from it; a role has none.
     * @param string|false|null $createAs Name for the record created by the first change. False means the record has to exist, which is what console commands want.
     */
    public function __construct(
        private readonly Owners $owners,
        private readonly DecisionPoint $decisions,
        public readonly int $type,
        public readonly string|int|null $id,
        private readonly ?Model $subject = null,
        private readonly string|false|null $createAs = false,
    ) {}

    /**
     * A copy that creates the record of the owner on the first change. Models use it: a user
     * that was never granted anything has no record, and "grant" must not fail on that.
     */
    public function createdAs(?string $name): self
    {
        return new self($this->owners, $this->decisions, $this->type, $this->id, $this->subject, $name);
    }

    public function record(): ?OwnerContract
    {
        return $this->owners->find($this->type, $this->id);
    }

    public function create(?string $name = null): OwnerContract
    {
        return $this->owners->findOrCreate($this->type, $this->id, $name ?? ($this->createAs ?: null));
    }

    public function delete(): bool
    {
        return $this->owners->delete($this->type, $this->id);
    }

    /**
     * @param string|BackedEnum      $ability Name of a rule, or "rule.option".
     * @param string|Cond|array|null $when    Condition, for example 'order.cost > 100 && order.items.count < 3'.
     *
     * @throws AccessRulesException See Owners::grant().
     */
    public function allow(string|BackedEnum $ability, string|int|null $option = null, string|Cond|array|null $when = null): bool
    {
        return $this->owners->grant($this->type, $this->id, enum_value($ability), self::option($option), true, $when, $this->createAs);
    }

    /**
     * A prohibition of the owner itself is the strongest statement in the package: it beats its
     * own permissions and everything inherited. Reach for it when a role permits something and
     * one owner must be the exception.
     *
     * @throws AccessRulesException See Owners::grant().
     */
    public function deny(string|BackedEnum $ability, string|int|null $option = null, string|Cond|array|null $when = null): bool
    {
        return $this->owners->grant($this->type, $this->id, enum_value($ability), self::option($option), false, $when, $this->createAs);
    }

    public function removeAllow(string|BackedEnum $ability, string|int|null $option = null): bool
    {
        return $this->owners->revoke($this->type, $this->id, enum_value($ability), self::option($option), true);
    }

    public function removeDeny(string|BackedEnum $ability, string|int|null $option = null): bool
    {
        return $this->owners->revoke($this->type, $this->id, enum_value($ability), self::option($option), false);
    }

    /**
     * This is how permissions reach many owners at once. The package has no "grant to many"
     * on purpose: a shared set of permissions is a role, and owners inherit from it.
     *
     * @param mixed $type Anything Owners::address() understands.
     *
     * @throws AccessRulesException With code INHERITANCE_LOOP.
     */
    public function inheritFrom(mixed $type, string|int|null $id = null): bool
    {
        [$parentType, $parentId] = $this->owners->address($type, $id);

        return $this->owners->inherit($this->type, $this->id, $parentType, $parentId, $this->createAs);
    }

    public function stopInheritingFrom(mixed $type, string|int|null $id = null): bool
    {
        [$parentType, $parentId] = $this->owners->address($type, $id);

        return $this->owners->disinherit($this->type, $this->id, $parentType, $parentId);
    }

    /**
     * Asks the package only. $user->can() of Laravel asks the same through Gate, where policies
     * and Gate::define() get their word after a null.
     *
     * @param  mixed     $record A record, a class name ("records in general"), or nothing. DecisionPoint::decide() explains the three cases.
     * @return bool|null True permitted, false prohibited, null when the package knows nothing about the ability.
     */
    public function can(string|BackedEnum $ability, mixed $record = null): ?bool
    {
        $decision = $this->decisions->decide($this->type, $this->id, $this->subject, enum_value($ability), Arr::wrap($record));

        // Refusals of direct checks are written down too, since many projects never go through
        // Gate. The container lookup sits behind the refusal, so a permitted check pays nothing.
        if ($decision !== true) {
            $explainer             = app(Explainer::class);
            $explainer->lastDenied = enum_value($ability);

            if ($explainer->enabled()) {
                $explainer->denied($this->type, $this->id, $this->subject, enum_value($ability), Arr::wrap($record));
            }
        }

        return $decision;
    }

    /**
     * Options are compared as text with names like "news.edit.2". Callers of version 2 pass integers.
     */
    /**
     * Why can() answers what it answers: permissions that took part, strongest first, where each
     * comes from, what conditions read, and whether the cache agrees with the database.
     * For people and for "artisan acr:explain"; several queries, never on the path of a request.
     *
     * @return array See Explainer::explain().
     */
    public function explain(string|BackedEnum $ability, mixed $record = null): array
    {
        return app(Explainer::class)->explain($this->type, $this->id, $this->subject, enum_value($ability), Arr::wrap($record));
    }

    private static function option(string|int|null $option): ?string
    {
        return $option === null ? null : (string) $option;
    }
}

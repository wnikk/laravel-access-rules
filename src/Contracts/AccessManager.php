<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Contracts;

use BackedEnum;
use Closure;
use Wnikk\LaravelAccessRules\Administration\OwnerAccess;
use Wnikk\LaravelAccessRules\Conditions\Cond;

/**
 * What application code may rely on: access of owners, rules, control over the cache.
 *
 * An interface exists for this one class because applications inject it, and their tests
 * replace it with a fake. The inner services have no interfaces on purpose. Nobody swaps
 * them, and an interface per class doubles the files without a second implementation.
 *
 * Implementations keep nothing between calls, so one instance is safe to share.
 */
interface AccessManager
{
    /**
     * Permissions of one owner.
     *
     * @param mixed           $type model, record of owner, name or id of a type from config access.owner_types
     * @param string|int|null $id   id inside of the type, when $type is a name or an id of a type
     */
    public function for(mixed $type, string|int|null $id = null): OwnerAccess;

    /**
     * @param  string|null            $options  validation rules of the option, e.g. "required|in:1,2,3"
     * @param  string|null            $resource alias of the entity from config access.resources the rule is about
     * @param  string|Cond|array|null $when     condition valid for everybody who has the rule
     * @return int|false              id of the rule
     */
    public function newRule(string|BackedEnum $guardName, ?string $title = null, ?string $description = null, ?int $parentId = null, ?string $options = null, ?string $resource = null, string|Cond|array|null $when = null): int|false;

    /**
     * Soft delete keeps permissions, so the rule can be restored with them; $force removes both for good.
     */
    public function delRule(string|BackedEnum $guardName, bool $force = false): bool;

    /**
     * Leave behind all cached permissions. Changes made through the package do it by themselves.
     */
    public function flush(): void;

    /**
     * Debug mode for the rest of the request: every refusal carries the explanation of its cause, and
     * every list narrowed by allowedTo() is written down. Meant for a support session, when an
     * administrator looks at the application as the user who complains. Config access.debug turns it
     * on for everybody, which suits a local environment and nothing else: explanations show rules
     * of other owners and values of attributes.
     */
    public function debug(bool $on = true): void;

    /**
     * What debug mode has collected during this request.
     *
     * @return array{denials:list<array>, lists:list<array>} Refusals as Explainer::explain() reports them; lists with the conditions and the SQL that narrowed them.
     */
    public function debugLog(): array;

    /**
     * Many changes in a row: cached permissions are left behind once, after $changes, and not after every change.
     *
     * @template T
     *
     * @param  Closure(): T $changes
     * @return T
     */
    public function batch(Closure $changes): mixed;
}

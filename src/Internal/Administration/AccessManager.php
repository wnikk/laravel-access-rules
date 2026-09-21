<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Internal\Administration;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Wnikk\LaravelAccessRules\Administration\OwnerAccess;
use Wnikk\LaravelAccessRules\Administration\RuleCatalog;
use Wnikk\LaravelAccessRules\Conditions\Cond;
use Wnikk\LaravelAccessRules\Contracts\AccessManager as AccessManagerContract;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;
use Wnikk\LaravelAccessRules\Internal\Authorization\DecisionPoint;
use Wnikk\LaravelAccessRules\Internal\Authorization\Explainer;
use Wnikk\LaravelAccessRules\Internal\Storage\PermissionCache;
use Wnikk\LaravelAccessRules\Models\RuleOrigin;

/**
 * Entry point of the package for application code: hands out access of owners and manages rules.
 *
 * The class keeps nothing between calls. The AccessRules object of version 2 remembered
 * a "selected owner", and an instance reused for a second owner served permissions of the
 * first one. Here for() returns a new immutable OwnerAccess on each call, which
 * rules that mistake out.
 *
 * It belongs to the administration layer and only delegates: Owners writes, RuleCatalog
 * manages rules, DecisionPoint answers. Inject Contracts\AccessManager or use Facades\Access;
 * the provider registers one instance per request.
 *
 * @internal Not part of the public API, it may change in any release. AGENTS.md lists what an application may rely on.
 */
class AccessManager implements AccessManagerContract
{
    public function __construct(
        private Owners $owners,
        private RuleCatalog $rules,
        private DecisionPoint $decisions,
        private PermissionCache $cache,
    ) {}

    /**
     * A model is remembered as the subject, so conditions can read "user." attributes from it.
     * A record of an owner is a model too, but it has no such attributes, hence the exclusion.
     */
    public function for(mixed $type, string|int|null $id = null): OwnerAccess
    {
        if ($type instanceof OwnerAccess) {
            return $type;
        }

        [$typeId, $id] = $this->owners->address($type, $id);

        return new OwnerAccess($this->owners, $this->decisions, $typeId, $id, $type instanceof Model && ! $type instanceof OwnerContract ? $type : null);
    }

    public function newRule(string|BackedEnum $guardName, ?string $title = null, ?string $description = null, ?int $parentId = null, ?string $options = null, ?string $resource = null, string|Cond|array|null $when = null, RuleOrigin|string|null $origin = null): int|false
    {
        return $this->rules->create([
            'guard_name'  => $guardName instanceof BackedEnum ? $guardName->value : $guardName,
            'title'       => $title,
            'description' => $description,
            'parent_id'   => $parentId,
            'options'     => $options,
            'resource'    => $resource,
            'when'        => $when,
            'origin'      => $origin,
        ]);
    }

    public function delRule(string|BackedEnum $guardName, bool $force = false): bool
    {
        return $this->rules->delete($guardName instanceof BackedEnum ? $guardName->value : $guardName, $force);
    }

    public function flush(): void
    {
        $this->cache->bump();
    }

    /**
     * The explainer is resolved here and not injected: it is needed by one request in a thousand.
     */
    public function debug(bool $on = true): void
    {
        app(Explainer::class)->enable($on);
    }

    public function debugLog(): array
    {
        return app(Explainer::class)->log();
    }

    public function lastDenied(): ?string
    {
        return app(Explainer::class)->lastDenied;
    }

    /**
     * Passes the connection of the rules table, so the cache repeats its drop after that
     * connection commits. All tables of the package are expected on one connection.
     */
    public function batch(Closure $changes): mixed
    {
        return $this->cache->batch($changes, app(RuleContract::class)->getConnection());
    }
}

<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Protected\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Wnikk\LaravelAccessRules\Protected\Administration\Owners;
use Wnikk\LaravelAccessRules\Protected\Administration\TypeRegistry;

/**
 * Plugs the package into Laravel Gate, so can(), @can, the "can:" middleware and
 * authorizeResource() work without a policy class per model.
 *
 * It uses Gate::before and answers "yes" or nothing at all. Null hands the decision on to
 * other Gate::before callbacks, to policies and to Gate::define(), so installing the package
 * changes no existing check. Registering abilities one by one through Gate::define() was the
 * alternative; it needs the full list of rules at boot, which means a query on every request.
 *
 * A prohibition answers null as well. The package starts from "everything is forbidden" and
 * hands out permissions; a prohibition takes a permission away and vetoes nothing in the rest
 * of the application. An earlier draft of version 3
 * returned a denial from here and named the ability from Gate::after. Both were removed:
 *   Gate stops at the first callback that answers, and package providers boot before those
 *   of the application. The usual "Gate::before(fn ($user) => $user->isAdmin() ? true : null)"
 *   would have lost to a prohibition that an administrator inherits from a role.
 *   Rules named like policy methods, "view" or "update", would have closed those abilities
 *   for every model and every policy, Nova and Filament included.
 *   An answer from Gate::after takes the place of every callback registered after it, so
 *   the message would have silenced an application that decides there.
 * A project loses the veto, which no user had requested. Version 2 behaved this way.
 *
 * The provider calls before() from a closure. The class belongs to the authorization layer and
 * holds the config repository for the same reason TypeRegistry does: the config() helper costs
 * three times more, and this code runs on every check.
 *
 * @internal Implementation of the package, not an entry point for applications. The public API is the facade Access, the traits and the classes outside src/Protected; AGENTS.md lists them.
 */
final class GateHook
{
    public function __construct(
        private DecisionPoint $decisions,
        private Explainer $explainer,
        private Owners $owners,
        private TypeRegistry $types,
        private Repository $config,
    ) {}

    /**
     * @param  Authenticatable|null $user Nullable on purpose. Gate inspects the signature and skips callbacks that cannot take a guest.
     * @return true|null            Never false, see the class.
     */
    public function before(?Authenticatable $user, string $ability, array $args = []): ?bool
    {
        $owner = $this->owner($user);
        if ($owner === null) {
            return null;
        }

        if ($this->decisions->decide($owner[0], $owner[1], $user instanceof Model ? $user : null, $ability, $args) === true) {
            return true;
        }

        // One assignment, in any mode: error pages of version 2 projects read the name of what was refused.
        $this->explainer->lastDenied = $ability;

        return null;
    }

    /**
     * A guest is checked as the one owner named in config access.guest. Signed in users do not
     * get permissions of the guest on top of theirs unless they inherit from that owner: the two
     * are different owners, and a silent merge would make "guests may not" impossible to express.
     *
     * @return array{0:int, 1:string|int|null}|null Null when the request has no owner. The package then stays silent.
     */
    public function owner(?Authenticatable $user): ?array
    {
        if ($user === null) {
            $guest = $this->config->get('access.guest');

            return $guest ? [$this->types->id($guest['type']), $guest['id'] ?? null] : null;
        }

        return $user instanceof Model ? $this->owners->addressOfModel($user) : null;
    }
}

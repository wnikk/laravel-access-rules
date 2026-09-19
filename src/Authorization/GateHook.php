<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Authorization;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Wnikk\LaravelAccessRules\Administration\Owners;
use Wnikk\LaravelAccessRules\Administration\TypeRegistry;

/**
 * Plugs the package into Laravel Gate, so can(), @can, the "can:" middleware and
 * authorizeResource() work without a policy class per model.
 *
 * It uses Gate::before and returns null whenever the package has nothing to say. Null hands
 * the decision on to policies and Gate::define(), so installing the package changes no
 * existing check. Registering abilities one by one through Gate::define() was the alternative;
 * it needs the full list of rules at boot, which means a query on every request.
 *
 * The provider calls the two public methods from closures. It belongs to the authorization
 * layer and holds the config repository for the same reason TypeRegistry does: the config()
 * helper costs three times more, and this code runs on every check.
 */
class GateHook
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
     * @return bool|Response|null   A Response for a prohibition, because Gate::before cannot say "no, and here is why" with a bare false.
     */
    public function before(?Authenticatable $user, string $ability, array $args = []): bool|Response|null
    {
        $owner = $this->owner($user);
        if ($owner === null) {
            return null;
        }

        return match ($this->decisions->decide($owner[0], $owner[1], $user instanceof Model ? $user : null, $ability, $args)) {
            true  => true,
            false => $this->config->get('access.deny_is_final', true) ? $this->denial($user, $ability, $args) : null,
            null  => null,
        };
    }

    /**
     * Runs only when nobody decided anything. Gate denies in that case anyway; this method names
     * the ability in the error, which version 2 did by remapping every AuthorizationException of
     * the application. A policy that answered false keeps its own message.
     */
    public function after(?Authenticatable $user, string $ability, mixed $result = null, array $args = []): ?Response
    {
        if ($result !== null) {
            return null;
        }

        // Written here too: with no message configured denial() is never reached.
        $this->explainer->lastDenied = $ability;

        return $this->config->get('access.denial_message') || $this->explainer->enabled() ? $this->denial($user, $ability, $args) : null;
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

    /**
     * In debug mode the message carries the explanation. The flag is looked at only here, on the
     * way to a refusal, so a permitted check never pays for the feature.
     */
    private function denial(?Authenticatable $user, string $ability, array $args): Response
    {
        $this->explainer->lastDenied = $ability;

        $message = $this->config->get('access.denial_message');
        $message = $message ? str_replace(':ability', $ability, $message) : null;

        if ($this->explainer->enabled()) {
            $owner   = $this->owner($user);
            $message = trim($message."\n".$this->explainer->denied($owner[0] ?? null, $owner[1] ?? null, $user instanceof Model ? $user : null, $ability, $args));
        }

        return Response::deny($message);
    }
}

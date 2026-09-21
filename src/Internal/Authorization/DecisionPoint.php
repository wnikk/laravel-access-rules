<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Internal\Authorization;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;
use Wnikk\LaravelAccessRules\Conditions\Context;
use Wnikk\LaravelAccessRules\Exceptions\UntranslatableConditionException;
use Wnikk\LaravelAccessRules\Internal\Conditions\Evaluation\Evaluator;
use Wnikk\LaravelAccessRules\Internal\Conditions\Evaluation\SqlCompiler;

/**
 * Answers "may this owner do that", for one record and for a whole query.
 *
 * Both answers come from the same compiled permissions and the same condition trees, and both
 * live in this one class of the authorization layer. Written as two separate paths they part
 * ways, and a list shows a record that the detail page then refuses.
 *
 * GateHook calls decide() for Laravel Gate, OwnerAccess calls it for direct checks, the
 * HasAccessScope trait calls constrain() for lists.
 *
 * The class does not know where permissions come from and never writes. It also returns
 * a plain ?bool instead of an enum: the three states map one to one onto what Gate::before
 * expects, and an enum added a translation step on the hottest path of the package.
 *
 * @internal Not part of the public API, it may change in any release. AGENTS.md lists what an application may rely on.
 */
class DecisionPoint
{
    public function __construct(private Permissions $permissions) {}

    /**
     * An ability without conditions costs one isset(). Conditions run only for abilities that
     * have them, strongest permission first, and the first one whose condition is true decides.
     *
     * The first argument of the check selects what is asked:
     *   a record   can('orders.view', $order)        conditions run against it
     *   a class    can('orders.view', Order::class)  "orders in general": menu items, list pages
     *   nothing    can('orders.view')                only permissions without a condition about a record
     *
     * With nothing to look at, a permission that depends on a record does not count. Counting it
     * would make a forgotten argument, authorize('orders.update') instead of
     * authorize('orders.update', $order), open every order to anyone who may edit one.
     *
     * @param  Model|null $subject Model of the owner, source of "user." attributes. Null for roles and guests.
     * @param  array      $args    Arguments of the check as Gate passes them. Only the first one is read.
     * @return bool|null  True permitted, false prohibited, null when the package has nothing to say and Laravel policies may decide.
     */
    #[\NoDiscard]
    public function decide(int $type, string|int|null $id, ?Model $subject, string $ability, array $args = []): ?bool
    {
        $compiled = $this->permissions->of($type, $id);

        if (! isset($compiled['cond'][$ability])) {
            return isset($compiled['permit'][$ability]) ? true : (isset($compiled['deny'][$ability]) ? false : null);
        }

        return $this->firstApplicable($compiled['cond'][$ability], $compiled, $subject, $ability, $args);
    }

    /**
     * Walks permissions of one ability, strongest first, and lets the first applicable one decide.
     *
     * Public because Explainer runs this loop with a trace. A second loop for explaining
     * would differ from this one in time, and the explanation would contradict the decision.
     * The trace costs a null check per entry when nobody asks for it.
     *
     * @param list<array{0:bool, 1:?array, 2:bool}> $entries
     * @param array                                 $facts   Compiled permissions of the owner, source of "user.roles" and "user.tenant".
     * @param array|null                            $trace   Filled with one item per entry when given: ['result' => true|false|null|'skipped'|'general'|'error'|'not reached', 'decisive' => bool, 'context' => ?Context].
     */
    public function firstApplicable(array $entries, array $facts, ?Model $subject, string $ability, array $args, ?array &$trace = null): ?bool
    {
        $record    = $args[0] ?? null;
        $hasRecord = $record instanceof Model;
        $inGeneral = is_string($record);
        $context   = null;

        foreach ($entries as $i => [$permit, $condition, $needsRecord]) {
            if ($condition === null) {
                return $this->decided($trace, $entries, $i, true, $permit);
            }

            if ($needsRecord && ! $hasRecord) {
                // Asked about records in general, a conditional permit means "yes, some of them".
                // A conditional prohibition is skipped: it hides some records, never all of them.
                if ($inGeneral && $permit) {
                    return $this->decided($trace, $entries, $i, 'general', true);
                }

                if ($trace !== null) {
                    $trace[$i] = ['result' => 'skipped', 'decisive' => false];
                }

                continue;
            }

            try {
                $context ??= new Context($subject, $record, $ability, $facts);
                $result = Evaluator::evaluate($condition, $context);
            } catch (Throwable $e) {
                // A condition that throws denies. The alternative, skipping it, turns a broken
                // prohibition into access. The exception goes to the log, the user gets a refusal.
                report($e);

                return $this->decided($trace, $entries, $i, 'error', false, $e->getMessage());
            }

            if ($result === true) {
                return $this->decided($trace, $entries, $i, true, $permit);
            }

            if ($trace !== null) {
                $trace[$i] = ['result' => $result, 'decisive' => false];
            }
        }

        return null;
    }

    /**
     * Returns the decision and, when a trace is kept, marks the entry that made it and everything after it.
     */
    private function decided(?array &$trace, array $entries, int $index, mixed $result, bool $decision, ?string $error = null): bool
    {
        if ($trace !== null) {
            $trace[$index] = ['result' => $result, 'decisive' => true, 'error' => $error];

            for ($i = $index + 1, $count = count($entries); $i < $count; $i++) {
                $trace[$i] = ['result' => 'not reached', 'decisive' => false];
            }
        }

        return $decision;
    }

    /**
     * Adds one bracketed group to WHERE and loads nothing. Filtering loaded records one by one
     * looks the same on ten rows and takes the page down on ten thousand.
     *
     * Laravel policies and Gate::define() take no part: they are PHP code and cannot become SQL.
     * A project that mixes them with conditions of this package has to filter the rest itself.
     *
     * @return array{outcome:string, sql:?string, bindings:list<mixed>} What was done: "filtered", "everything" or "nothing". Debug mode writes it down, ordinary callers ignore it.
     *
     * @throws UntranslatableConditionException When a condition applies a custom function to columns. The caller then loads the records and checks them one by one.
     */
    public function constrain(Builder $query, int $type, string|int|null $id, ?Model $subject, string $ability): array
    {
        $compiled = $this->permissions->of($type, $id);

        if (isset($compiled['cond'][$ability])) {
            [$sql, $bindings] = SqlCompiler::entries($compiled['cond'][$ability], $query, new Context($subject, null, $ability, $compiled));

            $query->whereRaw('('.$sql.')', $bindings);

            return ['outcome' => 'filtered', 'sql' => $sql, 'bindings' => $bindings];
        }

        if (isset($compiled['permit'][$ability])) {
            return ['outcome' => 'everything', 'sql' => null, 'bindings' => []];
        }

        // Prohibited or never mentioned: the list is empty.
        $query->whereRaw('0 = 1');

        return ['outcome' => 'nothing', 'sql' => '0 = 1', 'bindings' => []];
    }
}

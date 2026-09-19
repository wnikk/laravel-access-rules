<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Authorization;

use Wnikk\LaravelAccessRules\Administration\Owners;
use Wnikk\LaravelAccessRules\Administration\TypeRegistry;
use Wnikk\LaravelAccessRules\Conditions\ConditionCompiler;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;
use Wnikk\LaravelAccessRules\Contracts\Permission as PermissionContract;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;
use Wnikk\LaravelAccessRules\Storage\PermissionCache;

/**
 * Compiles everything an owner holds and inherits into arrays that answer a check with isset().
 *
 * A check happens dozens of times per request, a change of permissions a few times a day.
 * So the expensive part, walking inheritance and applying priorities, runs once when
 * permissions are compiled, and DecisionPoint only looks the answer up. Version 2 queried the
 * database on every check, which cost one query each even with a warm cache.
 *
 * Priority, from the weakest to the strongest:
 *   1. nothing is said, so not permitted
 *   2. inherited permission
 *   3. inherited prohibition
 *   4. own permission
 *   5. own prohibition
 *
 * The order is linear on purpose: one glance tells who wins. The code of version 2 let an own
 * permission beat an own prohibition. With conditions that order defeats itself: "clients
 * above 1000 of turnover" plus "not from city Y", both given to one user, showed city Y.
 *
 * The compiled form is a plain array, because the cache store serializes it on every miss
 * and a request reads it thousands of times:
 *   owner    id of the record of the owner, null when it has none
 *   roles    record ids of the owner and of everyone it inherits from
 *   tenants  application ids of inherited owners of tenant types, source of "user.tenant"
 *   permit   ability => true, permitted without conditions
 *   deny     ability => true, prohibited without conditions
 *   cond     ability => list of [permit?, condition, needs a record?], strongest first
 *
 * An ability lands in "cond" only when one of its permissions has a condition. Everything
 * else stays on the isset() path, so projects without conditions pay nothing for them.
 *
 * The class belongs to the authorization layer. DecisionPoint is its only reader on the path
 * of a check; AccessRules::getAllPermittedRule() calls build() for listings.
 */
class Permissions
{
    private const SELF_SUFFIX = '.self';

    private const OWN_DENY = 0;

    private const OWN_PERMIT = 1;

    private const INHERITED_DENY = 2;

    private const INHERITED_PERMIT = 3;

    /**
     * Compiled permissions by owner address, for the life of the request. The provider registers
     * the class as scoped, so an Octane worker does not carry them into the next request.
     *
     * @var array<string, array>
     */
    private array $memo = [];

    private int $epoch = 0;

    public function __construct(
        private Owners $owners,
        private TypeRegistry $types,
        private PermissionCache $cache,
    ) {}

    /**
     * Looks in the request memory, then in the cache store, then compiles from the database.
     *
     * @return array{owner:?int, roles:list<int>, tenants:list<int|string>, permit:array<string,true>, deny:array<string,true>, cond:array<string,list<array{0:bool,1:?array,2:bool}>>}
     */
    public function of(int $type, string|int|null $id): array
    {
        // The cache cannot reach into this memory, so it counts its switches and this class compares.
        // Without the check a request that grants a permission keeps denying it until the request ends.
        if ($this->epoch !== $this->cache->epoch) {
            $this->memo  = [];
            $this->epoch = $this->cache->epoch;
        }

        $key = $type.'.'.$id;

        return $this->memo[$key] ??= $this->cache->remember($key, fn () => $this->build($type, $id));
    }

    /**
     * Three queries at any depth of inheritance: the owner, its ancestors, permissions joined
     * with rules. A fourth one runs only when tenant types are configured.
     *
     * An owner without a record compiles to empty arrays, and that result is cached as well.
     * Most users of a system hold nothing of their own, and a miss that is never stored would
     * send each of them to the database on every request.
     */
    public function build(int $type, string|int|null $id): array
    {
        $compiled = ['owner' => null, 'roles' => [], 'tenants' => [], 'permit' => [], 'deny' => [], 'cond' => []];

        $owner = $this->owners->find($type, $id);
        if (! $owner) {
            return $compiled;
        }

        $ownId = (int) $owner->getKey();
        $roles = [$ownId, ...array_diff($this->owners->ancestors($owner), [$ownId])];

        foreach ($this->entries($ownId, $roles) as $ability => $entries) {
            $entries = self::reachable($entries);

            if (count($entries) === 1 && $entries[0][1] === null) {
                $compiled[$entries[0][0] ? 'permit' : 'deny'][$ability] = true;
            } else {
                $compiled['cond'][$ability] = $entries;
            }
        }

        $compiled['owner']   = $ownId;
        $compiled['roles']   = array_values($roles);
        $compiled['tenants'] = $this->tenants($roles, $ownId);

        return $compiled;
    }

    /**
     * Every permission that takes part in the decision about one ability, strongest first, straight
     * from the database, with the place it comes from. Nothing is cut and nothing is cached: this is
     * for Explainer, and an explanation built from the cache could only repeat what the cache believes.
     *
     * @return list<array{0:bool, 1:?array, 2:bool, 3:array{owner:int, own:bool, rule:string, option:?string, via:string}}>
     */
    public function trace(int $type, string|int|null $id, string $ability): array
    {
        $owner = $this->owners->find($type, $id);
        if (! $owner) {
            return [];
        }

        $ownId = (int) $owner->getKey();
        $roles = [$ownId, ...array_diff($this->owners->ancestors($owner), [$ownId])];

        return $this->entries($ownId, $roles, true)[$ability] ?? [];
    }

    /**
     * Entries after the first one without a condition can never be reached: that one always applies.
     * Dropping them keeps compiled permissions small and the walk of DecisionPoint short.
     */
    private static function reachable(array $entries): array
    {
        foreach ($entries as $i => $entry) {
            if ($entry[1] === null) {
                return array_slice($entries, 0, $i + 1);
            }
        }

        return $entries;
    }

    /**
     * Collects permissions of every ability into the four steps of priority and flattens them, strongest first.
     *
     * @param  bool                                                 $withOrigin Adds to every entry where it comes from. Only trace() asks for it, compiled permissions stay lean.
     * @return array<string, list<array{0:bool, 1:?array, 2:bool}>>
     */
    private function entries(int $ownId, array $roles, bool $withOrigin = false): array
    {
        $permissions = app(PermissionContract::class);
        $rules       = app(RuleContract::class);

        $pTable = $permissions->getTable();
        $rTable = $rules->getTable();

        $rows = $permissions->getConnection()->table($pTable)
            ->join($rTable, $rTable.'.id', '=', $pTable.'.rule_id')
            ->whereIn($pTable.'.owner_id', $roles)
            ->whereNull($rTable.'.deleted_at')
            ->orderBy($pTable.'.id')
            ->get([
                $pTable.'.owner_id', $pTable.'.permission', $pTable.'.option', $pTable.'.condition',
                $rTable.'.id as rule_id', $rTable.'.guard_name', $rTable.'.condition as rule_condition',
            ]);

        $below = config('access.rule_tree_inheritance') && $rows->isNotEmpty() ? $this->rulesBelow($rules) : [];

        $tiers = [];
        $add   = function (int $tier, string $ability, ?array $condition, ?array $origin = null) use (&$tiers) {
            $tiers[$ability][$tier][] = [$condition, $origin];

            // "posts.update.self" is "posts.update" for the author of the record. The entry takes the
            // step of the permission it comes from. Version 2 let the author through even when
            // "posts.update" was prohibited; an own prohibition now beats it like everything else.
            if (str_ends_with($ability, self::SELF_SUFFIX)) {
                $tiers[substr($ability, 0, -strlen(self::SELF_SUFFIX))][$tier][] = [self::both($condition, ['is-author']), $origin === null ? null : ['via' => 'self'] + $origin];
            }
        };

        foreach ($rows as $row) {
            $own       = (int) $row->owner_id === $ownId;
            $tier      = $row->permission ? ($own ? self::OWN_PERMIT : self::INHERITED_PERMIT) : ($own ? self::OWN_DENY : self::INHERITED_DENY);
            $suffix    = $row->option === null || $row->option === '' ? '' : '.'.$row->option;
            $condition = self::decode($row->condition);

            $origin = $withOrigin ? ['owner' => (int) $row->owner_id, 'own' => $own, 'rule' => $row->guard_name, 'option' => $row->option, 'via' => 'direct'] : null;

            $add($tier, $row->guard_name.$suffix, self::both(self::decode($row->rule_condition), $condition), $origin);

            // A statement about a rule also covers the rules below it, but only with the strength of
            // something inherited. Otherwise an own permission for "reports" could never be narrowed
            // by an own prohibition for "reports.sales": both would sit on the same step.
            foreach ($below[$row->rule_id] ?? [] as [$guardName, $ruleCondition]) {
                $add($row->permission ? self::INHERITED_PERMIT : self::INHERITED_DENY, $guardName.$suffix, self::both($ruleCondition, $condition), $origin === null ? null : ['via' => 'tree'] + $origin);
            }
        }

        $entries = [];
        foreach ($tiers as $ability => $byTier) {
            ksort($byTier);

            foreach ($byTier as $tier => $items) {
                foreach ($items as [$condition, $origin]) {
                    $entry = [$tier === self::OWN_PERMIT || $tier === self::INHERITED_PERMIT, $condition, ConditionCompiler::needsRecord($condition)];

                    $entries[$ability][] = $origin === null ? $entry : [...$entry, $origin];
                }
            }
        }

        return $entries;
    }

    /**
     * Loads the whole table of rules in one query and walks it in memory. The table holds
     * hundreds of rows, not millions, and one query beats a recursive one per granted rule.
     * The $seen set guards against a loop in parent_id, which the schema does not forbid.
     *
     * @return array<int, list<array{0:string, 1:?array}>> Rule id => every rule below it as [name, condition of that rule].
     */
    private function rulesBelow(RuleContract $rules): array
    {
        $all = $rules->getConnection()->table($rules->getTable())
            ->whereNull('deleted_at')
            ->get(['id', 'parent_id', 'guard_name', 'condition']);

        $children = [];
        foreach ($all as $rule) {
            $children[(int) $rule->parent_id][] = $rule;
        }

        $collect = function (int $id, array $seen) use (&$collect, $children): array {
            $found = [];
            foreach ($children[$id] ?? [] as $child) {
                if (! isset($seen[$child->id])) {
                    $found[] = [$child->guard_name, self::decode($child->condition)];
                    $found   = [...$found, ...$collect((int) $child->id, $seen + [$child->id => true])];
                }
            }

            return $found;
        };

        $below = [];
        foreach ($all as $rule) {
            if (isset($children[(int) $rule->id])) {
                $below[(int) $rule->id] = $collect((int) $rule->id, [$rule->id => true]);
            }
        }

        return $below;
    }

    /**
     * A tenant is an inherited owner whose type is listed in config access.tenant_types, so
     * membership in a team is ordinary inheritance and needs no tables of its own.
     *
     * Ids that look like numbers become integers. They end up in "team_id in (...)" next to
     * an integer column, and a text column keeps them as text.
     */
    private function tenants(array $roles, int $ownId): array
    {
        $types = array_map(fn ($name) => $this->types->id($name), config('access.tenant_types') ?? []);
        $ids   = array_values(array_diff($roles, [$ownId]));

        if ($types === [] || $ids === []) {
            return [];
        }

        return app(OwnerContract::class)->newQuery()->whereIn('id', $ids)->whereIn('type', $types)->pluck('original_id')
            ->map(static fn ($id) => is_string($id) && ctype_digit($id) ? (int) $id : $id)
            ->values()->all();
    }

    /**
     * The query builder returns JSON columns as text, Eloquent casts are not involved here.
     */
    private static function decode(mixed $condition): ?array
    {
        if ($condition === null || $condition === '') {
            return null;
        }

        return is_array($condition) ? $condition : json_decode($condition, true);
    }

    private static function both(?array $a, ?array $b): ?array
    {
        return $a === null ? $b : ($b === null ? $a : ['and', $a, $b]);
    }
}

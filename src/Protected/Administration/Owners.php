<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Protected\Administration;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Wnikk\LaravelAccessRules\AccessRules;
use Wnikk\LaravelAccessRules\Administration\OwnerAccess;
use Wnikk\LaravelAccessRules\Administration\RuleCatalog;
use Wnikk\LaravelAccessRules\Conditions\Cond;
use Wnikk\LaravelAccessRules\Contracts\Inheritance as InheritanceContract;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;
use Wnikk\LaravelAccessRules\Contracts\Permission as PermissionContract;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;
use Wnikk\LaravelAccessRules\Events\AccessChanged;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;
use Wnikk\LaravelAccessRules\Protected\Conditions\ConditionCompiler;
use Wnikk\LaravelAccessRules\Protected\Storage\HierarchyQuery;
use Wnikk\LaravelAccessRules\Protected\Storage\PermissionCache;

/**
 * Writes who holds what: records of owners, their permissions and prohibitions, their inheritance.
 *
 * Every change of access goes through this class of the administration layer, whether it
 * comes from a model trait, from OwnerAccess, from a console command or from a future admin
 * panel. One entry keeps one order of steps: write the row, drop cached permissions, announce
 * the change. Version 2 dropped the cache first, and a request that slipped in between cached
 * the old rows for a day.
 *
 * An owner is addressed by two plain values, the numeric type and the id inside of the type.
 * A value object for the pair was tried and removed: it carried two fields and one string
 * join, and every signature in the package paid for it with an import.
 *
 * Reading for a permission check does not belong here. Authorization\Permissions compiles
 * what this class writes, and nothing on the path of a check touches these tables.
 *
 * @internal Implementation of the package, not an entry point for applications. The public API is the facade Access, the traits and the classes outside src/Protected; AGENTS.md lists them.
 */
final class Owners
{
    public function __construct(
        private TypeRegistry $types,
        private RuleCatalog $rules,
        private ConditionCompiler $conditions,
        private HierarchyQuery $hierarchy,
        private PermissionCache $cache,
        private Dispatcher $events,
    ) {}

    /**
     * Accepts everything callers hold in practice: a model, a record of an owner, an
     * OwnerAccess, the AccessRules object of version 2, or a type name with an id. Migrations
     * written for version 2 pass all of these, so narrowing the argument breaks them.
     *
     * @return array{0:int, 1:string|int|null} Type and id. The record may not exist yet, no query runs here.
     *
     * @throws AccessRulesException With code UNKNOWN_OWNER_TYPE, when the type is missing from config access.owner_types.
     */
    public function address(mixed $type, mixed $id = null): array
    {
        if ($type instanceof OwnerContract) {
            return [(int) $type->type, $type->original_id];
        }

        if ($type instanceof OwnerAccess) {
            return [$type->type, $type->id];
        }

        if ($type instanceof AccessRules) {
            return $this->address($type->access());
        }

        if ($type instanceof Model) {
            return $id === null
                ? $this->addressOfModel($type) ?? [$this->types->id($type::class), $type->getKey()]
                : [$this->types->idOfModel($type) ?? $this->types->id($type::class), $id];
        }

        return [$this->types->id($type), $id];
    }

    /**
     * Reads the key from raw attributes, not through getKey(). This method sits on the path of
     * every check, and getKey() walks casts and accessors for 0.9 microseconds where the array
     * read takes 0.05. A model whose key lives behind an accessor still works through the fallback.
     *
     * @return array{0:int, 1:string|int|null}|null Null when the class is not an owner type. Gate then leaves the decision to regular policies.
     */
    public function addressOfModel(Model $model): ?array
    {
        $type = $this->types->idOfModel($model);

        return $type === null ? null : [$type, $model->getAttributes()[$model->getKeyName()] ?? $model->getKey()];
    }

    /**
     * The id goes to the query as a string. The column is text, because owners include UUIDs and
     * ids of external systems, and PostgreSQL refuses to compare text with an integer parameter.
     */
    public function find(int $type, string|int|null $id): ?OwnerContract
    {
        return app(OwnerContract::class)->newQuery()
            ->where('type', $type)
            ->where('original_id', $id === null ? null : (string) $id)
            ->first();
    }

    /**
     * @param string|null $name Shown in lists of owners and nowhere else. Access never depends on it.
     */
    public function findOrCreate(int $type, string|int|null $id, ?string $name = null): OwnerContract
    {
        $owner = $this->find($type, $id);
        if ($owner) {
            return $owner;
        }

        $owner              = app(OwnerContract::class);
        $owner->type        = $type;
        $owner->original_id = $id === null ? null : (string) $id;
        $owner->name        = $name;
        $owner->save();

        return $owner;
    }

    /**
     * Deletes links in both directions by hand. Tables created by version 2 have a cascade only
     * from the inheriting side, and SQLite cannot add the missing foreign key to an existing table,
     * so relying on the database leaves heirs pointing at a record that is gone.
     */
    public function delete(int $type, string|int|null $id): bool
    {
        $owner = $this->find($type, $id);
        if (! $owner) {
            return false;
        }

        $owner->permission()->delete();
        $owner->inheritance()->delete();
        $owner->inheritanceParent()->delete();
        $owner->delete();

        $this->changed(AccessChanged::OWNER_DELETED, $owner, ['owner_type' => $type, 'owner_id' => $id]);

        return true;
    }

    /**
     * A permission and a prohibition are the same row with a different flag, so one method writes both.
     *
     * The duplicate check reads before it writes and can lose a race. The unique index of the
     * table catches what slips through, except for rows without an option: NULL is distinct in
     * a unique index. Two such rows compile to one permission, so the race costs a redundant row,
     * not a wrong answer.
     *
     * @param string                 $ability  Name of a rule, or "rule.option". The last segment becomes the option when no rule carries the full name.
     * @param bool                   $permit   True for a permission, false for a prohibition.
     * @param string|Cond|array|null $when     Condition. It is parsed and checked against models now, so a mistake surfaces here and not during a check.
     * @param string|false|null      $createAs Name for the record of the owner created when absent. False means the record has to exist: console commands must not invent owners from a typo.
     *
     * @throws AccessRulesException Codes OWNER_NOT_FOUND, RULE_NOT_FOUND, INVALID_OPTION, DUPLICATE_PERMISSION, INVALID_CONDITION.
     */
    public function grant(int $type, string|int|null $id, string $ability, ?string $option, bool $permit, string|Cond|array|null $when = null, string|false|null $createAs = false): bool
    {
        $owner = $createAs === false ? $this->find($type, $id) : $this->findOrCreate($type, $id, $createAs);
        if (! $owner) {
            throw new AccessRulesException('Owner not find in the database. Before adding a permission, add owner to DB.', AccessRulesException::OWNER_NOT_FOUND);
        }

        $rule = $this->rules->resolve($ability, $option);
        if (! $rule) {
            throw new AccessRulesException('Rule "'.$ability.'" is absent in the database. Before adding a permission, add rule to DB.', AccessRulesException::RULE_NOT_FOUND);
        }

        $option = $option === '' ? null : $option;

        if ($owner->permission()->where('rule_id', $rule->getKey())->where('option', $option)->where('permission', $permit)->exists()) {
            throw new AccessRulesException('Rule "'.$rule->guard_name.'" has already been previously added to the owner.', AccessRulesException::DUPLICATE_PERMISSION);
        }

        $permission             = app(PermissionContract::class);
        $permission->owner_id   = $owner->getKey();
        $permission->rule_id    = $rule->getKey();
        $permission->permission = $permit;
        $permission->option     = $option;
        $permission->condition  = $this->conditions->compile($when, $rule->resource);
        $saved                  = $permission->save();

        $this->changed(AccessChanged::PERMISSION_GRANTED, $permission, [
            'owner_type' => $type, 'owner_id' => $id, 'rule' => $rule->guard_name, 'option' => $option,
            'permit'     => $permit, 'condition' => $permission->condition,
        ]);

        return $saved;
    }

    /**
     * @return bool False when there was nothing to take away. The cache is dropped either way: a false alarm costs one rebuild, a missed change costs a day of wrong answers.
     */
    public function revoke(int $type, string|int|null $id, string $ability, ?string $option, bool $permit): bool
    {
        $owner = $this->find($type, $id);
        $rule  = $owner ? $this->rules->resolve($ability, $option) : null;
        if (! $owner || ! $rule) {
            return false;
        }

        $option  = $option === '' ? null : $option;
        $deleted = $owner->permission()->where('rule_id', $rule->getKey())->where('option', $option)->where('permission', $permit)->delete();

        $this->changed(AccessChanged::PERMISSION_REVOKED, $owner, [
            'owner_type' => $type, 'owner_id' => $id, 'rule' => $rule->guard_name, 'option' => $option, 'permit' => $permit,
        ]);

        return (bool) $deleted;
    }

    /**
     * Refuses a link that closes a loop. Compiling survives loops, but a loop means two owners
     * inherit from each other, and then a prohibition given to one of them applies to both
     * as "inherited" and the priority order stops meaning anything.
     *
     * @param  string|false|null $createAs See grant().
     * @return bool              False when one of the owners has no record. True when the link already exists.
     *
     * @throws AccessRulesException With code INHERITANCE_LOOP.
     */
    public function inherit(int $type, string|int|null $id, int $parentType, string|int|null $parentId, string|false|null $createAs = false): bool
    {
        $owner  = $createAs === false ? $this->find($type, $id) : $this->findOrCreate($type, $id, $createAs);
        $parent = $this->find($parentType, $parentId);
        if (! $owner || ! $parent) {
            return false;
        }

        if ($owner->inheritance()->where('owner_parent_id', $parent->getKey())->exists()) {
            return true;
        }

        if ($owner->getKey() === $parent->getKey() || in_array($owner->getKey(), $this->ancestors($parent), false)) {
            throw new AccessRulesException('Inheritance would make a loop: "'.$parent->name.'" already inherits from "'.$owner->name.'".', AccessRulesException::INHERITANCE_LOOP);
        }

        $link                  = app(InheritanceContract::class);
        $link->owner_id        = $owner->getKey();
        $link->owner_parent_id = $parent->getKey();
        $saved                 = $link->save();

        $this->changed(AccessChanged::INHERITANCE_ADDED, $link, [
            'owner_type' => $type, 'owner_id' => $id, 'parent_type' => $parentType, 'parent_id' => $parentId,
        ]);

        return $saved;
    }

    public function disinherit(int $type, string|int|null $id, int $parentType, string|int|null $parentId): bool
    {
        $owner  = $this->find($type, $id);
        $parent = $this->find($parentType, $parentId);
        if (! $owner || ! $parent) {
            return false;
        }

        $deleted = $owner->inheritance()->where('owner_parent_id', $parent->getKey())->delete();

        $this->changed(AccessChanged::INHERITANCE_REMOVED, $owner, [
            'owner_type' => $type, 'owner_id' => $id, 'parent_type' => $parentType, 'parent_id' => $parentId,
        ]);

        return (bool) $deleted;
    }

    /**
     * @return list<int> Ids of records, not addresses. They feed a whereIn over permissions, which is keyed by record id.
     */
    public function ancestors(OwnerContract $owner): array
    {
        return $this->walk($owner, 'owner_id', 'owner_parent_id');
    }

    /**
     * Answers "who is affected if I change this role". Nothing in a permission check needs it,
     * it exists for admin panels and for the loop check of inherit().
     *
     * @return list<int> Ids of records.
     */
    public function heirs(OwnerContract $owner): array
    {
        return $this->walk($owner, 'owner_parent_id', 'owner_id');
    }

    private function walk(OwnerContract $owner, string $from, string $to): array
    {
        $links = app(InheritanceContract::class);

        return array_map('intval', $this->hierarchy->reachable($links->getConnection(), $links->getTable(), $from, $to, [$owner->getKey()]));
    }

    /**
     * Every permission row that reaches an owner, its own and those of everything it inherits
     * from, as data for an admin panel or a console: the rule, the option, the effect, the
     * condition as text, whether the row is the owner's own and whom it comes from. Rows are
     * grouped by rule and ordered strongest first inside a rule, by the five steps of the core.
     *
     * The compiled set of a check keeps none of this: it folds every row into one answer per
     * ability. So this reads the tables, four queries whatever the depth of inheritance, and is
     * for screens and commands, never for the path of a check.
     *
     * With config rule_tree_inheritance on, a row on a rule also reaches every rule below it in
     * the tree at the same step; such a row is listed under the rule it reaches with "via" set to
     * "tree" and "via_rule" naming the rule it sits on.
     *
     * @return list<array{rule:string, rule_id:int, option:?string, effect:string, when:?string, own:bool, from:array{type:string, id:string, name:?string, record:int}, via:?string, via_rule:?string}>
     */
    public function rowsOf(OwnerContract $owner): array
    {
        $ownId   = (int) $owner->getKey();
        $sources = [$ownId, ...$this->ancestors($owner)];
        $names   = $this->present(app(OwnerContract::class)->newQuery()->whereIn('id', $sources)->get());

        $rows = app(PermissionContract::class)->newQuery()->with('rule')->whereIn('owner_id', $sources)->get();

        $byRule = [];
        foreach ($rows as $row) {
            if ($row->rule === null || ! isset($names[(int) $row->owner_id])) {
                continue;
            }

            $byRule[(int) $row->rule_id][] = [
                'rule'     => $row->rule->guard_name,
                'rule_id'  => (int) $row->rule_id,
                'option'   => $row->option,
                'effect'   => $row->permission ? 'allow' : 'deny',
                'when'     => $this->conditions->describe($row->condition, $row->rule->resource),
                'own'      => (int) $row->owner_id === $ownId,
                'from'     => $names[(int) $row->owner_id],
                'via'      => null,
                'via_rule' => null,
            ];
        }

        if (config('access.rule_tree_inheritance') && $byRule !== []) {
            $rules   = app(RuleContract::class)->newQuery()->get(['id', 'parent_id', 'guard_name']);
            $parents = $rules->pluck('parent_id', 'id')->map(static fn ($id): int => (int) $id)->all();
            $labels  = $rules->pluck('guard_name', 'id')->all();

            foreach ($rules as $rule) {
                $ruleId = (int) $rule->id;
                for ($above = $parents[$ruleId], $seen = [$ruleId => true]; $above > 0 && ! isset($seen[$above]); $above = $parents[$above] ?? 0) {
                    $seen[$above] = true;
                    foreach ($byRule[$above] ?? [] as $entry) {
                        if ($entry['via'] === null) {
                            $byRule[$ruleId][] = ['via' => 'tree', 'via_rule' => $labels[$above] ?? null, 'rule' => $labels[$ruleId] ?? '', 'rule_id' => $ruleId] + $entry;
                        }
                    }
                }
            }
        }

        $strength = static fn (array $e): int => ($e['own'] ? 2 : 0) + ($e['effect'] === 'deny' ? 1 : 0);
        $out      = [];
        foreach ($byRule as $entries) {
            usort($entries, static fn (array $a, array $b): int => $strength($b) <=> $strength($a));
            $out = [...$out, ...$entries];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['rule'], $b['rule']) ?: ($strength($b) <=> $strength($a)));

        return $out;
    }

    /**
     * Whom an owner inherits from, at any depth, with the direct link each one came through.
     *
     * @return list<array{type:string, id:string, name:?string, record:int, direct:bool, link:?int, through:?int}>
     */
    public function sourcesOf(OwnerContract $owner): array
    {
        return $this->relatives($owner, 'owner_id', 'owner_parent_id');
    }

    /**
     * Who inherits from an owner, at any depth: everyone a change of it reaches.
     *
     * @return list<array{type:string, id:string, name:?string, record:int, direct:bool, link:?int, through:?int}>
     */
    public function heirsOf(OwnerContract $owner): array
    {
        return $this->relatives($owner, 'owner_parent_id', 'owner_id');
    }

    /**
     * The reachable set comes from one recursive query; the links among that set from one more,
     * walked breadth first in memory so the recorded route is the shortest. "through" names the
     * direct relative an indirect one is reached by: the link to change when it should go.
     */
    private function relatives(OwnerContract $owner, string $from, string $to): array
    {
        $ownId   = (int) $owner->getKey();
        $reached = $this->walk($owner, $from, $to);
        if ($reached === []) {
            return [];
        }

        $edges = [];
        $links = [];
        foreach (app(InheritanceContract::class)->newQuery()->whereIn($from, [$ownId, ...$reached])->whereIn($to, $reached)->get() as $link) {
            $edges[(int) $link->{$from}][] = (int) $link->{$to};
            if ((int) $link->{$from} === $ownId) {
                $links[(int) $link->{$to}] = (int) $link->getKey();
            }
        }

        $through = [];
        $queue   = [];
        foreach ($edges[$ownId] ?? [] as $direct) {
            $through[$direct] = $direct;
            $queue[]          = $direct;
        }
        while ($queue !== []) {
            $at = array_shift($queue);
            foreach ($edges[$at] ?? [] as $next) {
                if ($next !== $ownId && ! isset($through[$next])) {
                    $through[$next] = $through[$at];
                    $queue[]        = $next;
                }
            }
        }

        $out = [];
        foreach ($this->present(app(OwnerContract::class)->newQuery()->whereIn('id', $reached)->orderBy('name')->orderBy('id')->get()) as $id => $data) {
            $out[] = $data + ['direct' => isset($links[$id]), 'link' => $links[$id] ?? null, 'through' => isset($links[$id]) ? null : ($through[$id] ?? null)];
        }

        return $out;
    }

    /**
     * @return array<int, array{type:string, id:string, name:?string, record:int}> By record id. Owners of a type that left config are left out: they hold nothing a check sees.
     */
    private function present(iterable $owners): array
    {
        $out = [];
        foreach ($owners as $owner) {
            $type = $this->types->name((int) $owner->type);
            if ($type !== null) {
                $out[(int) $owner->getKey()] = ['type' => $type, 'id' => (string) $owner->original_id, 'name' => $owner->name, 'record' => (int) $owner->getKey()];
            }
        }

        return $out;
    }

    /**
     * Cache first, listeners second. A listener that checks a permission, for example to decide
     * whom to notify, must already see the new state.
     *
     * The package keeps no audit log of its own. AccessChanged carries enough to write one, and
     * what to store and for how long is a decision of the application.
     *
     * @param Model $written Any model of the change. Only its connection matters: the cache repeats the drop after that connection commits.
     */
    private function changed(string $action, Model $written, array $details): void
    {
        $this->cache->bump($written->getConnection());
        $this->events->dispatch(new AccessChanged($action, $details));
    }
}

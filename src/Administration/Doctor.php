<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Administration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Wnikk\LaravelAccessRules\Contracts\Inheritance as InheritanceContract;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;
use Wnikk\LaravelAccessRules\Contracts\Permission as PermissionContract;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;
use Wnikk\LaravelAccessRules\Protected\Administration\TypeRegistry;
use Wnikk\LaravelAccessRules\Protected\Storage\PermissionCache;

/**
 * Checks the rows of the package against each other: what a database can hold that the package
 * would never write.
 *
 * Linter reads conditions against the models of today; this reads nothing but the four tables.
 * Every write of the package keeps them consistent, and every row this finds came from somewhere
 * else: SQL typed by hand, a restore of a partial dump, a migration of 2.x that ran twice, an
 * SQLite file with foreign keys off. Such rows do no harm to a check, which skips what it cannot
 * join, but an import counts them, an export lists them, and a duplicate that survives a delete
 * keeps the permission the delete was meant to take away.
 *
 * "artisan acr:doctor" prints what this class returns and adds nothing.
 */
final class Doctor
{
    public const DUPLICATE_PERMISSION = 'duplicate';

    public const RULE_MISSING = 'rule_missing';

    public const OWNER_MISSING = 'owner_missing';

    public const INHERITANCE_LOOP = 'loop';

    public function __construct(
        private TypeRegistry $types,
        private PermissionCache $cache,
    ) {}

    /**
     * @param bool $fix Delete the rows that point at nothing and every copy of a duplicate past the first. A loop needs a person: there is no telling which link is the wrong one.
     * @return array{
     *     problems:list<array{code:string, subject:string, id:int|string|null, where:string, problem:string}>,
     *     fixed:int
     * } "code" is one of the constants of this class. "subject" is "permission" or "inheritance", and "id" a key in that table, so a panel can link to it. "fixed" counts deleted rows.
     */
    public function run(bool $fix = false): array
    {
        $problems = [];
        $fixed    = 0;

        $permissions = app(PermissionContract::class)->newQuery()->getQuery();
        $links       = app(InheritanceContract::class)->newQuery()->getQuery();
        $rulesTable  = app(RuleContract::class)->getTable();
        $ownersTable = app(OwnerContract::class)->getTable();

        // ---- duplicates: the unique index does not see two NULL options as one value

        $groups = (clone $permissions)
            ->select(['owner_id', 'rule_id', 'option', 'permission'])->selectRaw('min(id) as kept, count(*) as copies')
            ->groupBy('owner_id', 'rule_id', 'option', 'permission')->havingRaw('count(*) > 1')->orderBy('kept')->get();

        foreach ($groups as $group) {
            $where = $this->permissionLabel((int) $group->kept);
            if ($fix) {
                $fixed += (clone $permissions)->where('owner_id', $group->owner_id)->where('rule_id', $group->rule_id)
                    ->where('option', $group->option)->where('permission', $group->permission)->where('id', '<>', $group->kept)->delete();

                continue;
            }
            $problems[] = ['code' => self::DUPLICATE_PERMISSION, 'subject' => 'permission', 'id' => (int) $group->kept, 'where' => $where,
                'problem'         => 'is stored '.$group->copies.' times; a delete of one copy leaves the others working'];
        }

        // ---- permissions and links that point at rows that are not there

        $orphans = [
            [self::RULE_MISSING, 'permission', (clone $permissions)->whereNotIn('rule_id', static fn (Builder $q) => $q->select('id')->from($rulesTable)), 'rule_id', 'rule'],
            [self::OWNER_MISSING, 'permission', (clone $permissions)->whereNotIn('owner_id', static fn (Builder $q) => $q->select('id')->from($ownersTable)), 'owner_id', 'owner'],
            [self::OWNER_MISSING, 'inheritance', (clone $links)->whereNotIn('owner_id', static fn (Builder $q) => $q->select('id')->from($ownersTable)), 'owner_id', 'owner'],
            [self::OWNER_MISSING, 'inheritance', (clone $links)->whereNotIn('owner_parent_id', static fn (Builder $q) => $q->select('id')->from($ownersTable)), 'owner_parent_id', 'owner'],
        ];

        foreach ($orphans as [$code, $subject, $query, $column, $what]) {
            foreach ($query->orderBy('id')->get() as $row) {
                if ($fix) {
                    $fixed += (clone $query)->where('id', $row->id)->delete();

                    continue;
                }
                $problems[] = ['code' => $code, 'subject' => $subject, 'id' => (int) $row->id, 'where' => $subject.' #'.$row->id,
                    'problem'         => 'points at '.$what.' #'.$row->{$column}.' that does not exist: the row was left behind by a delete past the package'];
            }
        }

        // ---- loops: inherit() refuses them, so one here was written past the package

        foreach ($this->loops((clone $links)->orderBy('id')->get(['id', 'owner_id', 'owner_parent_id'])) as $loop) {
            $names = array_map(fn (int $id) => $this->ownerLabel($id), [...$loop['owners'], $loop['owners'][0]]);

            $problems[] = ['code' => self::INHERITANCE_LOOP, 'subject' => 'inheritance', 'id' => $loop['link'], 'where' => 'inheritance #'.$loop['link'],
                'problem'         => 'closes a loop: '.implode(' inherits from ', $names).'; a prohibition of any of them reaches all of them as inherited'];
        }

        if ($fixed > 0) {
            // Rows are deleted past Owners, which is what normally drops the cache.
            $this->cache->bump();
        }

        return ['problems' => $problems, 'fixed' => $fixed];
    }

    /**
     * Every cycle among the links, once, as the owners along it and the link that closes it.
     * A depth-first walk over the whole table in memory: the table holds one row per role
     * assignment, and the walk touches every row once.
     *
     * @param  iterable<object{id:int|string, owner_id:int|string, owner_parent_id:int|string}> $links
     * @return list<array{owners:list<int>, link:int}>
     */
    private function loops(iterable $links): array
    {
        $parents = [];
        foreach ($links as $link) {
            $parents[(int) $link->owner_id][] = [(int) $link->owner_parent_id, (int) $link->id];
        }

        $found = [];
        $done  = [];
        $path  = [];

        $walk = function (int $owner) use (&$walk, &$found, &$done, &$path, $parents): void {
            $path[$owner] = count($path);
            foreach ($parents[$owner] ?? [] as [$parent, $link]) {
                if (isset($path[$parent])) {
                    $found[] = ['owners' => array_slice(array_keys($path), $path[$parent]), 'link' => $link];
                } elseif (! isset($done[$parent])) {
                    $walk($parent);
                }
            }
            unset($path[$owner]);
            $done[$owner] = true;
        };

        foreach (array_keys($parents) as $owner) {
            if (! isset($done[$owner])) {
                $walk($owner);
            }
        }

        return $found;
    }

    private function permissionLabel(int $id): string
    {
        $permission = app(PermissionContract::class)->newQuery()->with(['rule', 'owner'])->find($id);
        if ($permission === null) {
            return 'permission #'.$id;
        }

        return ($permission->permission ? 'permission' : 'prohibition').' #'.$id
            .' of '.($permission->owner === null ? 'owner #'.$permission->owner_id : $this->ownerLabel((int) $permission->owner_id, $permission->owner))
            .' for '.($permission->rule->guard_name ?? 'rule #'.$permission->rule_id)
            .($permission->option === null || $permission->option === '' ? '' : ' ('.$permission->option.')');
    }

    private function ownerLabel(int $id, Model|OwnerContract|null $owner = null): string
    {
        $owner ??= app(OwnerContract::class)->newQuery()->find($id);
        if ($owner === null) {
            return 'owner #'.$id;
        }

        return class_basename($this->types->name((int) $owner->type) ?? '?').' '.$owner->original_id;
    }
}

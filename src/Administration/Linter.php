<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Administration;

use Illuminate\Database\Eloquent\Model;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;
use Wnikk\LaravelAccessRules\Contracts\Permission as PermissionContract;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;
use Wnikk\LaravelAccessRules\Exceptions\AccessRulesException;
use Wnikk\LaravelAccessRules\Internal\Administration\Owners;
use Wnikk\LaravelAccessRules\Internal\Administration\TypeRegistry;
use Wnikk\LaravelAccessRules\Internal\Conditions\ConditionCompiler;
use Wnikk\LaravelAccessRules\Internal\Conditions\ResourceRegistry;
use Wnikk\LaravelAccessRules\Internal\Storage\PermissionCache;

/**
 * Checks everything stored in the database against the application as it is today.
 *
 * Conditions are validated when they are saved, and then code changes under them: a migration
 * renames a column, a refactoring removes a relation, somebody drops a model from config. No error
 * appears at that moment. It appears later, as a user who lost access or as an SQL error in a list.
 *
 * The check lived inside the console command. It is a service of the administration layer now,
 * because an admin panel needs the same answer on a "health" screen, as data and not as a printed
 * table, with enough of an address to link every finding to the rule or the permission it is about.
 * "artisan acr:lint" prints what this class returns and adds nothing.
 *
 * It reads every stored rule and every permission with a condition or an option, so
 * it runs on demand, outside of checks.
 */
final class Linter
{
    public const BROKEN_CONDITION = 'condition';

    public const UNKNOWN_RESOURCE = 'resource';

    public const OUTDATED_TYPES = 'types';

    public const OPTION_NOT_ALLOWED = 'option';

    public const RULE_MISSING = 'rule_missing';

    public const UNKNOWN_OWNER_TYPE = 'owner_type';

    public function __construct(
        private ConditionCompiler $conditions,
        private ResourceRegistry $resources,
        private TypeRegistry $types,
        private RuleCatalog $rules,
        private PermissionCache $cache,
    ) {}

    /**
     * @param bool $fix Save again the conditions whose column types have changed since they were stored. Everything else needs a person.
     * @return array{
     *     problems:list<array{code:string, subject:string, id:int|string|null, where:string, problem:string}>,
     *     fixed:int
     * } "code" is one of the constants of this class, for translations and icons. "subject" is "rule", "permission" or "owners", and "id" its key in the table, so a panel can link to it.
     */
    public function run(bool $fix = false): array
    {
        $problems = [];
        $fixed    = 0;

        foreach (app(RuleContract::class)->newQuery()->get() as $rule) {
            $where = 'rule '.$rule->guard_name;
            $found = static fn (string $code, string $problem) => ['code' => $code, 'subject' => 'rule', 'id' => $rule->getKey(), 'where' => $where, 'problem' => $problem];

            if ($rule->resource !== null && $this->resources->model($rule->resource) === null) {
                $problems[] = $found(self::UNKNOWN_RESOURCE, 'resource "'.$rule->resource.'" is not listed in config access.resources');
            }

            foreach ($rule->condition === null ? [] : $this->conditions->lint($rule->condition, $rule->resource) as $problem) {
                $problems[] = $found(self::BROKEN_CONDITION, $problem);
            }

            $fixed += $this->retype($rule, $rule->resource, $fix, $found, $problems);
        }

        $permissions = app(PermissionContract::class)->newQuery()
            ->where(fn ($query) => $query->whereNotNull('condition')->orWhereNotNull('option'))
            ->with(['rule', 'owner'])->get();

        foreach ($permissions as $permission) {
            if ($permission->rule === null) {
                $problems[] = ['code' => self::RULE_MISSING, 'subject' => 'permission', 'id' => $permission->getKey(), 'where' => 'permission #'.$permission->getKey(),
                    'problem'         => 'points at rule #'.$permission->rule_id.' that does not exist: the row was left behind by a delete past the package'];

                continue;
            }

            $where = ($permission->permission ? 'permission' : 'prohibition').' #'.$permission->getKey().' of '
                .class_basename($this->types->name((int) $permission->owner?->type) ?? '?').' '.$permission->owner?->original_id
                .' for '.$permission->rule->guard_name;
            $found = static fn (string $code, string $problem) => ['code' => $code, 'subject' => 'permission', 'id' => $permission->getKey(), 'where' => $where, 'problem' => $problem];

            // Options are validated when they are granted. An administrator who narrows "in:csv,pdf" to "in:csv"
            // leaves permissions for "pdf" that keep working, and no other part of the package reports them.
            try {
                $this->rules->checkOption($permission->rule, $permission->option);
            } catch (AccessRulesException) {
                $problems[] = $found(self::OPTION_NOT_ALLOWED, 'option "'.$permission->option.'" is no longer allowed by the rule ('.$permission->rule->options.'); the permission still works');
            }

            foreach ($permission->condition === null ? [] : $this->conditions->lint($permission->condition, $permission->rule->resource) as $problem) {
                $problems[] = $found(self::BROKEN_CONDITION, $problem);
            }

            $fixed += $this->retype($permission, $permission->rule->resource, $fix, $found, $problems);
        }

        if ($fixed > 0) {
            // Conditions are saved past Owners, which is what normally drops the cache.
            $this->cache->bump();
        }

        // An owner whose type left config can no longer be addressed: its permissions are dead weight,
        // and a type that comes back under another name will not find them.
        $unknown = app(OwnerContract::class)->newQuery()->whereNotIn('type', array_keys($this->types->all()) ?: [-1])
            ->selectRaw('type, count(*) as owners')->groupBy('type')->get();

        foreach ($unknown as $row) {
            $problems[] = ['code' => self::UNKNOWN_OWNER_TYPE, 'subject' => 'owners', 'id' => (int) $row->type, 'where' => 'owners',
                'problem'         => $row->owners.' owner(s) of type #'.$row->type.' that is not in config access.owner_types'];
        }

        return ['problems' => $problems, 'fixed' => $fixed];
    }

    /**
     * Without $fix an outdated type is a problem like any other, so CI fails until somebody
     * looks: the migration that changed the column has also changed what the condition means.
     *
     * @return int 1 when the condition was saved again.
     */
    private function retype(Model $stored, ?string $resource, bool $fix, callable $found, array &$problems): int
    {
        $fresh = $stored->condition === null ? null : $this->conditions->retyped($stored->condition, $resource);
        if ($fresh === null) {
            return 0;
        }

        if (! $fix) {
            $problems[] = $found(self::OUTDATED_TYPES, 'types of columns have changed since the condition was saved, so records and lists may compare differently; run "php artisan acr:lint --fix"');

            return 0;
        }

        $stored->condition = $fresh;
        $stored->save();

        return 1;
    }
}

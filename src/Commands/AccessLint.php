<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Wnikk\LaravelAccessRules\Administration\TypeRegistry;
use Wnikk\LaravelAccessRules\Conditions\ConditionCompiler;
use Wnikk\LaravelAccessRules\Conditions\ResourceRegistry;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;
use Wnikk\LaravelAccessRules\Contracts\Permission as PermissionContract;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;

/**
 * Checks everything stored in the database against the application as it is today.
 *
 * Conditions are validated when they are saved, and then code changes under them: a migration
 * renames a column, a refactoring removes a relation, somebody drops a model from config. Nothing
 * fails at that moment. It fails weeks later, as a user who lost access or as an SQL error in a list.
 *
 * The command is meant for CI and for deploys, right after migrations: it exits with 1 when it
 * finds anything. It reads every stored condition, which is why it is a command and not something
 * the package does on its own at boot.
 */
#[AsCommand(name: 'acr:lint')]
class AccessLint extends AccessCommand
{
    protected $signature = 'acr:lint';

    protected $description = 'Access rules and inheritance: check stored rules, conditions and owners against current models and config';

    public function handle(ConditionCompiler $conditions, ResourceRegistry $resources, TypeRegistry $types): int
    {
        $problems = [];

        foreach (app(RuleContract::class)->newQuery()->get() as $rule) {
            if ($rule->resource !== null && $resources->model($rule->resource) === null) {
                $problems[] = ['rule '.$rule->guard_name, 'resource "'.$rule->resource.'" is not listed in config access.resources'];
            }

            foreach ($rule->condition === null ? [] : $conditions->lint($rule->condition, $rule->resource) as $problem) {
                $problems[] = ['rule '.$rule->guard_name, $problem];
            }
        }

        $permissions = app(PermissionContract::class)->newQuery()->whereNotNull('condition')->with(['rule', 'owner'])->get();

        foreach ($permissions as $permission) {
            // A permission of a soft deleted rule is asleep, not broken.
            if ($permission->rule === null) {
                continue;
            }

            $where = ($permission->permission ? 'permission' : 'prohibition').' #'.$permission->id.' of '
                .class_basename($types->name((int) $permission->owner?->type) ?? '?').' '.$permission->owner?->original_id
                .' for '.$permission->rule->guard_name;

            foreach ($conditions->lint($permission->condition, $permission->rule->resource) as $problem) {
                $problems[] = [$where, $problem];
            }
        }

        // An owner whose type left config can no longer be addressed: its permissions are dead weight,
        // and a type that comes back under another name will not find them.
        $unknown = app(OwnerContract::class)->newQuery()->whereNotIn('type', array_keys($types->all()) ?: [-1])
            ->selectRaw('type, count(*) as owners')->groupBy('type')->get();

        foreach ($unknown as $row) {
            $problems[] = ['owners', $row->owners.' owner(s) of type #'.$row->type.' that is not in config access.owner_types'];
        }

        if ($problems === []) {
            $this->info('Rules, conditions and owners match current models and config.');

            return self::SUCCESS;
        }

        $this->table(['Where', 'Problem'], $problems);
        $this->error(count($problems).' problem(s) found.');

        return self::FAILURE;
    }
}

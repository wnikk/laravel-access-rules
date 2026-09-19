<?php

declare(strict_types=1);

namespace Wnikk\LaravelAccessRules;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Throwable;
use Wnikk\LaravelAccessRules\Administration\AccessManager;
use Wnikk\LaravelAccessRules\Administration\Owners;
use Wnikk\LaravelAccessRules\Administration\RuleCatalog;
use Wnikk\LaravelAccessRules\Administration\TypeRegistry;
use Wnikk\LaravelAccessRules\Authorization\DecisionPoint;
use Wnikk\LaravelAccessRules\Authorization\Explainer;
use Wnikk\LaravelAccessRules\Authorization\GateHook;
use Wnikk\LaravelAccessRules\Authorization\Permissions;
use Wnikk\LaravelAccessRules\Conditions\ConditionCompiler;
use Wnikk\LaravelAccessRules\Conditions\Evaluation\TreeFunctions;
use Wnikk\LaravelAccessRules\Conditions\ResourceRegistry;
use Wnikk\LaravelAccessRules\Contracts\AccessManager as AccessManagerContract;
use Wnikk\LaravelAccessRules\Contracts\Inheritance as InheritanceContract;
use Wnikk\LaravelAccessRules\Contracts\Owner as OwnerContract;
use Wnikk\LaravelAccessRules\Contracts\Permission as PermissionContract;
use Wnikk\LaravelAccessRules\Contracts\Rule as RuleContract;
use Wnikk\LaravelAccessRules\Storage\HierarchyQuery;
use Wnikk\LaravelAccessRules\Storage\PermissionCache;

/**
 * Wires the package into an application: bindings, the Gate hooks, commands, publishing.
 *
 * The interesting part is lifetimes. Facts about the application and the database server,
 * such as the type map and "this server has WITH RECURSIVE", live as long as the process.
 * Everything that remembers permissions lives as long as a request. Under Octane or in
 * a queue worker a process serves many users, and a singleton that remembers permissions
 * would hand the first user's answers to the second.
 */
class AccessRulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/access.php', 'access');

        // A closure, not a class name: the model class is read from config when a model is needed.
        // Binding the name at registration froze whatever config held before the application set its own.
        foreach ([
            'rule'        => [RuleContract::class, Models\Rule::class],
            'permission'  => [PermissionContract::class, Models\Permission::class],
            'owner'       => [OwnerContract::class, Models\Owner::class],
            'inheritance' => [InheritanceContract::class, Models\Inheritance::class],
        ] as $key => [$contract, $default]) {
            $this->app->bind($contract, static fn ($app) => $app->make(config('access.models.'.$key) ?: $default));
        }

        // Process lifetime. None of these holds anything about a user or a request.
        $this->app->singleton(TypeRegistry::class);
        $this->app->singleton(ResourceRegistry::class);
        $this->app->singleton(HierarchyQuery::class, static fn () => new HierarchyQuery(
            mode: (string) config('access.hierarchy', 'auto'),
            onFallback: static function (Throwable $e) {
                logger()->warning('[AccessRules] WITH RECURSIVE is not available, falling back to level-by-level queries: '.$e->getMessage());
            },
        ));

        // Request lifetime. Permissions compiles per owner and remembers the result; the services
        // around it hold a reference to it, so they share its lifetime.
        $this->app->scoped(PermissionCache::class, static fn () => new PermissionCache(config('access.cache') ?? []));
        foreach ([Permissions::class, DecisionPoint::class, GateHook::class, Owners::class, RuleCatalog::class, ConditionCompiler::class, AccessManager::class, TreeFunctions::class, Explainer::class] as $service) {
            $this->app->scoped($service);
        }

        $this->app->alias(AccessManager::class, AccessManagerContract::class);
    }

    public function boot(): void
    {
        $this->offerPublishing();
        $this->registerCommands();

        if (config('access.register_permission_check_method')) {
            $this->registerPermissionsToGate();
        }
    }

    /**
     * Registers closures and resolves GateHook inside of them. The hook lives as long as a
     * request, and a Gate that captured one instance at boot would keep using it in an Octane
     * worker forever.
     *
     * The user parameter is nullable on purpose: Gate reads the signature and skips callbacks
     * that cannot take a guest.
     */
    public function registerPermissionsToGate(): bool
    {
        $gate = $this->app->make(Gate::class);

        $gate->before(fn (?Authenticatable $user, $ability, $args = []) => $this->app->make(GateHook::class)->before($user, (string) $ability, (array) $args));

        // The check for null comes before the container call: most checks are decided by now, and
        // resolving the hook costs 0.3 microseconds that a decided check does not need to pay.
        $gate->after(fn (?Authenticatable $user, $ability, $result = null, $args = []) => $result !== null ? null : $this->app->make(GateHook::class)->after($user, (string) $ability, $result, (array) $args));

        return true;
    }

    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            Commands\AccessRuleCreate::class,
            Commands\AccessRuleDelete::class,
            Commands\AccessRuleOwners::class,
            Commands\AccessPermissionAssign::class,
            Commands\AccessPermissionRemove::class,
            Commands\AccessPermissionInherit::class,
            Commands\AccessPermissionNotInherit::class,
            Commands\AccessCacheClear::class,
            Commands\AccessExplain::class,
            Commands\AccessLint::class,
        ]);

        $this->optimizes(clear: 'acr:cache:clear', key: 'access-rules');
    }

    protected function offerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/access.php' => config_path('access.php'),
        ], 'access-config');

        $migrations = __DIR__.'/../database/migrations/';

        $this->publishes([
            $migrations.'create_access_rules_tables.php.stub' => $this->migrationPath('create_access_rules_tables.php'),
        ], 'access-migrations');

        // A tag of its own: a fresh install must not publish it, and an upgrade must not publish
        // the create migration a second time.
        $this->publishes([
            $migrations.'upgrade_access_rules_tables_to_v3.php.stub' => $this->migrationPath('upgrade_access_rules_tables_to_v3.php'),
        ], 'access-migrations-upgrade');

        // Separate again, because it stops on duplicates that version 2 allowed. A project decides
        // when to clean them up; the upgrade of the package does not wait for that.
        $this->publishes([
            $migrations.'add_access_rules_constraints.php.stub' => $this->migrationPath('add_access_rules_constraints.php'),
        ], 'access-migrations-constraints');
    }

    /**
     * Reuses the file name of an earlier publish. Laravel names migrations by timestamp, and
     * a second "vendor:publish" would otherwise add the same migration under a new name.
     */
    protected function migrationPath(string $name): string
    {
        $directory = $this->app->databasePath('migrations'.DIRECTORY_SEPARATOR);
        $existing  = $this->app->make(Filesystem::class)->glob($directory.'*_'.$name);

        return $existing[0] ?? $directory.date('Y_m_d_His').'_'.$name;
    }
}

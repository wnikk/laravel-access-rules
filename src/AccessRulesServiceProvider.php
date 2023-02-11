<?php

namespace Wnikk\LaravelAccessRules;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Wnikk\LaravelAccessRules\Contracts\{
    Rule as RuleContract,
    Inheritance as InheritanceContract,
    Permission as PermissionContract,
    Owner as OwnerContract
};

class AccessRulesServiceProvider extends ServiceProvider
{
    use EventMap;
    public function boot()
    {
        $this->registerEvents();
        $this->offerPublishing();
        $this->registerCommands();

        if (config('access.register_permission_check_method')) {
            $this->registerPermissionsToGate();
        }
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerModelBindings();
    }

    /**
     * Register events
     *
     * @return void
     */
    protected function registerEvents()
    {
        $events = $this->app->make(Dispatcher::class);

        foreach ($this->events as $event => $listeners) {
            foreach ($listeners as $listener) {
                $events->listen($event, $listener);
            }
        }
    }

    /**
     * Register console commands
     *
     * @return void
     */
    protected function registerCommands()
    {
        if ($this->app->runningInConsole()) {
            //$this->commands([
            //]);
        }
    }
    protected function registerModelBindings()
    {
        /** @var array{rule:string, linkage:string, owners:string, inheritance:string} */
        $config = config('access.models');

        if (!$config) {
            return;
        }

        $this->app->bind(RuleContract::class, $config['rule']);
        $this->app->bind(InheritanceContract::class, $config['inheritance']);
        $this->app->bind(PermissionContract::class, $config['permission']);
        $this->app->bind(OwnerContract::class, $config['owner']);
    }


    /**
     * Register permissions to Laravel Gate
     *
     * @return bool
     */
    public function registerPermissionsToGate(): bool
    {
        app(Gate::class)->before([app(AccessRules::class), 'checkOwnerPermission']);
        return true;
    }


    /**
     * Setup the resource publishing groups
     *
     * @return void
     */
    protected function offerPublishing()
    {
        if (!function_exists('config_path')) {
            // function not available and 'publish' not relevant in Lumen
            return;
        }
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/access.php' => config_path('access.php'),
            ], 'access-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_access_rules_tables.php.stub' => $this->getMigrationFileName('create_access_rules_tables.php'),
            ], 'access-migrations');
        }
    }

    /**
     * Returns existing migration file if found, else uses the current timestamp.
     *
     * @return string
     */
    protected function getMigrationFileName($migrationFileName): string
    {
        $timestamp = date('Y_m_d_His');

        $filesystem = $this->app->make(Filesystem::class);

        return Collection::make($this->app->databasePath().DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR)
            ->flatMap(function ($path) use ($filesystem, $migrationFileName) {
                return $filesystem->glob($path.'*_'.$migrationFileName);
            })
            ->push($this->app->databasePath()."/migrations/{$timestamp}_{$migrationFileName}")
            ->first();
    }
}

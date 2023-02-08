<?php

namespace Wnikk\LaravelAccessRules;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Wnikk\LaravelAccessRules\Contracts\{
    Role as RoleContract,
    Inheritance as InheritanceContract,
    Linkage as LinkageContract,
    Owners as OwnersContract
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
        /** @var array{role:string, linkage:string, owners:string, inheritance:string} */
        $config = config('access.models');

        if (!$config) {
            return;
        }

        $this->app->bind(RoleContract::class, $config['role']);
        $this->app->bind(InheritanceContract::class, $config['inheritance']);
        $this->app->bind(LinkageContract::class, $config['linkage']);
        $this->app->bind(OwnersContract::class, $config['owners']);
    }


    /**
     * Register permissions to Laravel Gate
     *
     * @return bool
     */
    public function registerPermissionsToGate(): bool
    {
        app(Gate::class)->before(function (Authorizable $user, string $ability) {
            if (method_exists($user, 'hasPermission')) {
                return $user->hasPermission($ability) ?: null;
            }
            return null;
        });

        return true;
    }

}
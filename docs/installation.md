---
title: Installation
weight: 1
---

# Installation

Version 3 needs PHP 8.4 and Laravel 13 or newer. For older applications stay on 2.x: `composer require wnikk/laravel-access-rules:^2.4`.

1. This package **publishes a `config/access.php` file**. If you already have a file by that name, you must rename or remove it.

2. You can install the package using composer:
    ```bash
    composer require wnikk/laravel-access-rules
    ```

3. The service provider registers itself. With package discovery turned off, add it to `bootstrap/providers.php`:

    ```php
    return [
        // ...
        Wnikk\LaravelAccessRules\AccessRulesServiceProvider::class,
    ];
    ```

   Or, in a project that lists providers in `bootstrap/app.php`:

    ```php
    return Application::configure(basePath: dirname(__DIR__))
        ->withProviders([
            Wnikk\LaravelAccessRules\AccessRulesServiceProvider::class,
        ])
        // ...
    ```

   `withProviders()` adds to the list of `bootstrap/providers.php`, it does not replace it.

4. You should publish the migration and the **config/access.php** config file with:

    ```bash
    php artisan vendor:publish --provider="Wnikk\LaravelAccessRules\AccessRulesServiceProvider"
    ```

   Or one part at a time: `--tag=access-config`, `--tag=access-migrations`.

   Coming from version 2.x? Tables stay as they are, see the [upgrade guide](upgrade-2-to-3.md):

    ```bash
    php artisan vendor:publish --tag=access-migrations-upgrade
    ```

5. Before performing the following commands, you need to adjust the settings file `config/access.php`
by indicating the list of possible types of users.

    ```php
    // Who can hold permissions: model classes, and plain names for owners without a model.
    'owner_types' => [
        App\Models\User::class,
        'Group',
        'Role',
    ],
    ```

   The database stores a number computed from the name, so the order of the list means nothing. Renaming
   an entry is what breaks things: owners stored under the old name lose their permissions. Rename a class
   only together with a data migration.

6. **Run the migrations**: After the config and migration have been published and configured, you can create the tables for this package by running:

    ```bash
    php artisan migrate
    ```

7. **Add the necessary trait to your User model**:

    ```php
    use Wnikk\LaravelAccessRules\Traits\HasPermissions;
    
    class User extends Authenticatable
    {
        use HasPermissions;
    ```

8. Optional, for permissions that depend on data: list the models conditions may read in `resources` of
   `config/access.php` and add the trait `HasAccessScope` to models whose lists are filtered with `allowedTo()`.
   See [Conditions](conditions.md).

9. Optional: run `php artisan acr:lint` in CI and after migrations. It checks stored conditions, rules and owners
   against models and config as they are now.

Go on with [Basic Usage](basic-usage.md).

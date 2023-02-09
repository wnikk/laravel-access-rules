# Installation

1. This package **publishes a `config/access.php` file**. If you already have a file by that name, you must rename or remove it.

2. You can install the package using composer:
    ```bash
    composer require wnikk/laravel-access-rules
    ```

3. Optional: The service provider will automatically get registered. Or you may manually add the service provider in your `config/app.php` file:

    ```
    'providers' => [
        // ...
        Spatie\Permission\PermissionServiceProvider::class,
    ];
    ```

4. You should publish the migration and the config/access.php config file with:

    ```bash
    php artisan vendor:publish --provider="Wnikk\LaravelAccessRules\AccessRulesServiceProvider"
    ```

5. Before performing the following commands, you need to adjust the settings file `config/access.php`
by indicating the list of possible types of users.

    ```php
    /**
     * List of user types.
     * The list can be both the real name of the classes
     * or pseudo names like "group".
     */
    'owner_types' => [
        'Groups',
        App\Models\User::class,
        ...
    ],
    ...
    ```
    **IMPORTANT**: After installation, this list can only be supplemented.

6. **Run the migrations**: After the config and migration have been published and configured, you can create the tables for this package by running:

    ```bash
    php artisan migrate
    ```

7. **Add the necessary trait to your User model**:

    ```php
    use Wnikk\LaravelAccessRules\Traits\HasPermissions;
    
    class User extends ... {
        // The User model requires this trait
        use HasPermissions;
    ```

   Consult the **Basic Usage** section of the docs to get started using the features of this package.

```bash
php artisan laratrust:setup
```

This command will generate the migrations, create the `Role` and `Permission` models (if you are using the teams feature it will also create a `Team` model) and will add the trait to the configured user models.

4. Dump the autoloader:
```bash
composer dump-autoload
```

5. Run the migrations:
```bash
php artisan migrate
```

::: tip IMPORTANT
**If you did the steps above you are done with the configuration, if not, please read and follow the whole configuration process**
:::

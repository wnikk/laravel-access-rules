
# Laravel Access Rules (Laravel Permissions Package)



## What does Access Rules support?

- Multiple user models.
- Multiple roles and permissions can be attached to users.
- Multiple permissions can be attached to roles.
- Roles and permissions verification.
- Roles and permissions caching.
- Events when roles and permissions are attached, detached or synced.
- Multiple roles and permissions can be attached to users within teams.
- Objects ownership verification.
- Multiple guards for the middleware.
- Laravel gates and policies.

## What It Does
This package allows you to manage user permissions and roles in a database.

Once installed you can do stuff like this:

```php
// Adding permissions to a user
$user->givePermissionTo('articles.edit');
```

Because all permissions will be registered on [Laravel's gate](https://laravel.com/docs/authorization), you can check if a user has a permission with Laravel's default `can` function:

```php
$user->can('articles.edit');
```

## Alternatives

- [Povilas Korop](https://github.com/spatie/laravel-permission) takes a slightly different approach to its features.
- [ultraware/roles](https://github.com/ultraware/roles) For several years it has not been supported and transferred to the archive.
- [santigarcor/laratrust](https://github.com/santigarcor/laratrust) implements team support.
- [zizaco/entrust](https://github.com/zizaco/entrust) offers some wildcard pattern matching.

## Contributing

Please report any issue you find in the issues page. Pull requests are more than welcome.


## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

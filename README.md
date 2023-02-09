
# Laravel Access Rules (Laravel Permissions Package)

[![License](https://poser.pugx.org/wnikk/larar/license)](//packagist.org/packages/wnikk/larar)
[![Latest Stable Version](https://poser.pugx.org/wnikk/larar/v)](//packagist.org/packages/wnikk/larar)
[![Total Downloads](https://poser.pugx.org/wnikk/larar/downloads)](//packagist.org/packages/wnikk/larar)

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

- [spatie/laravel-permission](https://github.com/spatie/laravel-permission) takes a slightly different approach to its features.
- [ultraware/roles](https://github.com/ultraware/roles) It not supported and transferred to archive.
- [santigarcor/laratrust](https://github.com/santigarcor/laratrust) implements team support.
- [zizaco/entrust](https://github.com/zizaco/entrust) offers some wildcard pattern matching.

## Contributing

Please report any issue you find in the issues page. Pull requests are more than welcome.


## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

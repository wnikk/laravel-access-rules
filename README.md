
# Laravel Access Rules (Laravel Permissions Package)

[![License](https://poser.pugx.org/wnikk/laravel-access-rules/license)](//packagist.org/packages/wnikk/laravel-access-rules)
[![Latest Stable Version](https://poser.pugx.org/wnikk/laravel-access-rules/v)](//packagist.org/packages/wnikk/laravel-access-rules)
[![Total Downloads](https://poser.pugx.org/wnikk/laravel-access-rules/downloads)](//packagist.org/packages/wnikk/laravel-access-rules)

## What does Access Rules support?

- Multiple user models.
- Multiple permissions can be attached to users.
- Multiple permissions can be attached to groups.
- Permissions verification.
- Permissions caching.
- Events when permissions are attached, detached or synced.
- Multiple permissions can be attached to user or group.
- Permissions can be inherited with unlimited investment from users and groups.
- Laravel gates and policies.


## Documentation, Installation, and Usage Instructions

See the [documentation](https://github.com/wnikk/laravel-access-rules/tree/master/docs) for detailed installation and usage instructions.

## What It Does
This package allows you to manage user permissions and groups (instead roles) in a database.

Once installed you can do stuff like this:

```php
// Adding permissions to a user
$user->givePermissionTo('articles.edit');
```


Or you can inherit the rights from another user or groups

```php
// According to the existing user from object
$user->inheritPermissionFrom(User::find(1));

// By identifier
$user->inheritPermissionFrom(User::class, 1);

// From the group
$user->inheritPermissionFrom('Group', 1);
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


![Laravel Access Control Rules](https://raw.githubusercontent.com/wnikk/laravel-access-rules/main/docs/art/laravel-access-control-rules-logo.png)

# Access Control Rules (Laravel Permissions Package)

[![License](https://poser.pugx.org/wnikk/laravel-access-rules/license)](//packagist.org/packages/wnikk/laravel-access-rules)
[![Code Climate](https://codeclimate.com/github/wnikk/laravel-access-rules/badges/gpa.svg)](//codeclimate.com/github/wnikk/laravel-access-rules)
[![PHP Version Require](http://poser.pugx.org/wnikk/laravel-access-rules/require/php)](//packagist.org/packages/wnikk/laravel-access-rules)
[![Total Downloads](http://poser.pugx.org/wnikk/laravel-access-rules/downloads)](//packagist.org/packages/wnikk/laravel-access-rules)
[![Latest Stable Version](https://poser.pugx.org/wnikk/laravel-access-rules/v)](//packagist.org/packages/wnikk/laravel-access-rules)
[![Latest Unstable Version](http://poser.pugx.org/wnikk/laravel-access-rules/v/unstable)](//packagist.org/packages/wnikk/laravel-access-rules)

## What does Access Control Rules support?

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


## You can install the package using composer:

```bash
composer require wnikk/laravel-access-rules
```

See the [installation page](https://github.com/wnikk/laravel-access-rules/blob/main/docs/installation.md) for detailed.


## What It Does
This package allows you to manage user permissions and groups (instead roles) in a database.

Once installed you can do stuff like this:

```php
use Wnikk\LaravelAccessRules\AccessRules;

// Add new rule permission
AccessRules::newRule('articles.edit', 'Access to editing articles');
```
```php
// Adding permissions to a user
$user->addPermission('articles.edit');
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


Because all permissions will be registered on **Laravel's gate**, you can check if a user has a permission with Laravel's default `can` function:

```php
$user->can('articles.edit');
```

Examples of how can be used in more detail described in [Basic Usage](https://github.com/wnikk/laravel-access-rules/blob/main/docs/basic-usage.md) section.

## Opening an Issue

Before opening an issue there are a couple of considerations:
* You are all awesome!
* Pull requests are more than welcome.
* **Read the instructions** and make sure all steps were *followed correctly*.
* **Check** that the issue is not *specific to your development environment* setup.
* **Provide** *duplication steps*.
* **Attempt to look into the issue**, and if you *have a solution, make a pull request*.
* **Show that you have made an attempt** to *look into the issue*.
* **Check** to see if the issue you are *reporting is a duplicate* of a previous reported issue.
* **Following these instructions show me that you have tried.**
* Please be considerate that this is an open source project that I provide to the community for FREE when opening an issue.

## Alternatives

- [spatie/laravel-permission](https://github.com/spatie/laravel-permission) takes a slightly different approach to its features.
- [ultraware/roles](https://github.com/ultraware/roles) It not supported and transferred to archive.
- [santigarcor/laratrust](https://github.com/santigarcor/laratrust) implements team support.
- [zizaco/entrust](https://github.com/zizaco/entrust) offers some wildcard pattern matching.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

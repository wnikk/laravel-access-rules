# Introduction

Laravel Access Rules is a package that lets you handle very easily roles and permissions inside your application. All of this through a very simple configuration process and API.

**It is necessary for work correctly**

1. It is necessary to carry out [installation](https://github.com/wnikk/laravel-access-rules/blob/master/docs/installation.md).

2. Add the necessary trait to your User model:

```php
use Wnikk\LaravelAccessRules\Traits\HasPermissions;

class User extends ... {
    // The User model requires this trait
    use HasPermissions;
```

After this, through standard Laravel methods, you can use all the capabilities of **Gate**.

**Here you can see some examples**

Checking through standard **Gate**:
```php
$user->can('articles.edit');
```

Get such a right, you can assign a rally:

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

Examples of how can be used in more detail described in **Basic Usage** section.

<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Wnikk\LaravelAccessRules\Traits\HasPermissions;

/**
 * A user that owns permissions. Most tests never save it: the package needs a class and a key,
 * not a row, and tests without a users table run on any database without setup.
 */
class TestUser extends Authenticatable
{
    use HasFactory, HasPermissions;

    protected $fillable = [
        'id',
        'name',
        'email',
    ];

    public static function newFactory()
    {
        return TestUserFactory::new();
    }
}

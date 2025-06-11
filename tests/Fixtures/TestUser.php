<?php

namespace Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Wnikk\LaravelAccessRules\Traits\HasPermissions;
use Tests\Fixtures\TestUserFactory;

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
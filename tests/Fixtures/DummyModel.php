<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A record to check abilities against in tests of version 2. Tests of conditions use the Shop models.
 */
class DummyModel extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $fillable = [
        'id',
    ];

    public static function newFactory()
    {
        return DummyModelFactory::new();
    }
}

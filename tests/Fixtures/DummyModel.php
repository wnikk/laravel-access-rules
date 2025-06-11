<?php
namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Tests\Fixtures\DummyModelFactory;


/**
 * Dummy model for resource authorization.
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
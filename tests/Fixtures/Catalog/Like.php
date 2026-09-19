<?php

namespace Tests\Fixtures\Catalog;

use Illuminate\Database\Eloquent\Model;

class Like extends Model
{
    protected $table = 'catalog_likes';

    protected $guarded = [];

    public $timestamps = false;
}

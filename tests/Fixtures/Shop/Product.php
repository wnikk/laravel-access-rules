<?php

namespace Tests\Fixtures\Shop;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'shop_products';

    protected $guarded = [];

    public $timestamps = false;
}

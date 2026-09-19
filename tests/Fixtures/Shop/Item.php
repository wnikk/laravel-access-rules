<?php

namespace Tests\Fixtures\Shop;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $table = 'shop_items';

    protected $guarded = [];

    public $timestamps = false;
}

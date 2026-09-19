<?php

namespace Tests\Fixtures\Shop;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $table = 'shop_payments';

    protected $guarded = [];

    public $timestamps = false;
}

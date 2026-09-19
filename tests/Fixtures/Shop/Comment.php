<?php

namespace Tests\Fixtures\Shop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Comment extends Model
{
    protected $table = 'shop_comments';

    protected $guarded = [];

    public $timestamps = false;

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}

<?php

namespace Tests\Fixtures\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Comment extends Model
{
    protected $table = 'catalog_comments';

    protected $guarded = [];

    public $timestamps = false;

    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable');
    }
}

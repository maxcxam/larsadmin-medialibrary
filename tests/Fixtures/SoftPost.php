<?php

namespace Maxcxam\LarsadminMedialibrary\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;

/** The same posts table, soft deleted: medialibrary keeps the media until a force delete. */
class SoftPost extends Post
{
    use SoftDeletes;
}

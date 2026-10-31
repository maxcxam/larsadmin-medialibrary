<?php

namespace Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Same fields; the detail scope only contains articles whose title starts with "Live". */
class LiveArticles extends Articles
{
    public static function detailQuery(Request $request, Builder $query): Builder
    {
        return $query->where('title', 'like', 'Live%');
    }
}

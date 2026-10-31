<?php

namespace Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\LarsadminMedialibrary\Fields\Media;

/** The contract's media are audited as "changed" only (sensitive()). */
class SensitiveArticles extends Articles
{
    public function fields(Request $request): array
    {
        return [
            Text::make('Title'),
            Media::make('Contract', 'contract')->types(['pdf'])->sensitive(),
        ];
    }
}

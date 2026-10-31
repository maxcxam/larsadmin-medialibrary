<?php

namespace Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\Fields\MediaGallery;

class RequiredCoverArticles extends Articles
{
    public function fields(Request $request): array
    {
        return [
            Text::make('Title'),
            Media::make('Cover', 'cover')->images()->rules('required'),
            MediaGallery::make('Gallery', 'gallery')->rules('required', 'dimensions:min_width=10'),
        ];
    }
}

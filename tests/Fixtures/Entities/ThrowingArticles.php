<?php

namespace Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\LarsadminMedialibrary\Fields\MediaGallery;
use RuntimeException;

/** A later after-save callback throws, so the panel rolls the save transaction back. */
class ThrowingArticles extends Articles
{
    public function fields(Request $request): array
    {
        return [
            Text::make('Title'),
            MediaGallery::make('Gallery', 'gallery'),
            Text::make('Boom', 'boom')->fillUsing(fn () => fn () => throw new RuntimeException('boom')),
        ];
    }
}

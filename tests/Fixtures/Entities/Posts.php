<?php

namespace Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\Fields\MediaGallery;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Post;

/** Fields that rely on the model's collection declarations (no disk(), limit() above onlyKeepLatest()). */
class Posts extends Entity
{
    public static string $model = Post::class;

    public static string $title = 'title';

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Title')->rules('required', 'max:255'),
            Media::make('Cover', 'cover')->images()->conversion('thumb'),
            MediaGallery::make('Gallery', 'gallery')->images()->conversion('thumb')->limit(5),
            Media::make('Docs', 'docs'),
            MediaGallery::make('Screened', 'screened'),
            MediaGallery::make('Responsive', 'responsive')->images()->conversion('thumb'),
        ];
    }
}

<?php

namespace Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\Fields\MediaGallery;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Article;

class Articles extends Entity
{
    public static string $model = Article::class;

    public static string $title = 'title';

    /** Whether the private "contract" field is visible (canSee). */
    public static bool $contractVisible = true;

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Title')->rules('required', 'max:255'),
            Media::make('Cover', 'cover')->images()->conversion('thumb')->disk('media_public')->maxSize(512),
            MediaGallery::make('Gallery', 'gallery')->images()->conversion('thumb')->limit(3),
            Media::make('Contract', 'contract')->types(['pdf', 'txt'])
                ->canSee(fn () => static::$contractVisible),
        ];
    }
}

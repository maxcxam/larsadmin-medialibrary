<?php

namespace Maxcxam\LarsadminMedialibrary\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Article extends Model implements HasMedia
{
    use InteractsWithMedia;

    /** Queue the thumb conversion instead of generating it during the upload. */
    public static bool $queueThumbs = false;

    protected $table = 'articles';

    protected $guarded = [];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover')->singleFile();
        $this->addMediaCollection('gallery');
        $this->addMediaCollection('contract')->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $conversion = $this->addMediaConversion('thumb')->fit(Fit::Crop, 32, 32)->performOnCollections('cover', 'gallery');

        static::$queueThumbs ? $conversion->queued() : $conversion->nonQueued();
    }
}

<?php

namespace Maxcxam\LarsadminMedialibrary\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\File;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** A model whose collections declare disk, size limit, MIME types, an acceptsFile() check and responsive images. */
class Post extends Model implements HasMedia
{
    use InteractsWithMedia;

    /** Queue the thumb conversion instead of generating it during the upload. */
    public static bool $queueThumbs = false;

    protected $table = 'posts';

    protected $guarded = [];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover')->singleFile();
        $this->addMediaCollection('gallery')->onlyKeepLatest(2);
        $this->addMediaCollection('docs')->singleFile()->useDisk('media_public')->acceptsMimeTypes(['application/pdf']);
        $this->addMediaCollection('screened')->acceptsFile(fn (File $file) => $file->mimeType !== 'text/plain');
        $this->addMediaCollection('responsive')->withResponsiveImages();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $conversion = $this->addMediaConversion('thumb')->fit(Fit::Crop, 32, 32)->performOnCollections('cover', 'gallery', 'responsive');

        static::$queueThumbs ? $conversion->queued() : $conversion->nonQueued();

        $this->addMediaConversion('wide')->width(48)->performOnCollections('docs')->nonQueued();
    }
}

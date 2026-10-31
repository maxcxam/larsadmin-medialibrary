<?php

namespace Maxcxam\LarsadminMedialibrary\Tests\Fixtures;

use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** The common spatie pattern: conversions registered only for some media (CRM-05). */
class ConditionalPost extends Post
{
    public function registerMediaConversions(?Media $media = null): void
    {
        if ($media?->mime_type === 'image/jpeg') {
            $this->addMediaConversion('thumb')->fit(Fit::Crop, 32, 32)->performOnCollections('cover')->nonQueued();
        }
    }
}

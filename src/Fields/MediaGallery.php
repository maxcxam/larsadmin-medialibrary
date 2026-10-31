<?php

namespace Maxcxam\LarsadminMedialibrary\Fields;

use InvalidArgumentException;

/**
 * Several media of one collection, in the collection's order (order_column); reorderable in the form and, for users
 * who may update the record, directly on the detail page.
 */
class MediaGallery extends Media
{
    public string $component = 'maxcxam/media-gallery';

    protected bool $multiple = true;

    protected int $maxFiles = 10;

    public function __construct(string $name, ?string $attribute = null, ?string $collection = null)
    {
        parent::__construct($name, $attribute, $collection);

        $this->maxFiles = max(1, (int) config('larsadmin.files.max_files', 10));
    }

    public function limit(int $maxFiles): static
    {
        if ($maxFiles < 1) {
            throw new InvalidArgumentException('limit() must be at least 1.');
        }

        $this->maxFiles = $maxFiles;

        return $this;
    }
}

<?php

namespace Maxcxam\LarsadminMedialibrary\Fields;

use InvalidArgumentException;

/**
 * Several media of one collection, in the collection's order (order_column); reorderable in the form and, for users
 * who may update the record, directly on the detail page.
 *
 * Stable API (1.x of the add-on): everything of Media plus limit(). The collection must not be singleFile() (a
 * LogicException on the first resolve); its onlyKeepLatest(n) caps limit().
 */
class MediaGallery extends Media
{
    public string $component = 'maxcxam/media-gallery';

    protected bool $multiple = true;

    protected int $maxFiles = 10;

    /**
     * @param  string  $name  the label
     * @param  string|null  $attribute  the field key; defaults to the snake-cased name
     * @param  string|null  $collection  the media collection; defaults to the attribute
     */
    public function __construct(string $name, ?string $attribute = null, ?string $collection = null)
    {
        parent::__construct($name, $attribute, $collection);

        $this->maxFiles = max(1, (int) config('larsadmin.files.max_files', 10));
    }

    /**
     * The most media the gallery may hold (default larsadmin.files.max_files); capped by the collection's
     * onlyKeepLatest(n). More is a validation error before anything is written.
     *
     * @throws InvalidArgumentException when below 1
     */
    public function limit(int $maxFiles): static
    {
        if ($maxFiles < 1) {
            throw new InvalidArgumentException('limit() must be at least 1.');
        }

        $this->maxFiles = $maxFiles;

        return $this;
    }
}

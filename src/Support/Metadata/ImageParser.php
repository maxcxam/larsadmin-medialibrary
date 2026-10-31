<?php

namespace Maxcxam\LarsadminMedialibrary\Support\Metadata;

use Maxcxam\LarsadminMedialibrary\Support\MetadataStripFailed;

/**
 * Walks one image container and plans the stripped file: allow-listed structure is kept verbatim, everything else is
 * dropped. Throws {@see MetadataStripFailed} on anything malformed.
 *
 * @internal
 */
interface ImageParser
{
    public function parse(Source $source): Plan;
}

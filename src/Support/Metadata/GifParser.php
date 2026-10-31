<?php

namespace Maxcxam\LarsadminMedialibrary\Support\Metadata;

use Maxcxam\LarsadminMedialibrary\Support\MetadataStripFailed;

/**
 * GIF: keeps the header, logical screen descriptor, colour tables, images, Graphic Control and Plain Text extensions
 * and the NETSCAPE2.0 / ANIMEXTS1.0 looping extensions; drops comments, every other application extension (XMP, ICC
 * …), unknown extensions and everything after the trailer.
 *
 * @internal
 */
final class GifParser implements ImageParser
{
    private const LOOPING = ['NETSCAPE2.0', 'ANIMEXTS1.0'];

    public function parse(Source $source): Plan
    {
        if (! $source->startsWith(0, 'GIF87a') && ! $source->startsWith(0, 'GIF89a')) {
            throw MetadataStripFailed::failed('not a GIF file.');
        }

        $plan = new Plan($source);
        $position = 13 + $this->colorTable($source->byte(10));
        $plan->keep(0, $position);
        $images = 0;

        while (true) {
            $introducer = $source->byte($position);

            if ($introducer === 0x3B) {
                $plan->keep($position, 1);
                $position++;
                break;
            }

            if ($introducer === 0x2C) {
                $data = $position + 10 + $this->colorTable($source->byte($position + 9));
                $codeSize = $source->byte($data);

                if ($codeSize < 2 || $codeSize > 12) {
                    throw MetadataStripFailed::failed('invalid GIF image data.');
                }

                $end = $this->subBlocks($source, $data + 1);
                $plan->keep($position, $end - $position);
                $images++;
            } elseif ($introducer === 0x21) {
                $label = $source->byte($position + 1);
                $end = $this->subBlocks($source, $position + 2);
                $first = $source->byte($position + 2);

                // Kept extensions must have their exact layout, so no other data can ride along in them.
                if (($label === 0xF9 && ($first !== 4 || $end !== $position + 8))
                    || ($label === 0x01 && $first !== 12)) {
                    throw MetadataStripFailed::failed('malformed GIF extension.');
                }

                if ($label === 0xF9 || $label === 0x01 || ($label === 0xFF && $this->isLooping($source, $position + 2, $end))) {
                    $plan->keep($position, $end - $position);
                } else {
                    $plan->drop();
                }
            } else {
                throw MetadataStripFailed::failed('invalid GIF block.');
            }

            $position = $end;
        }

        if ($images === 0) {
            throw MetadataStripFailed::failed('GIF has no image data.');
        }

        if ($position < $source->size()) {
            $plan->drop();
        }

        return $plan;
    }

    private function colorTable(int $packed): int
    {
        return ($packed & 0x80) === 0 ? 0 : 3 * (2 << ($packed & 0x07));
    }

    /** The offset after the block terminator of the data sub-blocks starting at $position. */
    private function subBlocks(Source $source, int $position): int
    {
        while (($size = $source->byte($position)) !== 0) {
            $position += 1 + $size;
        }

        return $position + 1;
    }

    /** Exactly the looping extension: identifier, one 3-byte sub-block (1, loop count), terminator. */
    private function isLooping(Source $source, int $position, int $end): bool
    {
        return $end === $position + 17
            && $source->byte($position) === 11
            && in_array($source->bytes($position + 1, 11), self::LOOPING, true)
            && $source->byte($position + 12) === 3
            && $source->byte($position + 13) === 1;
    }
}

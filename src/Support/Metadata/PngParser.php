<?php

namespace Maxcxam\LarsadminMedialibrary\Support\Metadata;

use Maxcxam\LarsadminMedialibrary\Support\MetadataStripFailed;

/**
 * PNG / APNG: keeps the critical chunks and an allow-list of ancillary ones (colour, transparency, density, APNG
 * animation), each copied whole with its CRC verified; drops eXIf, tEXt, zTXt, iTXt, tIME, every unknown ancillary
 * chunk and everything after IEND. An unknown critical chunk fails.
 *
 * @internal
 */
final class PngParser implements ImageParser
{
    private const SIGNATURE = "\x89PNG\r\n\x1A\n";

    private const KEEP = [
        'IHDR', 'PLTE', 'IDAT', 'IEND',
        'tRNS', 'gAMA', 'cHRM', 'sRGB', 'iCCP', 'sBIT', 'bKGD', 'pHYs', 'hIST',
        'acTL', 'fcTL', 'fdAT',
    ];

    /** Larger eXIf chunks are dropped after reading only this much for the Orientation. */
    private const MAX_EXIF = 65536;

    public function parse(Source $source): Plan
    {
        if (! $source->startsWith(0, self::SIGNATURE)) {
            throw MetadataStripFailed::failed('not a PNG file.');
        }

        $plan = new Plan($source);
        $plan->keep(0, 8);

        $position = 8;
        $exifMark = null;
        $exifSeen = false;
        $ended = false;

        while (! $ended) {
            $length = $source->u32be($position);
            $type = $source->bytes($position + 4, 4);

            if ($length > 0x7FFFFFFF || $length > $source->size() - $position - 12) {
                throw MetadataStripFailed::failed('invalid PNG chunk length.');
            }

            if (preg_match('/\A[A-Za-z]{4}\z/', $type) !== 1) {
                throw MetadataStripFailed::failed('invalid PNG chunk type.');
            }

            if (($position === 8) !== ($type === 'IHDR')) {
                throw MetadataStripFailed::failed('PNG must start with one IHDR chunk.');
            }

            if ($type === 'IDAT' && $exifMark === null) {
                $exifMark = $plan->mark();
            }

            if (in_array($type, self::KEEP, true)) {
                $ended = $type === 'IEND';

                if (! $this->validLength($type, $length)) {
                    throw MetadataStripFailed::failed("invalid PNG {$type} chunk length.");
                }

                $this->assertCrc($source, $position, $length);
                $plan->keep($position, 12 + $length);
            } elseif ($type === 'eXIf') {
                $tiff = $source->bytes($position + 8, min($length, self::MAX_EXIF));

                if ($exifSeen) {
                    $plan->drop();
                } else {
                    $exifSeen = true;
                    $plan->orientation = Orientation::fromTiff($tiff);

                    if ($length > self::MAX_EXIF || ! Orientation::isMinimal($tiff)) {
                        $plan->drop();
                    }
                }
            } elseif (ctype_upper($type[0])) {
                throw MetadataStripFailed::failed('unknown critical PNG chunk.');
            } else {
                $plan->drop();
            }

            $position += 12 + $length;
        }

        if ($exifMark === null) {
            throw MetadataStripFailed::failed('PNG has no image data.');
        }

        if ($position < $source->size()) {
            $plan->drop();
        }

        if ($plan->orientation !== null) {
            $tiff = Orientation::tiff($plan->orientation);
            $chunk = 'eXIf'.$tiff;
            $plan->insert($exifMark, pack('N', strlen($tiff)).$chunk.pack('N', crc32($chunk)));
        }

        return $plan;
    }

    /** Kept chunks with a fixed or bounded size may not carry extra bytes. */
    private function validLength(string $type, int $length): bool
    {
        return match ($type) {
            'IHDR' => $length === 13,
            'IEND' => $length === 0,
            'PLTE' => $length > 0 && $length <= 768 && $length % 3 === 0,
            'tRNS' => $length <= 256,
            'gAMA' => $length === 4,
            'cHRM' => $length === 32,
            'sRGB' => $length === 1,
            'sBIT' => $length >= 1 && $length <= 4,
            'bKGD' => in_array($length, [1, 2, 6], true),
            'pHYs' => $length === 9,
            'hIST' => $length > 0 && $length <= 512 && $length % 2 === 0,
            'acTL' => $length === 8,
            'fcTL' => $length === 26,
            'fdAT' => $length >= 4,
            default => true, // IDAT (pixel data), iCCP (compressed ICC profile)
        };
    }

    private function assertCrc(Source $source, int $position, int $length): void
    {
        $crc = hash_init('crc32b');
        $source->hashInto($crc, $position + 4, 4 + $length);

        if (hash_final($crc, true) !== $source->bytes($position + 8 + $length, 4)) {
            throw MetadataStripFailed::failed('PNG chunk CRC mismatch.');
        }
    }
}

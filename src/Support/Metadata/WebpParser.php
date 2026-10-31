<?php

namespace Maxcxam\LarsadminMedialibrary\Support\Metadata;

use Maxcxam\LarsadminMedialibrary\Support\MetadataStripFailed;

/**
 * WebP (RIFF): keeps VP8, VP8L, VP8X, ALPH, ANIM, ANMF and ICCP; drops EXIF, XMP, unknown chunks and data after the
 * RIFF payload; clears the EXIF/XMP flags of VP8X and rewrites the RIFF size. Animation frames may only contain
 * ALPH / VP8 / VP8L. The minimal Orientation EXIF is appended (with the VP8X EXIF flag) only for the extended format:
 * the simple format (no VP8X) cannot carry EXIF.
 *
 * @internal
 */
final class WebpParser implements ImageParser
{
    private const KEEP = ['VP8 ', 'VP8L', 'ALPH', 'ANIM', 'ANMF', 'ICCP'];

    private const FRAME_CHUNKS = ['ALPH', 'VP8 ', 'VP8L'];

    private const IMAGE_CHUNKS = ['VP8 ', 'VP8L', 'ANMF'];

    private const FLAG_EXIF = 0x08;

    private const FLAG_XMP = 0x04;

    private const MAX_EXIF = 65536;

    public function parse(Source $source): Plan
    {
        if (! $source->startsWith(0, 'RIFF') || ! $source->startsWith(8, 'WEBP')) {
            throw MetadataStripFailed::failed('not a WebP file.');
        }

        $end = 8 + $source->u32le(4);

        if ($end > $source->size()) {
            throw MetadataStripFailed::failed('WebP is truncated.');
        }

        $chunks = $this->chunks($source, 12, $end);
        $extended = $chunks !== [] && $chunks[0][0] === 'VP8X';

        if ($extended && $chunks[0][2] !== 10) {
            throw MetadataStripFailed::failed('invalid WebP VP8X chunk.');
        }

        if (! $extended && ($chunks === [] || ! in_array($chunks[0][0], ['VP8 ', 'VP8L'], true))) {
            throw MetadataStripFailed::failed('invalid WebP structure.');
        }

        $plan = new Plan($source);
        $kept = [];
        $keptLength = 0;
        $exifSeen = false;
        $image = false;
        $dropped = 0;

        foreach ($chunks as $index => [$fourcc, $offset, $size, $total]) {
            if ($fourcc === 'VP8X' && $index > 0) {
                throw MetadataStripFailed::failed('invalid WebP VP8X chunk.');
            }

            if ($fourcc === 'ANIM' && $size !== 6) {
                throw MetadataStripFailed::failed('invalid WebP ANIM chunk.');
            }

            if ($fourcc === 'ANMF') {
                $this->assertFrame($source, $offset + 8, $size);
            }

            if ($index === 0 || in_array($fourcc, self::KEEP, true)) {
                $kept[] = [$fourcc, $offset, $total];
                $keptLength += $total;
                $image = $image || in_array($fourcc, self::IMAGE_CHUNKS, true);
            } elseif ($fourcc === 'EXIF' && ! $exifSeen) {
                $exifSeen = true;
                $data = $source->bytes($offset + 8, min($size, self::MAX_EXIF));
                $tiff = str_starts_with($data, "Exif\0\0") ? substr($data, 6) : $data;
                $plan->orientation = $extended ? Orientation::fromTiff($tiff) : null;

                if (! $extended || $size !== strlen($tiff) || ! Orientation::isMinimal($tiff)) {
                    $dropped++;
                }
            } else {
                $dropped++;
            }
        }

        if (! $image) {
            throw MetadataStripFailed::failed('WebP has no image data.');
        }

        $exif = $plan->orientation === null ? '' : 'EXIF'.pack('V', 26).Orientation::tiff($plan->orientation);
        $plan->add('RIFF'.pack('V', 4 + $keptLength + strlen($exif)).'WEBP');

        foreach ($kept as [$fourcc, $offset, $total]) {
            if ($fourcc === 'VP8X') {
                $header = $source->bytes($offset, 18);
                $flags = ord($header[8]) & ~(self::FLAG_EXIF | self::FLAG_XMP);
                $written = $flags | ($exif === '' ? 0 : self::FLAG_EXIF);
                $plan->add(substr_replace($header, chr($written), 8, 1), substr_replace($header, chr($flags), 8, 1));
            } else {
                $plan->keep($offset, $total);
            }
        }

        if ($exif !== '') {
            $plan->add($exif);
        }

        for ($i = 0; $i < $dropped; $i++) {
            $plan->drop();
        }

        if ($end < $source->size()) {
            $plan->drop();
        }

        return $plan;
    }

    /**
     * The chunks between $start and $end as [fourcc, offset, data size, total size with header and padding].
     *
     * @return list<array{string, int, int, int}>
     */
    private function chunks(Source $source, int $start, int $end): array
    {
        $chunks = [];

        for ($position = $start; $position < $end; $position += $chunks[array_key_last($chunks)][3]) {
            if ($end - $position < 8 || count($chunks) >= Plan::MAX_ITEMS) {
                throw MetadataStripFailed::failed('invalid WebP chunk.');
            }

            $size = $source->u32le($position + 4);
            $total = 8 + $size + ($size & 1);

            if ($total > $end - $position) {
                throw MetadataStripFailed::failed('invalid WebP chunk size.');
            }

            $chunks[] = [$source->bytes($position, 4), $position, $size, $total];
        }

        return $chunks;
    }

    /** An animation frame: a 16-byte header followed only by ALPH / VP8 / VP8L chunks. */
    private function assertFrame(Source $source, int $offset, int $size): void
    {
        if ($size < 16) {
            throw MetadataStripFailed::failed('invalid WebP animation frame.');
        }

        $image = false;

        foreach ($this->chunks($source, $offset + 16, $offset + $size) as [$fourcc]) {
            if (! in_array($fourcc, self::FRAME_CHUNKS, true)) {
                throw MetadataStripFailed::failed('unexpected chunk in a WebP animation frame.');
            }

            $image = $image || $fourcc !== 'ALPH';
        }

        if (! $image) {
            throw MetadataStripFailed::failed('WebP animation frame has no image data.');
        }
    }
}

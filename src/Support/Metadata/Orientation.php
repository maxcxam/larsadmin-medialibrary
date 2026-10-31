<?php

namespace Maxcxam\LarsadminMedialibrary\Support\Metadata;

/**
 * Reads the Orientation tag (0x0112) from IFD0 of a TIFF structure (EXIF) and writes the minimal EXIF that carries
 * only that tag (ADR-047). Only IFD0 is read and no offsets are followed, so a malformed EXIF cannot loop.
 *
 * @internal
 */
final class Orientation
{
    private const TAG = 0x0112;

    private const TYPE_SHORT = 3;

    /** The Orientation 2–8 from a TIFF structure; null for 1, absent, invalid or a malformed structure. */
    public static function fromTiff(string $tiff): ?int
    {
        $length = strlen($tiff);

        if ($length < 8) {
            return null;
        }

        $order = substr($tiff, 0, 2);

        if ($order !== 'II' && $order !== 'MM') {
            return null;
        }

        $u16 = $order === 'II' ? 'v' : 'n';
        $u32 = $order === 'II' ? 'V' : 'N';

        if (unpack($u16, $tiff, 2)[1] !== 42) {
            return null;
        }

        $ifd = unpack($u32, $tiff, 4)[1];

        if ($ifd < 8 || $ifd > $length - 2) {
            return null;
        }

        $entries = unpack($u16, $tiff, $ifd)[1];

        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + 12 * $i;

            if ($entry + 12 > $length) {
                return null;
            }

            if (unpack($u16, $tiff, $entry)[1] !== self::TAG) {
                continue;
            }

            if (unpack($u16, $tiff, $entry + 2)[1] !== self::TYPE_SHORT || unpack($u32, $tiff, $entry + 4)[1] !== 1) {
                return null;
            }

            $value = unpack($u16, $tiff, $entry + 8)[1];

            return $value >= 2 && $value <= 8 ? $value : null;
        }

        return null;
    }

    /** The 26-byte TIFF structure (big endian) with IFD0 holding only Orientation. */
    public static function tiff(int $orientation): string
    {
        return 'MM'.pack('n', 42).pack('N', 8)
            .pack('n', 1)
            .pack('nnN', self::TAG, self::TYPE_SHORT, 1).pack('n', $orientation)."\0\0"
            .pack('N', 0);
    }

    /** Whether $tiff is exactly the minimal EXIF this class writes (so stripping a stripped file is a no-op). */
    public static function isMinimal(string $tiff): bool
    {
        $orientation = self::fromTiff($tiff);

        return $orientation !== null && $tiff === self::tiff($orientation);
    }
}

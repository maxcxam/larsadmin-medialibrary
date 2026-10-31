<?php

namespace Maxcxam\LarsadminMedialibrary\Tests\Support;

use GdImage;
use RuntimeException;

/**
 * Synthetic test images for the metadata stripper: base images rendered with GD, then metadata spliced into the
 * container (EXIF with GPS, XMP, IPTC, comments, text chunks, MPF trailers …). No real photos are used.
 */
final class ImageFactory
{
    /** Appears in every piece of injected metadata, so tests can assert it is gone from the output. */
    public const SECRET = 'SECRET-METADATA';

    // ---------------------------------------------------------------- base images (GD)

    public static function canvas(int $width = 48, int $height = 32): GdImage
    {
        $image = imagecreatetruecolor($width, $height);

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                imagesetpixel($image, $x, $y, imagecolorallocate($image, (int) ($x * 255 / $width), (int) ($y * 255 / $height), ($x * $y) % 256));
            }
        }

        imagefilledellipse($image, intdiv($width, 2), intdiv($height, 2), intdiv($width, 2), intdiv($height, 2), imagecolorallocate($image, 250, 250, 20));

        return $image;
    }

    public static function jpeg(int $width = 48, int $height = 32, bool $progressive = false): string
    {
        $image = self::canvas($width, $height);
        imageinterlace($image, $progressive);

        return self::capture(fn () => imagejpeg($image, null, 85));
    }

    public static function png(int $width = 48, int $height = 32): string
    {
        $image = self::canvas($width, $height);

        return self::capture(fn () => imagepng($image));
    }

    public static function webp(int $width = 48, int $height = 32, bool $lossless = false): string
    {
        $image = self::canvas($width, $height);

        return self::capture(fn () => imagewebp($image, null, $lossless ? IMG_WEBP_LOSSLESS : 80));
    }

    public static function gif(int $width = 48, int $height = 32): string
    {
        $image = self::canvas($width, $height);
        imagetruecolortopalette($image, false, 64);

        return self::capture(fn () => imagegif($image));
    }

    /**
     * A tiny AVIF: the committed 8×8 fixture (CI's GD may lack libavif), or rendered with imageavif() when another
     * size is asked for and GD supports it.
     */
    public static function avif(int $width = 8, int $height = 8): string
    {
        $fixture = dirname(__DIR__).'/Fixtures/images/tiny.avif';

        if (($width === 8 && $height === 8) || ! function_exists('imageavif')) {
            return file_get_contents($fixture);
        }

        $image = self::canvas($width, $height);

        return self::capture(fn () => imageavif($image));
    }

    // ---------------------------------------------------------------- EXIF (TIFF structure)

    /**
     * A camera-like EXIF: IFD0 (make, model, software, date, artist, optional Orientation), a GPS IFD and an IFD1
     * with a JPEG thumbnail.
     */
    public static function cameraExif(?int $orientation = null, bool $littleEndian = true, bool $thumbnail = true): string
    {
        $ifd0 = [
            0x010F => [2, 'Pixelphone'],
            0x0110 => [2, 'Pixelphone 9 Pro '.self::SECRET],
            0x0131 => [2, 'CameraApp 1.0'],
            0x0132 => [2, '2026:10:31 12:34:56'],
            0x013B => [2, 'Jane Doe '.self::SECRET],
        ];

        if ($orientation !== null) {
            $ifd0[0x0112] = [3, $orientation];
        }

        $gps = [
            0x0001 => [2, 'N'],
            0x0002 => [5, [[50, 1], [27, 1], [1234, 100]]],
            0x0003 => [2, 'E'],
            0x0004 => [5, [[30, 1], [31, 1], [2345, 100]]],
            0x001B => [7, 'ASCII'."\0\0\0".self::SECRET],
        ];

        return self::tiff($ifd0, $gps, $littleEndian, $thumbnail ? self::jpeg(8, 8) : null);
    }

    /**
     * @param  array<int, array{int, mixed}>  $ifd0  tag => [type, value]; types 2 ASCII, 3 SHORT, 4 LONG, 5 RATIONAL, 7 UNDEFINED
     * @param  array<int, array{int, mixed}>  $gps
     */
    public static function tiff(array $ifd0, array $gps = [], bool $littleEndian = true, ?string $thumbnail = null): string
    {
        $u16 = $littleEndian ? 'v' : 'n';
        $u32 = $littleEndian ? 'V' : 'N';

        if ($gps !== []) {
            $ifd0[0x8825] = [4, 0];
        }

        $ifd1 = $thumbnail === null ? [] : [0x0103 => [3, 6], 0x0201 => [4, 0], 0x0202 => [4, strlen($thumbnail)]];

        $gpsOffset = 8 + self::ifdSize($ifd0);
        $ifd1Offset = $gpsOffset + ($gps === [] ? 0 : self::ifdSize($gps));
        $thumbnailOffset = $ifd1Offset + ($ifd1 === [] ? 0 : self::ifdSize($ifd1));

        if ($gps !== []) {
            $ifd0[0x8825] = [4, $gpsOffset];
        }

        if ($ifd1 !== []) {
            $ifd1[0x0201] = [4, $thumbnailOffset];
        }

        return ($littleEndian ? 'II' : 'MM').pack($u16, 42).pack($u32, 8)
            .self::ifd($ifd0, 8, $littleEndian, $ifd1 === [] ? 0 : $ifd1Offset)
            .($gps === [] ? '' : self::ifd($gps, $gpsOffset, $littleEndian, 0))
            .($ifd1 === [] ? '' : self::ifd($ifd1, $ifd1Offset, $littleEndian, 0))
            .($thumbnail ?? '');
    }

    /** @param array<int, array{int, mixed}> $entries */
    private static function ifdSize(array $entries): int
    {
        $size = 2 + 12 * count($entries) + 4;

        foreach ($entries as [$type, $value]) {
            $bytes = strlen(self::value($type, $value, true));
            $size += $bytes > 4 ? $bytes + ($bytes & 1) : 0;
        }

        return $size;
    }

    /** @param array<int, array{int, mixed}> $entries */
    private static function ifd(array $entries, int $offset, bool $littleEndian, int $next): string
    {
        ksort($entries);
        $u16 = $littleEndian ? 'v' : 'n';
        $u32 = $littleEndian ? 'V' : 'N';
        $dataOffset = $offset + 2 + 12 * count($entries) + 4;
        $ifd = pack($u16, count($entries));
        $data = '';

        foreach ($entries as $tag => [$type, $value]) {
            $bytes = self::value($type, $value, $littleEndian);
            $count = match ($type) {
                2, 7 => strlen($bytes),
                3 => intdiv(strlen($bytes), 2),
                4 => intdiv(strlen($bytes), 4),
                5 => intdiv(strlen($bytes), 8),
            };

            if (strlen($bytes) <= 4) {
                $field = str_pad($bytes, 4, "\0");
            } else {
                $field = pack($u32, $dataOffset + strlen($data));
                $data .= $bytes.(strlen($bytes) % 2 === 1 ? "\0" : '');
            }

            $ifd .= pack($u16, $tag).pack($u16, $type).pack($u32, $count).$field;
        }

        return $ifd.pack($u32, $next).$data;
    }

    private static function value(int $type, mixed $value, bool $littleEndian): string
    {
        $u16 = $littleEndian ? 'v' : 'n';
        $u32 = $littleEndian ? 'V' : 'N';

        return match ($type) {
            2 => $value."\0",
            3 => pack($u16, $value),
            4 => pack($u32, $value),
            5 => implode('', array_map(fn (array $r) => pack($u32, $r[0]).pack($u32, $r[1]), $value)),
            7 => $value,
        };
    }

    public static function xmp(): string
    {
        return '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/">'
            .'<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description '
            .'xmlns:exif="http://ns.adobe.com/exif/1.0/" xmlns:dc="http://purl.org/dc/elements/1.1/" '
            .'exif:GPSLatitude="50,27.2N" exif:GPSLongitude="30,31.4E" dc:creator="Jane Doe '.self::SECRET.'"/>'
            .'</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
    }

    /** A fake (structurally plausible) ICC profile; colour management data, not personal data. */
    public static function iccProfile(): string
    {
        return pack('N', 160).'lcms'.pack('N', 0x02100000).'mntrRGB XYZ '.str_repeat("\0", 12).'acsp'.str_repeat("\x11", 128);
    }

    // ---------------------------------------------------------------- JPEG

    public static function segment(int $marker, string $data): string
    {
        return "\xFF".chr($marker).pack('n', strlen($data) + 2).$data;
    }

    public static function exifSegment(string $tiff): string
    {
        return self::segment(0xE1, "Exif\0\0".$tiff);
    }

    public static function xmpSegment(): string
    {
        return self::segment(0xE1, "http://ns.adobe.com/xap/1.0/\0".self::xmp());
    }

    public static function iptcSegment(): string
    {
        $byline = 'Jane Doe '.self::SECRET;
        $iptc = "\x1C\x02\x50".pack('n', strlen($byline)).$byline."\x1C\x02\x5A".pack('n', 4).'Kyiv';
        $resource = '8BIM'.pack('n', 0x0404)."\0\0".pack('N', strlen($iptc)).$iptc.(strlen($iptc) % 2 ? "\0" : '');

        return self::segment(0xED, "Photoshop 3.0\0".$resource);
    }

    public static function commentSegment(): string
    {
        return self::segment(0xFE, 'comment '.self::SECRET);
    }

    public static function mpfSegment(): string
    {
        return self::segment(0xE2, "MPF\0MM\0*".pack('N', 8).self::SECRET);
    }

    public static function iccSegment(): string
    {
        return self::segment(0xE2, "ICC_PROFILE\0\x01\x01".self::iccProfile());
    }

    public static function adobeSegment(): string
    {
        return self::segment(0xEE, 'Adobe'.pack('n', 100).pack('n', 0).pack('n', 0)."\x01");
    }

    /**
     * Splices segments into a JPEG: $head right after SOI and APP0, $tables before the first SOS, $betweenScans before
     * the second SOS (progressive), and $trailer after EOI.
     *
     * @param  list<string>  $head
     * @param  list<string>  $tables
     * @param  list<string>  $betweenScans
     */
    public static function withJpeg(string $jpeg, array $head = [], array $tables = [], array $betweenScans = [], string $trailer = ''): string
    {
        $markers = self::jpegMarkers($jpeg);
        $sos = array_values(array_filter($markers, fn (array $m) => $m[0] === 0xDA));
        $app0 = array_values(array_filter($markers, fn (array $m) => $m[0] === 0xE0));
        $afterApp0 = $app0 === [] ? 2 : $app0[0][1] + 2 + unpack('n', $jpeg, $app0[0][1] + 2)[1];

        $out = substr($jpeg, 0, $afterApp0).implode('', $head).substr($jpeg, $afterApp0, $sos[0][1] - $afterApp0).implode('', $tables);

        if ($betweenScans !== []) {
            $out .= substr($jpeg, $sos[0][1], $sos[1][1] - $sos[0][1]).implode('', $betweenScans).substr($jpeg, $sos[1][1]);
        } else {
            $out .= substr($jpeg, $sos[0][1]);
        }

        return $out.$trailer;
    }

    /**
     * Every marker of a JPEG as [code, offset of 0xFF], walking segments and entropy-coded data.
     *
     * @return list<array{int, int}>
     */
    public static function jpegMarkers(string $jpeg): array
    {
        $markers = [[0xD8, 0]];
        $position = 2;

        while ($position < strlen($jpeg)) {
            $code = ord($jpeg[$position + 1]);
            $markers[] = [$code, $position];

            if ($code === 0xD9) {
                break;
            }

            $position += 2 + unpack('n', $jpeg, $position + 2)[1];

            if ($code === 0xDA) {
                while (! ($jpeg[$position] === "\xFF" && ! in_array(ord($jpeg[$position + 1]), [0x00, 0xD0, 0xD1, 0xD2, 0xD3, 0xD4, 0xD5, 0xD6, 0xD7], true))) {
                    $position++;
                }
            }
        }

        return $markers;
    }

    /** The marker codes before the first SOS. @return list<int> */
    public static function jpegHeaderCodes(string $jpeg): array
    {
        $codes = [];

        foreach (self::jpegMarkers($jpeg) as [$code]) {
            if ($code === 0xDA) {
                break;
            }

            $codes[] = $code;
        }

        return $codes;
    }

    /** The bytes from the first SOS to EOI inclusive (the compressed pixel data). */
    public static function jpegScans(string $jpeg): string
    {
        $markers = self::jpegMarkers($jpeg);
        $sos = array_values(array_filter($markers, fn (array $m) => $m[0] === 0xDA))[0][1];
        $eoi = array_values(array_filter($markers, fn (array $m) => $m[0] === 0xD9))[0][1];

        return substr($jpeg, $sos, $eoi + 2 - $sos);
    }

    /** The data of every segment with this marker. @return list<string> */
    public static function jpegSegments(string $jpeg, int $code): array
    {
        $segments = [];

        foreach (self::jpegMarkers($jpeg) as [$marker, $offset]) {
            if ($marker === $code) {
                $segments[] = substr($jpeg, $offset + 4, unpack('n', $jpeg, $offset + 2)[1] - 2);
            }
        }

        return $segments;
    }

    // ---------------------------------------------------------------- PNG

    public static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    /**
     * Chunks of a PNG as [type, data].
     *
     * @return list<array{string, string}>
     */
    public static function pngChunks(string $png): array
    {
        $chunks = [];

        for ($position = 8; $position < strlen($png); $position += 12 + $length) {
            $length = unpack('N', $png, $position)[1];
            $chunks[] = [substr($png, $position + 4, 4), substr($png, $position + 8, $length)];
        }

        return $chunks;
    }

    /**
     * Splices chunks into a PNG: $afterHeader after IHDR, $beforeData before the first IDAT, $afterData before IEND,
     * $trailer after IEND.
     *
     * @param  list<string>  $afterHeader
     * @param  list<string>  $beforeData
     * @param  list<string>  $afterData
     */
    public static function withPng(string $png, array $afterHeader = [], array $beforeData = [], array $afterData = [], string $trailer = ''): string
    {
        $out = substr($png, 0, 8);
        $first = true;

        foreach (self::pngChunks($png) as [$type, $data]) {
            if ($type === 'IDAT' && $first) {
                $out .= implode('', $beforeData);
                $first = false;
            }

            if ($type === 'IEND') {
                $out .= implode('', $afterData);
            }

            $out .= self::chunk($type, $data);

            if ($type === 'IHDR') {
                $out .= implode('', $afterHeader);
            }
        }

        return $out.$trailer;
    }

    /** The concatenated IDAT data. */
    public static function pngData(string $png): string
    {
        return implode('', array_map(fn (array $c) => $c[1], array_filter(self::pngChunks($png), fn (array $c) => $c[0] === 'IDAT')));
    }

    /** An APNG of two frames: the default image (also frame 1) and a second frame reusing its data. */
    public static function apng(string $png): string
    {
        $ihdr = self::pngChunks($png)[0][1];
        $fctl = fn (int $sequence) => self::chunk('fcTL', pack('N', $sequence).substr($ihdr, 0, 8).pack('NN', 0, 0).pack('nn', 1, 10)."\0\0");
        $fdat = self::chunk('fdAT', pack('N', 2).self::pngData($png));

        return self::withPng($png, beforeData: [self::chunk('acTL', pack('NN', 2, 0)), $fctl(0)], afterData: [$fctl(1), $fdat]);
    }

    // ---------------------------------------------------------------- WebP

    public static function webpChunk(string $fourcc, string $data): string
    {
        return $fourcc.pack('V', strlen($data)).$data.(strlen($data) % 2 === 1 ? "\0" : '');
    }

    public static function riff(string ...$chunks): string
    {
        $body = 'WEBP'.implode('', $chunks);

        return 'RIFF'.pack('V', strlen($body)).$body;
    }

    /**
     * Chunks of a WebP as [fourcc, data].
     *
     * @return list<array{string, string}>
     */
    public static function webpChunks(string $webp): array
    {
        $chunks = [];
        $end = 8 + unpack('V', $webp, 4)[1];

        for ($position = 12; $position < $end; $position += 8 + $size + ($size & 1)) {
            $size = unpack('V', $webp, $position + 4)[1];
            $chunks[] = [substr($webp, $position, 4), substr($webp, $position + 8, $size)];
        }

        return $chunks;
    }

    public static function vp8x(int $flags, int $width, int $height): string
    {
        return self::webpChunk('VP8X', chr($flags)."\0\0\0".substr(pack('V', $width - 1), 0, 3).substr(pack('V', $height - 1), 0, 3));
    }

    /**
     * An extended WebP: VP8X (flags from the chunks given), $before (e.g. ICCP), the image of $simple, $after
     * (e.g. EXIF, XMP).
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public static function webpExtended(string $simple, int $width, int $height, array $before = [], array $after = []): string
    {
        $all = implode('', [...$before, ...$after]);
        $flags = (str_contains($all, 'ICCP') ? 0x20 : 0) | (str_contains($all, 'EXIF') ? 0x08 : 0) | (str_contains($all, 'XMP ') ? 0x04 : 0);
        $image = array_map(fn (array $c) => self::webpChunk($c[0], $c[1]), self::webpChunks($simple));

        return self::riff(self::vp8x($flags, $width, $height), ...$before, ...$image, ...$after);
    }

    /** An animated WebP of the given simple WebP frames. @param list<string> $frames */
    public static function webpAnimated(array $frames, int $width, int $height, array $after = []): string
    {
        $anmf = array_map(function (string $frame) use ($width, $height) {
            $image = implode('', array_map(fn (array $c) => self::webpChunk($c[0], $c[1]), self::webpChunks($frame)));
            $header = "\0\0\0\0\0\0".substr(pack('V', $width - 1), 0, 3).substr(pack('V', $height - 1), 0, 3).substr(pack('V', 100), 0, 3)."\0";

            return self::webpChunk('ANMF', $header.$image);
        }, $frames);

        return self::riff(self::vp8x(0x02, $width, $height), self::webpChunk('ANIM', "\0\0\0\0\0\0"), ...$anmf, ...$after);
    }

    // ---------------------------------------------------------------- GIF

    public static function gifExtension(int $label, string $data): string
    {
        return "\x21".chr($label).self::subBlocks($data);
    }

    public static function gifApplication(string $identifier, string $data): string
    {
        return "\x21\xFF\x0B".$identifier.self::subBlocks($data);
    }

    public static function gifLooping(): string
    {
        return self::gifApplication('NETSCAPE2.0', "\x01\x00\x00");
    }

    public static function subBlocks(string $data): string
    {
        $out = '';

        foreach (str_split($data, 255) as $block) {
            $out .= $data === '' ? '' : chr(strlen($block)).$block;
        }

        return $out."\0";
    }

    /**
     * Splices extensions into a GIF (made GIF89a) right after the global colour table, and $trailer after the trailer.
     *
     * @param  list<string>  $extensions
     */
    public static function withGif(string $gif, array $extensions = [], string $trailer = ''): string
    {
        $packed = ord($gif[10]);
        $table = ($packed & 0x80) === 0 ? 0 : 3 * (2 << ($packed & 0x07));
        $head = 13 + $table;

        return 'GIF89a'.substr($gif, 6, $head - 6).implode('', $extensions).substr($gif, $head).$trailer;
    }

    // ---------------------------------------------------------------- helpers

    /** Decoded pixels (GD), for comparing that two files show the same image. */
    public static function pixels(string $bytes): string
    {
        $image = @imagecreatefromstring($bytes); // libpng warns about the synthetic ICC profile, then decodes

        if ($image === false) {
            throw new RuntimeException('GD cannot decode the image.');
        }

        $pixels = '';

        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                $pixels .= pack('N', imagecolorat($image, $x, $y));
            }
        }

        return $pixels;
    }

    private static function capture(callable $render): string
    {
        ob_start();

        try {
            $render();
        } finally {
            $bytes = ob_get_clean();
        }

        return $bytes;
    }
}

<?php

namespace Maxcxam\LarsadminMedialibrary\Support\Metadata;

use Maxcxam\LarsadminMedialibrary\Support\MetadataStripFailed;

/**
 * JPEG (baseline, progressive, arithmetic): keeps SOI, DQT, DHT, DAC, SOF*, DRI, SOS + entropy-coded data (with RSTn),
 * EOI, APP0 JFIF/JFXX, APP2 ICC_PROFILE and APP14 Adobe; drops APP1 (Exif, XMP), other APP2 (MPF), APP3вЂ“APP13,
 * APP15, COM and everything after the first EOI. Any other marker fails. Fill bytes before markers are dropped.
 *
 * @internal
 */
final class JpegParser implements ImageParser
{
    private const MAX_FILL = 65536;

    private const SOI = 0xD8;

    private const EOI = 0xD9;

    private const SOS = 0xDA;

    private const APP0 = 0xE0;

    private const APP1 = 0xE1;

    private const APP2 = 0xE2;

    private const APP14 = 0xEE;

    private const COM = 0xFE;

    /** DHT, DAC, DQT, DRI: coding tables, kept anywhere before EOI. */
    private const TABLES = [0xC4, 0xCC, 0xDB, 0xDD];

    /** SOF0вЂ“3, 5вЂ“7, 9вЂ“11, 13вЂ“15 (C4 DHT, C8 JPG and CC DAC are not frames). */
    private const FRAMES = [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF];

    public function parse(Source $source): Plan
    {
        if (! $source->startsWith(0, "\xFF\xD8\xFF")) {
            throw MetadataStripFailed::failed('not a JPEG file.');
        }

        $plan = new Plan($source);
        $plan->keep(0, 2);

        $exifMark = null;
        $exifSeen = false;
        $frame = false;
        $scanned = false;
        $position = 2;

        while (true) {
            [$marker, $position] = $this->marker($source, $position);

            if ($marker === self::EOI) {
                if (! $scanned) {
                    throw MetadataStripFailed::failed('JPEG ends before the image data.');
                }

                $plan->add("\xFF\xD9", "\xFF\xD9");
                break;
            }

            if ($marker === self::SOI || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                throw MetadataStripFailed::failed(sprintf('unexpected JPEG marker 0x%02X.', $marker));
            }

            $length = $source->u16be($position);

            if ($length < 2 || $length > $source->size() - $position) {
                throw MetadataStripFailed::failed('invalid JPEG segment length.');
            }

            $keep = match (true) {
                $marker >= self::APP0 && $marker <= 0xEF => $this->keepApp($marker, $source, $position + 2, $length - 2),
                $marker === self::COM => false,
                in_array($marker, self::TABLES, true) => true,
                in_array($marker, self::FRAMES, true) => ! $frame && ! $scanned,
                $marker === self::SOS => $frame,
                default => throw MetadataStripFailed::failed(sprintf('unsupported JPEG marker 0x%02X.', $marker)),
            };

            if (in_array($marker, self::FRAMES, true)) {
                if (! $keep) {
                    throw MetadataStripFailed::failed('unsupported JPEG frame structure.');
                }

                $frame = true;
            }

            if ($marker === self::SOS && ! $keep) {
                throw MetadataStripFailed::failed('JPEG scan before the frame header.');
            }

            if ($keep) {
                $this->assertStructure($marker, $source->bytes($position + 2, $length - 2));

                if ($exifMark === null && ! $this->isJfif($marker, $source, $position + 2, $length - 2)) {
                    $exifMark = $plan->mark();
                }

                $code = "\xFF".chr($marker);
                $plan->add($code, $code);
                $plan->keep($position, $length);
            } elseif ($marker === self::APP1 && $this->isExif($source, $position + 2, $length - 2)) {
                $tiff = $source->bytes($position + 8, $length - 8);

                if ($exifSeen) {
                    $plan->drop();
                } else {
                    $exifSeen = true;
                    $plan->orientation = Orientation::fromTiff($tiff);

                    if (! Orientation::isMinimal($tiff)) {
                        $plan->drop();
                    }
                }
            } else {
                $plan->drop();
            }

            $position += $length;

            if ($marker === self::SOS) {
                $position = $this->entropy($source, $plan, $position);
                $scanned = true;
            }
        }

        if ($position < $source->size()) {
            $plan->drop();
        }

        if ($plan->orientation !== null && $exifMark !== null) {
            $plan->insert($exifMark, "\xFF\xE1".pack('n', 2 + 6 + 26)."Exif\0\0".Orientation::tiff($plan->orientation));
        }

        return $plan;
    }

    /**
     * The marker code at $position (0xFF, optional fill bytes, code) and the offset after it.
     *
     * @return array{int, int}
     */
    private function marker(Source $source, int $position): array
    {
        if ($source->byte($position) !== 0xFF) {
            throw MetadataStripFailed::failed('JPEG marker expected.');
        }

        $position++;
        $fill = 0;

        while (($code = $source->byte($position)) === 0xFF) {
            $position++;

            if (++$fill > self::MAX_FILL) {
                throw MetadataStripFailed::failed('too many JPEG fill bytes.');
            }
        }

        return [$code, $position + 1];
    }

    /** Keeps entropy-coded data up to the next marker that is not RSTn or a stuffed byte; returns that marker's offset. */
    private function entropy(Source $source, Plan $plan, int $start): int
    {
        $position = $start;

        while (true) {
            $ff = $source->find("\xFF", $position);
            $next = $ff + 1;
            $fill = 0;

            while (($code = $source->byte($next)) === 0xFF) {
                $next++;

                if (++$fill > self::MAX_FILL) {
                    throw MetadataStripFailed::failed('too many JPEG fill bytes.');
                }
            }

            if ($code === 0x00 || ($code >= 0xD0 && $code <= 0xD7)) {
                $position = $next + 1;

                continue;
            }

            if ($ff > $start) {
                $plan->keep($start, $ff - $start);
            }

            return $ff;
        }
    }

    /**
     * Kept segments with a fixed layout must fill their length exactly, so nothing (metadata of a damaged or crafted
     * file) can hide in their tail. ICC_PROFILE and JFXX data are opaque and kept as is (ADR-047).
     */
    private function assertStructure(int $marker, string $data): void
    {
        $length = strlen($data);

        $valid = match (true) {
            $marker === 0xDB => $this->validQuantization($data),
            $marker === 0xC4 => $this->validHuffman($data),
            $marker === 0xCC => $length > 0 && $length % 2 === 0,
            $marker === 0xDD => $length === 2,
            $marker === self::SOS => $length >= 6 && ord($data[0]) >= 1 && ord($data[0]) <= 4 && $length === 4 + 2 * ord($data[0]),
            in_array($marker, self::FRAMES, true) => $length >= 9 && ord($data[5]) >= 1 && $length === 6 + 3 * ord($data[5]),
            $marker === self::APP0 && str_starts_with($data, "JFIF\0") => $length >= 14 && $length === 14 + 3 * ord($data[12]) * ord($data[13]),
            $marker === self::APP14 => $length === 12,
            default => true,
        };

        if (! $valid) {
            throw MetadataStripFailed::failed(sprintf('malformed JPEG segment 0x%02X.', $marker));
        }
    }

    /** DQT: tables of 1 + 64 (8-bit) or 1 + 128 (16-bit) bytes. */
    private function validQuantization(string $data): bool
    {
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $info = ord($data[$offset]);
            $table = 1 + 64 * (1 + ($info >> 4));

            if (($info >> 4) > 1 || ($info & 0x0F) > 3 || $table > $length - $offset) {
                return false;
            }

            $offset += $table;
        }

        return $length > 0;
    }

    /** DHT: tables of 1 + 16 code counts + as many values. */
    private function validHuffman(string $data): bool
    {
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $class = ord($data[$offset]);

            if (($class >> 4) > 1 || ($class & 0x0F) > 3 || $length - $offset < 17) {
                return false;
            }

            $values = array_sum(unpack('C16', $data, $offset + 1));

            if ($values > 256 || 17 + $values > $length - $offset) {
                return false;
            }

            $offset += 17 + $values;
        }

        return $length > 0;
    }

    private function keepApp(int $marker, Source $source, int $offset, int $length): bool
    {
        return match ($marker) {
            self::APP0 => $this->isJfif($marker, $source, $offset, $length),
            self::APP2 => $this->has($source, $offset, $length, "ICC_PROFILE\0"),
            self::APP14 => $this->has($source, $offset, $length, 'Adobe'),
            default => false,
        };
    }

    private function isJfif(int $marker, Source $source, int $offset, int $length): bool
    {
        return $marker === self::APP0
            && ($this->has($source, $offset, $length, "JFIF\0") || $this->has($source, $offset, $length, "JFXX\0"));
    }

    private function isExif(Source $source, int $offset, int $length): bool
    {
        return $this->has($source, $offset, $length, "Exif\0\0");
    }

    private function has(Source $source, int $offset, int $length, string $identifier): bool
    {
        return $length >= strlen($identifier) && $source->startsWith($offset, $identifier);
    }
}

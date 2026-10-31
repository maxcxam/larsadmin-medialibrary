<?php

namespace Maxcxam\LarsadminMedialibrary\Support;

use Maxcxam\LarsadminMedialibrary\Support\Metadata\GifParser;
use Maxcxam\LarsadminMedialibrary\Support\Metadata\ImageParser;
use Maxcxam\LarsadminMedialibrary\Support\Metadata\JpegParser;
use Maxcxam\LarsadminMedialibrary\Support\Metadata\Plan;
use Maxcxam\LarsadminMedialibrary\Support\Metadata\PngParser;
use Maxcxam\LarsadminMedialibrary\Support\Metadata\Source;
use Maxcxam\LarsadminMedialibrary\Support\Metadata\WebpParser;
use Throwable;

/**
 * Strips metadata (EXIF incl. GPS, XMP, IPTC, comments, text chunks …) from JPEG, PNG/APNG, WebP and GIF by
 * rewriting the container, never re-encoding: pixel data is copied byte for byte, ICC profiles and animation stay
 * (ADR-047, design 1.0 §3.1). Allow-lists decide what is kept, so unknown metadata vanishes. A non-trivial EXIF
 * Orientation (2–8) is carried over as a minimal EXIF holding only that tag.
 *
 * Fail closed: an unsupported image type, malformed or truncated input, an exceeded limit, a failed self-check or an
 * I/O error throws {@see MetadataStripFailed} and leaves no output file. The source file is never modified.
 *
 * The self-check re-parses the output: nothing may be left to drop, the Orientation and the hash of every kept
 * byte (pixel payload included) must match the source, and getimagesize() must report the same width, height and
 * type. Stripping is idempotent: strip(strip(x)) is byte-identical to strip(x).
 *
 * @internal
 */
class MetadataStripper
{
    public const DEFAULT_MAX_BYTES = 134217728;

    /** Content-sniffed MIME types => format. */
    private const FORMATS = [
        'image/jpeg' => 'jpeg',
        'image/pjpeg' => 'jpeg',
        'image/png' => 'png',
        'image/apng' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    private const EXTENSIONS = ['jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp', 'gif' => 'gif'];

    /** getimagesize() types, so a mismatching signature cannot be stripped as another format. */
    private const IMAGE_TYPES = ['jpeg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP, 'gif' => IMAGETYPE_GIF];

    public function __construct(protected int $maxBytes = self::DEFAULT_MAX_BYTES) {}

    /** Whether files of this (content-sniffed) MIME type are stripped at all: every image/* type. */
    public static function applies(string $mime): bool
    {
        return str_starts_with(self::normalize($mime), 'image/');
    }

    /** Whether the image type can be stripped; other image/* types are rejected by {@see strip()}. */
    public static function supports(string $mime): bool
    {
        return isset(self::FORMATS[self::normalize($mime)]);
    }

    /**
     * Writes a stripped copy of $path into $directory and returns its path. Pass a directory from
     * {@see StripWorkspace::create()}; without one a new workspace directory is created, which the caller deletes
     * with {@see StripWorkspace::delete()} (dirname of the result). The file is created exclusively with mode 0600.
     * A non-image $mime returns $path unchanged (only images are stripped).
     *
     * @param  string  $mime  the content-sniffed MIME type (finfo), not the client-provided one
     *
     * @throws MetadataStripFailed
     */
    public function strip(string $path, string $mime, ?string $directory = null): string
    {
        if (! self::applies($mime)) {
            return $path;
        }

        $format = self::FORMATS[self::normalize($mime)] ?? throw MetadataStripFailed::unsupported(self::normalize($mime));
        $target = null;
        $workspace = null;

        try {
            $source = Source::open($path);

            if ($source->size() > $this->maxBytes) {
                throw MetadataStripFailed::failed('the file exceeds the size limit.');
            }

            $plan = $this->parser($format)->parse($source);

            if ($directory === null) {
                $workspace = new StripWorkspace;
                $directory = $workspace->create();
            }

            $target = $this->write($plan, $format, $directory);
            $source->close();

            $this->selfCheck($format, $path, $target, $plan);

            return $target;
        } catch (Throwable $e) {
            if ($target !== null && is_file($target)) {
                @unlink($target);
            }

            $workspace?->delete($directory);

            throw $e instanceof MetadataStripFailed ? $e : MetadataStripFailed::failed('unexpected error.', $e);
        }
    }

    protected function parser(string $format): ImageParser
    {
        return match ($format) {
            'jpeg' => new JpegParser,
            'png' => new PngParser,
            'webp' => new WebpParser,
            'gif' => new GifParser,
        };
    }

    private function write(Plan $plan, string $format, string $directory): string
    {
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw MetadataStripFailed::failed('the target directory is not writable.');
        }

        $target = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'stripped-'.bin2hex(random_bytes(16)).'.'.self::EXTENSIONS[$format];
        $umask = umask(0077);

        try {
            $out = @fopen($target, 'xb'); // exclusive: fails if the path exists (including a planted symlink)
        } finally {
            umask($umask);
        }

        if ($out === false) {
            throw MetadataStripFailed::failed('the stripped file cannot be created.');
        }

        @chmod($target, 0600);

        try {
            $plan->writeTo($out);

            if (! fflush($out)) {
                throw MetadataStripFailed::failed('the stripped file cannot be written.');
            }
        } catch (Throwable $e) {
            fclose($out);
            @unlink($target);

            throw $e;
        }

        fclose($out);

        return $target;
    }

    private function selfCheck(string $format, string $path, string $target, Plan $plan): void
    {
        $output = Source::open($target);

        try {
            $check = $this->parser($format)->parse($output);
            $size = $output->size();
        } catch (MetadataStripFailed $e) {
            throw MetadataStripFailed::failed('the stripped file does not parse.', $e);
        } finally {
            $output->close();
        }

        if ($check->dropped !== 0
            || $check->orientation !== $plan->orientation
            || $check->length() !== $size
            || $size !== $plan->length()
            || ! hash_equals($plan->payloadHash(), $check->payloadHash())) {
            throw MetadataStripFailed::failed('the self-check of the stripped file failed.');
        }

        $before = @getimagesize($path);
        $after = @getimagesize($target);

        if ($before === false || $after === false
            || $before[2] !== self::IMAGE_TYPES[$format]
            || [$before[0], $before[1], $before[2]] !== [$after[0], $after[1], $after[2]]) {
            throw MetadataStripFailed::failed('the image size changed or cannot be read.');
        }
    }

    private static function normalize(string $mime): string
    {
        return strtolower(trim(explode(';', $mime, 2)[0]));
    }
}

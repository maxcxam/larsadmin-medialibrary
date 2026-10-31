<?php

namespace Maxcxam\LarsadminMedialibrary\Support;

use RuntimeException;
use Throwable;

/**
 * Metadata could not be stripped, so the file must not be stored (ADR-047, fail closed).
 *
 * The message is for logs only and may name parser details; user-facing code shows a generic, translated message
 * picked by {@see self::$reason} (`metadata_unsupported` / `metadata_failed`).
 *
 * @internal
 */
class MetadataStripFailed extends RuntimeException
{
    /** The image type is not supported by the stripper (AVIF, HEIC, TIFF, BMP, SVG, ICO …). */
    public const UNSUPPORTED = 'unsupported';

    /** The file is malformed or truncated, exceeds a limit, failed the self-check or could not be read / written. */
    public const FAILED = 'failed';

    final public function __construct(public readonly string $reason, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function unsupported(string $mime): static
    {
        return new static(self::UNSUPPORTED, "Metadata stripping does not support [{$mime}].");
    }

    public static function failed(string $detail, ?Throwable $previous = null): static
    {
        return new static(self::FAILED, "Metadata stripping failed: {$detail}", $previous);
    }

    public function isUnsupported(): bool
    {
        return $this->reason === self::UNSUPPORTED;
    }
}

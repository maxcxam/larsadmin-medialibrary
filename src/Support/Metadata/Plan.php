<?php

namespace Maxcxam\LarsadminMedialibrary\Support\Metadata;

use HashContext;
use Maxcxam\LarsadminMedialibrary\Support\MetadataStripFailed;

/**
 * The stripped file as a list of parts in output order: byte ranges of the source (kept segments / chunks, copied
 * verbatim) and literal bytes (rewritten headers, the minimal Orientation EXIF). The payload hash covers every kept
 * byte that must survive unchanged, so the self-check can compare it between the source and the output.
 *
 * @internal
 */
final class Plan
{
    /** Bounds the work on files made of millions of tiny segments / chunks / blocks. */
    public const MAX_ITEMS = 100000;

    /** @var list<array{int, int}|string> */
    private array $parts = [];

    private int $items = 0;

    private int $length = 0;

    /** Removed items: segments / chunks / blocks / trailing data (a canonical Orientation EXIF is not counted). */
    public int $dropped = 0;

    /** The Orientation (2–8) carried over as a minimal EXIF, or null. */
    public ?int $orientation = null;

    private HashContext $payload;

    public function __construct(private readonly Source $source)
    {
        $this->payload = hash_init('sha256');
    }

    /** Keeps a source range verbatim; it is part of the payload. */
    public function keep(int $offset, int $length): void
    {
        $this->count();
        $this->source->hashInto($this->payload, $offset, $length);
        $this->parts[] = [$offset, $length];
        $this->length += $length;
    }

    /** Writes literal bytes; $payload is what the payload hash sees for them (null: not part of the payload). */
    public function add(string $bytes, ?string $payload = null): void
    {
        $this->count();

        if ($payload !== null) {
            hash_update($this->payload, $payload);
        }

        $this->parts[] = $bytes;
        $this->length += strlen($bytes);
    }

    public function drop(): void
    {
        $this->count();
        $this->dropped++;
    }

    /** The position for a later {@see insert()} (the minimal EXIF is placed once the whole file is parsed). */
    public function mark(): int
    {
        return count($this->parts);
    }

    public function insert(int $mark, string $bytes): void
    {
        array_splice($this->parts, $mark, 0, [$bytes]);
        $this->length += strlen($bytes);
    }

    public function length(): int
    {
        return $this->length;
    }

    public function payloadHash(): string
    {
        return hash_final(hash_copy($this->payload));
    }

    /** @param resource $out */
    public function writeTo($out): void
    {
        foreach ($this->parts as $part) {
            if (is_string($part)) {
                if (@fwrite($out, $part) !== strlen($part)) {
                    throw MetadataStripFailed::failed('the stripped file cannot be written.');
                }
            } else {
                $this->source->copyTo($out, $part[0], $part[1]);
            }
        }
    }

    private function count(): void
    {
        if (++$this->items > self::MAX_ITEMS) {
            throw MetadataStripFailed::failed('too many segments.');
        }
    }
}

<?php

namespace Maxcxam\LarsadminMedialibrary\Support\Metadata;

use HashContext;
use Maxcxam\LarsadminMedialibrary\Support\MetadataStripFailed;

/**
 * Bounds-checked random access to a file through a small read window: the file is never loaded whole. Every read
 * past the end throws (a truncated file is malformed), so parsers never see short data.
 *
 * @internal
 */
final class Source
{
    private const WINDOW = 65536;

    /** The largest single read a parser may ask for (segment / header data); payload is streamed, not read. */
    private const MAX_READ = 1048576;

    private string $window = '';

    private int $windowStart = 0;

    /** @param resource $handle */
    private function __construct(private $handle, private readonly int $size) {}

    public static function open(string $path): self
    {
        $handle = is_file($path) ? @fopen($path, 'rb') : false;
        $size = $handle === false ? false : @filesize($path);

        if ($handle === false || $size === false) {
            throw MetadataStripFailed::failed('the file cannot be read.');
        }

        return new self($handle, $size);
    }

    public function size(): int
    {
        return $this->size;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Exactly $length bytes at $offset, or a failure when the file is shorter. */
    public function bytes(int $offset, int $length): string
    {
        $this->assertRange($offset, $length);

        if ($length > self::MAX_READ) {
            throw MetadataStripFailed::failed('a header read exceeds the limit.');
        }

        if ($length === 0) {
            return '';
        }

        if ($offset < $this->windowStart || $offset + $length > $this->windowStart + strlen($this->window)) {
            $this->load($offset, max($length, self::WINDOW));
        }

        return substr($this->window, $offset - $this->windowStart, $length);
    }

    public function byte(int $offset): int
    {
        return ord($this->bytes($offset, 1));
    }

    public function u16be(int $offset): int
    {
        return unpack('n', $this->bytes($offset, 2))[1];
    }

    public function u32be(int $offset): int
    {
        return unpack('N', $this->bytes($offset, 4))[1];
    }

    public function u32le(int $offset): int
    {
        return unpack('V', $this->bytes($offset, 4))[1];
    }

    public function startsWith(int $offset, string $prefix): bool
    {
        return $offset + strlen($prefix) <= $this->size && $this->bytes($offset, strlen($prefix)) === $prefix;
    }

    /** The offset of the next $byte at or after $from; a failure when the file ends first. */
    public function find(string $byte, int $from): int
    {
        while ($from < $this->size) {
            if ($from < $this->windowStart || $from >= $this->windowStart + strlen($this->window)) {
                $this->load($from, self::WINDOW);
            }

            $found = strpos($this->window, $byte, $from - $this->windowStart);

            if ($found !== false) {
                return $this->windowStart + $found;
            }

            $from = $this->windowStart + strlen($this->window);
        }

        throw MetadataStripFailed::failed('unexpected end of file.');
    }

    public function hashInto(HashContext $context, int $offset, int $length): void
    {
        $this->assertRange($offset, $length);
        $this->seek($offset);

        while ($length > 0) {
            $chunk = $this->read(min($length, self::WINDOW));
            hash_update($context, $chunk);
            $length -= strlen($chunk);
        }
    }

    /** @param resource $out */
    public function copyTo($out, int $offset, int $length): void
    {
        $this->assertRange($offset, $length);
        $this->seek($offset);

        while ($length > 0) {
            $chunk = $this->read(min($length, self::WINDOW));

            if (@fwrite($out, $chunk) !== strlen($chunk)) {
                throw MetadataStripFailed::failed('the stripped file cannot be written.');
            }

            $length -= strlen($chunk);
        }
    }

    private function assertRange(int $offset, int $length): void
    {
        if ($offset < 0 || $length < 0 || $offset > $this->size || $length > $this->size - $offset) {
            throw MetadataStripFailed::failed('unexpected end of file.');
        }
    }

    private function load(int $offset, int $length): void
    {
        $this->seek($offset);
        $this->window = $this->read(min($length, $this->size - $offset));
        $this->windowStart = $offset;
    }

    private function seek(int $offset): void
    {
        if (fseek($this->handle, $offset) !== 0) {
            throw MetadataStripFailed::failed('the file cannot be read.');
        }
    }

    private function read(int $length): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $chunk = fread($this->handle, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                throw MetadataStripFailed::failed('the file cannot be read.');
            }

            $data .= $chunk;
        }

        return $data;
    }
}

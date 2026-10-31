<?php

namespace Maxcxam\LarsadminMedialibrary\Support;

use FilesystemIterator;
use Throwable;

/**
 * Private working directories for stripped copies (security review SECM-04). Each call gets its own directory with
 * an unpredictable name, mode 0700, under a root that is also 0700: `storage/app/larsadmin-medialibrary-strip` in a
 * Laravel application, else a per-user directory in the system temp directory. Directories left behind by a fatal
 * error are swept when the next one is created.
 *
 * @internal
 */
class StripWorkspace
{
    /** Seconds after which a leftover directory is considered stale and removed. */
    public const STALE_AFTER = 3600;

    private const PREFIX = 'strip-';

    private readonly string $root;

    public function __construct(?string $root = null, private readonly int $staleAfter = self::STALE_AFTER)
    {
        $this->root = rtrim($root ?? self::defaultRoot(), '/\\');
    }

    public static function defaultRoot(): string
    {
        try {
            if (function_exists('app') && app()->bound('path.storage')) {
                return storage_path('app'.DIRECTORY_SEPARATOR.'larsadmin-medialibrary-strip');
            }
        } catch (Throwable) {
            // no application: fall back to the system temp directory
        }

        $user = function_exists('posix_geteuid') ? (string) posix_geteuid() : (getenv('USERNAME') ?: get_current_user());

        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-medialibrary-strip-'.substr(hash('sha256', $user), 0, 12);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Creates a new private directory (0700, unpredictable name) after sweeping stale ones, and returns its path.
     *
     * @throws MetadataStripFailed
     */
    public function create(): string
    {
        $this->ensureRoot();
        $this->sweep();

        $directory = $this->root.DIRECTORY_SEPARATOR.self::PREFIX.bin2hex(random_bytes(16));

        // mkdir() fails when the path exists, so the directory is created exclusively
        if (! @mkdir($directory, 0700)) {
            throw MetadataStripFailed::failed('the working directory cannot be created.');
        }

        @chmod($directory, 0700);

        return $directory;
    }

    /** Deletes a directory created by {@see create()} with its files; anything outside the root is ignored. */
    public function delete(string $directory): void
    {
        $directory = rtrim($directory, '/\\');

        if (dirname($directory) !== $this->root
            || ! str_starts_with(basename($directory), self::PREFIX)
            || is_link($directory)
            || ! is_dir($directory)) {
            return;
        }

        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isLink() || $entry->isFile()) {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($directory);
    }

    /** Removes working directories older than the stale period (left behind by a fatal error or a killed worker). */
    public function sweep(): void
    {
        if (! is_dir($this->root)) {
            return;
        }

        $limit = time() - $this->staleAfter;

        foreach (new FilesystemIterator($this->root, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (str_starts_with($entry->getFilename(), self::PREFIX) && ! $entry->isLink() && $entry->isDir() && $entry->getMTime() < $limit) {
                $this->delete($entry->getPathname());
            }
        }
    }

    private function ensureRoot(): void
    {
        if (is_link($this->root)) {
            throw MetadataStripFailed::failed('the working directory root is a symbolic link.');
        }

        if (! is_dir($this->root) && ! @mkdir($this->root, 0700, true) && ! is_dir($this->root)) {
            throw MetadataStripFailed::failed('the working directory root cannot be created.');
        }

        if (function_exists('posix_geteuid') && @fileowner($this->root) !== posix_geteuid()) {
            throw MetadataStripFailed::failed('the working directory root belongs to another user.');
        }

        @chmod($this->root, 0700);

        if (! is_writable($this->root)) {
            throw MetadataStripFailed::failed('the working directory root is not writable.');
        }
    }
}

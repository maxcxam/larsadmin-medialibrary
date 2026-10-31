<?php

namespace Maxcxam\LarsadminMedialibrary\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Upload and serving safety (mirrors larsadmin's 1.1 file rules, ADR-018).
 *
 * SPIKE GAP (A6a, deferred to 1.6): larsadmin's Files\FileTypes and Files\FileResponse are @internal, so this is a copy of
 * security-critical code (blocked extensions incl. multi-extension names, name sanitizing, safe headers).
 * Only the config key larsadmin.files.blocked_extensions is shared. tests/Feature/FileSafetyParityTest.php runs a
 * shared corpus through this class and the core one, so any drift fails the suite (SECX-03).
 */
final class FileSafety
{
    public const array RASTER = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    ];

    public const array PUBLIC_BLOCKED = [
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'xht', 'xhtm', 'shtml', 'mht', 'mhtml',
        'xml', 'xsl', 'xslt', 'js', 'mjs', 'php', 'phtml', 'phar', 'htaccess',
    ];

    public const string CONTENT_SECURITY_POLICY = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox";

    /**
     * @return list<string>
     */
    public static function blocked(bool $public): array
    {
        $blocked = array_map(
            fn ($extension) => strtolower(ltrim((string) $extension, '.')),
            (array) config('larsadmin.files.blocked_extensions', []),
        );

        return array_values(array_unique($public ? [...$blocked, ...self::PUBLIC_BLOCKED] : $blocked));
    }

    /**
     * Detected types the mime map has no extension for, but which must still be caught.
     */
    public const array SCRIPT_TYPES = [
        'text/x-php' => 'php',
        'application/x-php' => 'php',
        'application/x-httpd-php' => 'php',
        'text/x-shellscript' => 'sh',
        'application/x-shellscript' => 'sh',
    ];

    public static function detectedExtension(UploadedFile $file): string
    {
        return strtolower((string) ($file->guessExtension() ?? self::SCRIPT_TYPES[$file->getMimeType()] ?? ''));
    }

    /**
     * The first blocked extension of the upload (every dot segment of the raw and sanitized client name, then the
     * content-guessed extension), or null.
     */
    public static function blockedExtension(UploadedFile $file, bool $public): ?string
    {
        $blocked = self::blocked($public);
        $raw = $file->getClientOriginalName();
        $segments = [];

        foreach (array_unique([mb_strtolower($raw), mb_strtolower(self::sanitizeName($raw))]) as $name) {
            array_push($segments, ...array_slice(explode('.', $name), 1));
        }

        foreach ([...$segments, self::detectedExtension($file)] as $extension) {
            $extension = rtrim(trim($extension), '. ');

            if ($extension !== '' && in_array($extension, $blocked, true)) {
                return $extension;
            }
        }

        return null;
    }

    /**
     * The extension of the stored file name: content-guessed, "bin" when unknown or blocked.
     */
    public static function storedExtension(UploadedFile $file, bool $public): string
    {
        $extension = self::detectedExtension($file);

        return preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 && ! in_array($extension, self::blocked($public), true)
            ? $extension
            : 'bin';
    }

    public static function sanitizeName(string $name): string
    {
        $name = Str::afterLast(str_replace('\\', '/', $name), '/');

        if (! mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }

        $name = preg_replace('/[\p{Cc}\p{Cf}"<>:*?|]+/u', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if (mb_strlen($name) > 200) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $extension = $extension !== '' && mb_strlen($extension) <= 10 ? '.'.$extension : '';
            $name = rtrim(mb_substr($name, 0, 200 - mb_strlen($extension))).$extension;
        }

        return $name;
    }

    public static function asciiName(string $name): string
    {
        $fallback = trim(preg_replace('/[^\x20-\x7E]|[%\/\\\\"]/', '', Str::ascii($name)) ?? '');

        if ($fallback === '' || $fallback[0] === '.') {
            $extension = pathinfo($fallback, PATHINFO_EXTENSION);

            return 'download'.($extension !== '' ? '.'.$extension : '');
        }

        return $fallback;
    }

    public static function isRasterMime(?string $mime): bool
    {
        return in_array($mime, self::RASTER, true);
    }

    public static function isRasterFile(string $fileName): bool
    {
        return isset(self::RASTER[strtolower(pathinfo($fileName, PATHINFO_EXTENSION))]);
    }
}

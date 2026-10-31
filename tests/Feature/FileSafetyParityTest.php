<?php

use Illuminate\Http\UploadedFile;
use Maxcxam\Larsadmin\Files\FileResponse as CoreFileResponse;
use Maxcxam\Larsadmin\Files\FileTypes as CoreFileTypes;
use Maxcxam\LarsadminMedialibrary\Support\FileSafety;

/*
 * SECX-03: Support\FileSafety is a copy of larsadmin's @internal Files\FileTypes / FileResponse (A6a, deferred to
 * 1.6). This test reads the internal classes on purpose (the public API check exempts an extension's tests/**):
 * the same corpus must give the same answers, so a change in either copy fails CI. Remove this test (and the copy)
 * when 1.6 makes a safe file API public.
 */

/** A real upload (fake files report the MIME type of their name, not of their content). */
function upload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'lmlp');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

function png(): string
{
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

dataset('uploads', fn () => [
    'php content as jpg' => ['shell.jpg', '<?php system($_GET["c"]); ?>'],
    'php short tag as txt' => ['note.txt', "<?php\necho 1;"],
    'shell script as png' => ['run.png', "#!/bin/sh\nrm -rf /\n"],
    'bash script, no extension' => ['run', "#!/bin/bash\necho hi\n"],
    'real png' => ['photo.png', png()],
    'png named exe' => ['photo.exe', png()],
    'multi extension' => ['x.php.jpg', png()],
    'trailing dots and spaces' => ['evil.php. .', 'hello'],
    'html' => ['page.html', '<!doctype html><html><body><script>alert(1)</script></body></html>'],
    'svg' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'htaccess' => ['.htaccess', 'AddType application/x-httpd-php .jpg'],
    'quoted bat' => ['run.bat"', '@echo off'],
    'upper case' => ['SHELL.PHTML', 'x'],
    'pdf' => ['doc.pdf', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"],
    'plain text' => ['readme.txt', 'just text'],
    'unicode' => ['Звіт за жовтень.txt', 'текст'],
]);

it('detects, blocks and names uploads exactly like the core', function (string $name, string $content) {
    $file = upload($name, $content);

    expect(FileSafety::detectedExtension($file))->toBe(CoreFileTypes::detectedExtension($file));

    foreach ([true, false] as $public) {
        expect(FileSafety::blockedExtension($file, $public))->toBe(CoreFileTypes::blockedExtension($file, $public))
            ->and(FileSafety::storedExtension($file, $public))->toBe(CoreFileTypes::storedExtension($file, $public));
    }
})->with('uploads');

it('blocks script content that only the mime sniff reveals', function () {
    expect(FileSafety::blockedExtension(upload('shell.jpg', '<?php system($_GET["c"]); ?>'), false))->toBe('php')
        ->and(FileSafety::storedExtension(upload('a.txt', "#!/bin/sh\nid\n"), false))->toBe('bin');
});

it('sanitizes and ascii-folds names exactly like the core', function (string $name) {
    expect(FileSafety::sanitizeName($name))->toBe(CoreFileTypes::sanitizeName($name))
        ->and(FileSafety::asciiName($name))->toBe(CoreFileTypes::asciiName($name));
})->with([
    'plain' => 'photo.jpg',
    'path' => '..\\..\\windows/system32/evil.exe',
    'quotes and newline' => "a\"b\nc.pdf",
    'bidi override' => "invoice\u{202E}gpj.exe",
    'zero width' => "na\u{200B}me.txt",
    'reserved chars' => 'a<b>c:d*e?f|g.txt',
    'whitespace' => "  lots   of\tspace .txt ",
    'long with extension' => str_repeat('я', 250).'.jpeg',
    'long ascii' => str_repeat('a', 300).'.pdf',
    'long, long extension' => str_repeat('b', 300).'.'.str_repeat('x', 12),
    'dotfile' => '.env',
    'only unicode' => 'Звіт.pdf',
    'invalid utf8' => "bad\xC3\x28.txt",
    'empty' => '',
]);

it('keeps the extension when truncating a long name', function () {
    $name = FileSafety::sanitizeName(str_repeat('a', 300).'.pdf');

    expect(mb_strlen($name))->toBe(200)->and($name)->toEndWith('.pdf');
});

it('shares the block lists, raster map and serving policy with the core', function () {
    config(['larsadmin.files.blocked_extensions' => ['php', '.EXE', 'bat']]);

    expect(FileSafety::RASTER)->toBe(CoreFileTypes::RASTER)
        ->and(FileSafety::PUBLIC_BLOCKED)->toBe(CoreFileTypes::PUBLIC_BLOCKED)
        ->and(FileSafety::SCRIPT_TYPES)->toBe(CoreFileTypes::SCRIPT_TYPES)
        ->and(FileSafety::blocked(true))->toBe(CoreFileTypes::blocked(true))
        ->and(FileSafety::blocked(false))->toBe(CoreFileTypes::blocked(false))
        ->and(FileSafety::CONTENT_SECURITY_POLICY)->toBe(CoreFileResponse::CONTENT_SECURITY_POLICY);

    foreach (array_keys(CoreFileTypes::RASTER) as $extension) {
        expect(FileSafety::RASTER[$extension])->toBe(CoreFileTypes::rasterType($extension));
    }
});

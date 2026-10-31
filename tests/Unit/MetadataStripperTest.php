<?php

use Maxcxam\LarsadminMedialibrary\Support\Metadata\ImageParser;
use Maxcxam\LarsadminMedialibrary\Support\Metadata\Orientation;
use Maxcxam\LarsadminMedialibrary\Support\Metadata\Plan;
use Maxcxam\LarsadminMedialibrary\Support\Metadata\Source;
use Maxcxam\LarsadminMedialibrary\Support\MetadataStripFailed;
use Maxcxam\LarsadminMedialibrary\Support\MetadataStripper;
use Maxcxam\LarsadminMedialibrary\Support\StripWorkspace;
use Maxcxam\LarsadminMedialibrary\Tests\Support\ImageFactory as F;

/*
 * ADR-047 / design 1.0 §3.1: lossless metadata stripping by container rewriting, fail closed.
 */

function msDir(): string
{
    static $dir = null;

    if ($dir === null) {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-strip-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($dir);
        register_shutdown_function(fn () => @rmdir($dir));
    }

    return $dir;
}

/** @return list<string> */
function msFiles(): array
{
    return glob(msDir().DIRECTORY_SEPARATOR.'*') ?: [];
}

function msInput(string $bytes): string
{
    $path = msDir().DIRECTORY_SEPARATOR.'input-'.bin2hex(random_bytes(6));
    file_put_contents($path, $bytes);

    return $path;
}

/** Strips $bytes and returns the stripped bytes. */
function msStrip(string $bytes, string $mime, ?MetadataStripper $stripper = null): string
{
    $output = ($stripper ?? new MetadataStripper)->strip(msInput($bytes), $mime, msDir());

    return file_get_contents($output);
}

/** Asserts stripping fails with $reason and leaves no output file behind. */
function msFails(string $bytes, string $mime, string $reason = MetadataStripFailed::FAILED, ?MetadataStripper $stripper = null): void
{
    $input = msInput($bytes);

    try {
        ($stripper ?? new MetadataStripper)->strip($input, $mime, msDir());
        test()->fail('Stripping was expected to fail.');
    } catch (MetadataStripFailed $e) {
        expect($e->reason)->toBe($reason)
            ->and(msFiles())->toBe([$input]);
    }

    unlink($input);
}

/** getimagesize() width, height and type. @return list<int> */
function msSize(string $bytes): array
{
    $size = getimagesizefromstring($bytes);

    return [$size[0], $size[1], $size[2]];
}

/** The parts every clean output satisfies: no injected metadata, same image, idempotent. */
function msAssertClean(string $source, string $output, string $mime, bool $decodable = true): void
{
    expect($output)->not->toContain(F::SECRET)
        ->and($output)->not->toContain('Jane Doe')
        ->and(msSize($output))->toBe(msSize($source))
        ->and(msStrip($output, $mime))->toBe($output);

    if ($decodable) {
        expect(F::pixels($output))->toBe(F::pixels($source));
    }
}

/** The EXIF sections and IFD0 tags PHP reads from a JPEG. @return array<string, mixed> */
function msExif(string $jpeg): array
{
    return @exif_read_data(msInput($jpeg), 'ANY_TAG', true) ?: [];
}

/** The offset of the first image descriptor of a GIF. */
function msGifImage(string $gif): int
{
    $position = 13 + ((ord($gif[10]) & 0x80) === 0 ? 0 : 3 * (2 << (ord($gif[10]) & 7)));

    while ($gif[$position] === "\x21") {
        $position += 2;

        while (($size = ord($gif[$position])) !== 0) {
            $position += 1 + $size;
        }

        $position++;
    }

    return $position;
}

afterEach(function () {
    foreach (msFiles() as $file) {
        @unlink($file);
    }
});

describe('scope', function () {
    it('applies to every image type and supports JPEG, PNG, WebP and GIF', function () {
        expect(MetadataStripper::applies('image/heic'))->toBeTrue()
            ->and(MetadataStripper::applies('application/pdf'))->toBeFalse()
            ->and(MetadataStripper::supports('image/jpeg'))->toBeTrue()
            ->and(MetadataStripper::supports('IMAGE/PNG; charset=binary'))->toBeTrue()
            ->and(MetadataStripper::supports('image/webp'))->toBeTrue()
            ->and(MetadataStripper::supports('image/gif'))->toBeTrue()
            ->and(MetadataStripper::supports('image/avif'))->toBeFalse();
    });

    it('rejects image types it cannot guarantee clean', function (string $mime, string $bytes) {
        msFails($bytes, $mime, MetadataStripFailed::UNSUPPORTED);
    })->with([
        'avif' => fn () => ['image/avif', F::avif()],
        'heic' => ['image/heic', "\0\0\0\x18ftypheic"],
        'heif' => ['image/heif', "\0\0\0\x18ftypmif1"],
        'tiff' => ['image/tiff', F::tiff([0x0100 => [3, 1]])],
        'bmp' => ['image/bmp', 'BM'.str_repeat("\0", 60)],
        'svg' => ['image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><metadata>'.F::SECRET.'</metadata></svg>'],
        'ico' => ['image/vnd.microsoft.icon', "\0\0\1\0"],
    ]);

    it('leaves non-image files untouched and returns their path', function (string $mime) {
        $input = msInput('%PDF-1.7 '.F::SECRET);

        expect((new MetadataStripper)->strip($input, $mime, msDir()))->toBe($input)
            ->and(file_get_contents($input))->toBe('%PDF-1.7 '.F::SECRET)
            ->and(msFiles())->toBe([$input]);
    })->with(['application/pdf', 'text/plain', 'application/octet-stream']);

    it('fails when the content does not match the sniffed type', function () {
        msFails(F::png(), 'image/jpeg');
        msFails(F::jpeg(), 'image/png');
        msFails(F::gif(), 'image/webp');
        msFails(F::webp(), 'image/gif');
    });

    it('fails on a missing file, an empty file, a size over the limit and an unusable directory', function () {
        $stripper = new MetadataStripper;

        expect(fn () => $stripper->strip(msDir().'/missing.jpg', 'image/jpeg', msDir()))->toThrow(MetadataStripFailed::class);
        msFails('', 'image/jpeg');
        msFails(F::jpeg(), 'image/jpeg', stripper: new MetadataStripper(maxBytes: 100));
        expect(fn () => $stripper->strip(msInput(F::jpeg()), 'image/jpeg', msDir().'/nope'))->toThrow(MetadataStripFailed::class);
    });

    it('writes into a new private workspace directory by default and never modifies the source', function () {
        $bytes = F::withJpeg(F::jpeg(), [F::exifSegment(F::cameraExif())]);
        $input = msInput($bytes);
        $output = (new MetadataStripper)->strip($input, 'image/jpeg');
        $workspace = new StripWorkspace;

        expect(dirname($output, 2))->toBe($workspace->root())
            ->and($workspace->root())->toBe(storage_path('app'.DIRECTORY_SEPARATOR.'larsadmin-medialibrary-strip'))
            ->and(basename(dirname($output)))->toMatch('/\Astrip-[0-9a-f]{32}\z/')
            ->and(basename($output))->toEndWith('.jpg')
            ->and(file_get_contents($input))->toBe($bytes)
            ->and(file_get_contents($output))->not->toContain(F::SECRET);

        $workspace->delete(dirname($output));
        expect(file_exists(dirname($output)))->toBeFalse();
    });

    it('removes the workspace it created when stripping fails', function () {
        $workspace = new StripWorkspace;
        $before = is_dir($workspace->root()) ? count(glob($workspace->root().'/strip-*')) : 0;

        // a parser that always finds something to drop, so the self-check fails after the file is written
        $stripper = new class extends MetadataStripper
        {
            protected function parser(string $format): ImageParser
            {
                return new class implements ImageParser
                {
                    public function parse(Source $source): Plan
                    {
                        $plan = new Plan($source);
                        $plan->keep(0, $source->size());
                        $plan->drop();

                        return $plan;
                    }
                };
            }
        };

        expect(fn () => $stripper->strip(msInput(F::jpeg()), 'image/jpeg'))->toThrow(MetadataStripFailed::class)
            ->and(is_dir($workspace->root()) ? count(glob($workspace->root().'/strip-*')) : 0)->toBe($before);
    });
});

describe('workspace (SECM-04)', function () {
    beforeEach(function () {
        $this->root = msDir().DIRECTORY_SEPARATOR.'ws-'.bin2hex(random_bytes(4));
        $this->workspace = new StripWorkspace($this->root, staleAfter: 60);
    });

    afterEach(function () {
        foreach (glob($this->root.'/*') ?: [] as $directory) {
            $this->workspace->delete($directory);
        }

        @rmdir($this->root);
    });

    it('creates a distinct, unpredictable directory per call under a private root', function () {
        $first = $this->workspace->create();
        $second = $this->workspace->create();

        expect($first)->not->toBe($second)
            ->and(dirname($first))->toBe($this->root)
            ->and(basename($first))->toMatch('/\Astrip-[0-9a-f]{32}\z/')
            ->and(is_dir($first) && is_dir($second))->toBeTrue();

        if (DIRECTORY_SEPARATOR === '/') {
            expect(fileperms($this->root) & 0777)->toBe(0700)
                ->and(fileperms($first) & 0777)->toBe(0700);
        }
    });

    it('creates the stripped file exclusively and privately', function () {
        $directory = $this->workspace->create();
        $output = (new MetadataStripper)->strip(msInput(F::jpeg()), 'image/jpeg', $directory);

        expect(dirname($output))->toBe($directory);

        if (DIRECTORY_SEPARATOR === '/') {
            expect(fileperms($output) & 0777)->toBe(0600);
        }
    });

    it('sweeps stale directories when the next one is created and keeps fresh ones', function () {
        $stale = $this->workspace->create();
        file_put_contents($stale.'/stripped-old.jpg', 'x');
        touch($stale, time() - 120);
        $fresh = $this->workspace->create();
        $next = $this->workspace->create();

        expect(file_exists($stale))->toBeFalse()
            ->and(is_dir($fresh) && is_dir($next))->toBeTrue();
    });

    it('deletes only its own directories', function () {
        $foreign = msDir().DIRECTORY_SEPARATOR.'strip-foreign';
        mkdir($foreign);
        file_put_contents($foreign.'/keep.txt', 'x');
        $outsidePrefix = $this->root.DIRECTORY_SEPARATOR.'other';
        $this->workspace->create();
        mkdir($outsidePrefix);

        $this->workspace->delete($foreign);
        $this->workspace->delete($outsidePrefix);

        expect(is_file($foreign.'/keep.txt'))->toBeTrue()
            ->and(is_dir($outsidePrefix))->toBeTrue();

        unlink($foreign.'/keep.txt');
        rmdir($foreign);
        rmdir($outsidePrefix);
    });

    it('refuses a symbolic link as its root', function () {
        $target = msDir().DIRECTORY_SEPARATOR.'link-target';
        mkdir($target);
        $link = msDir().DIRECTORY_SEPARATOR.'link-root';

        if (! @symlink($target, $link)) {
            rmdir($target);
            $this->markTestSkipped('symbolic links are not available');
        }

        try {
            expect(fn () => (new StripWorkspace($link))->create())->toThrow(MetadataStripFailed::class);
        } finally {
            DIRECTORY_SEPARATOR === '/' ? @unlink($link) : @rmdir($link);
            @rmdir($target);
        }
    });

    it('uses storage/app of the application as the default root', function () {
        expect(StripWorkspace::defaultRoot())->toBe(storage_path('app'.DIRECTORY_SEPARATOR.'larsadmin-medialibrary-strip'));
    });
});

describe('fixtures', function () {
    it('strips every synthetic camera fixture', function (string $file, string $mime) {
        $path = dirname(__DIR__).'/Fixtures/images/'.$file;
        $before = hash_file('sha256', $path);
        $source = file_get_contents($path);
        $output = file_get_contents((new MetadataStripper)->strip($path, $mime, msDir()));

        expect($source)->toContain(F::SECRET)
            ->and(strlen($source))->toBeLessThan(20480)
            ->and(hash_file('sha256', $path))->toBe($before);
        msAssertClean($source, $output, $mime);
    })->with([
        ['camera-gps-orientation6.jpg', 'image/jpeg'],
        ['camera-gps.png', 'image/png'],
        ['camera-gps-orientation6.webp', 'image/webp'],
        ['comment-xmp.gif', 'image/gif'],
    ]);

    it('keeps only the Orientation of the camera JPEG', function () {
        $source = file_get_contents(dirname(__DIR__).'/Fixtures/images/camera-gps-orientation6.jpg');
        $output = msStrip($source, 'image/jpeg');

        expect(msExif($source))->toHaveKey('GPS')
            ->and(msExif($output))->not->toHaveKeys(['GPS', 'EXIF', 'THUMBNAIL'])
            ->and(msExif($output)['IFD0'])->toBe(['Orientation' => 6]);
    });
});

describe('JPEG', function () {
    beforeEach(function () {
        $this->base = F::jpeg();
    });

    it('removes Exif with GPS, XMP, IPTC, MPF, comments and data after EOI and keeps JFIF, ICC and Adobe', function () {
        $source = F::withJpeg($this->base, [
            F::exifSegment(F::cameraExif()), F::xmpSegment(), F::mpfSegment(), F::iccSegment(), F::iptcSegment(),
            F::segment(0xE3, F::SECRET), F::segment(0xEF, F::SECRET), F::segment(0xE0, 'AVI1'.F::SECRET), F::adobeSegment(),
        ], [F::commentSegment()], trailer: F::jpeg(8, 8).F::SECRET);
        $output = msStrip($source, 'image/jpeg');

        expect(array_values(array_intersect(F::jpegHeaderCodes($output), [0xE1, 0xE3, 0xED, 0xEF, 0xFE])))->toBe([])
            ->and(F::jpegSegments($output, 0xE0))->toBe(F::jpegSegments($this->base, 0xE0))
            ->and(F::jpegSegments($output, 0xE2))->toBe(["ICC_PROFILE\0\x01\x01".F::iccProfile()])
            ->and(F::jpegSegments($output, 0xEE))->toHaveCount(1)
            ->and(F::jpegScans($output))->toBe(F::jpegScans($this->base))
            ->and($output)->toEndWith("\xFF\xD9");
        msAssertClean($source, $output, 'image/jpeg');
    });

    it('removes the comment GD writes, so a plain GD JPEG changes only by that', function () {
        $output = msStrip($this->base, 'image/jpeg');

        expect(F::jpegSegments($this->base, 0xFE))->toHaveCount(1)
            ->and(F::jpegSegments($output, 0xFE))->toBe([])
            ->and(strlen($output))->toBe(strlen($this->base) - 4 - strlen(F::jpegSegments($this->base, 0xFE)[0]));
    });

    it('carries Orientation 2–8 over as a minimal EXIF right after APP0', function (int $orientation, bool $littleEndian) {
        $source = F::withJpeg($this->base, [F::exifSegment(F::cameraExif($orientation, $littleEndian))]);
        $output = msStrip($source, 'image/jpeg');

        expect(array_slice(F::jpegHeaderCodes($output), 0, 3))->toBe([0xD8, 0xE0, 0xE1])
            ->and(F::jpegSegments($output, 0xE1))->toBe(["Exif\0\0".Orientation::tiff($orientation)])
            ->and(strlen(Orientation::tiff($orientation)))->toBe(26)
            ->and(msExif($output)['IFD0'])->toBe(['Orientation' => $orientation])
            ->and(msExif($output))->not->toHaveKeys(['GPS', 'EXIF', 'THUMBNAIL']);
        msAssertClean($source, $output, 'image/jpeg');
    })->with([2, 3, 6, 8])->with(['little endian' => true, 'big endian' => false]);

    it('writes no EXIF for Orientation 1, an invalid value or no Orientation', function (?int $orientation) {
        $output = msStrip(F::withJpeg($this->base, [F::exifSegment(F::cameraExif($orientation))]), 'image/jpeg');

        expect(F::jpegHeaderCodes($output))->not->toContain(0xE1);
    })->with([1, 0, 9, null]);

    it('places the minimal EXIF right after SOI without APP0 and takes the first Exif only', function () {
        $app0 = F::jpegMarkers($this->base)[1];
        $bare = "\xFF\xD8".substr($this->base, $app0[1] + 2 + unpack('n', $this->base, $app0[1] + 2)[1]);
        $source = substr($bare, 0, 2).F::exifSegment(F::cameraExif(5)).F::exifSegment(F::cameraExif(7)).substr($bare, 2);
        $output = msStrip($source, 'image/jpeg');

        expect(array_slice(F::jpegHeaderCodes($output), 0, 2))->toBe([0xD8, 0xE1])
            ->and(F::jpegSegments($output, 0xE1))->toBe(["Exif\0\0".Orientation::tiff(5)]);
    });

    it('strips a progressive JPEG with metadata between scans and keeps every scan', function () {
        $base = F::jpeg(64, 48, progressive: true);
        $source = F::withJpeg($base, [F::exifSegment(F::cameraExif(6))], betweenScans: [F::commentSegment(), F::xmpSegment()]);
        $output = msStrip($source, 'image/jpeg');

        expect(count(array_filter(F::jpegMarkers($output), fn ($m) => $m[0] === 0xDA)))->toBeGreaterThan(1)
            ->and(F::jpegScans($output))->toBe(F::jpegScans($base));
        msAssertClean($source, $output, 'image/jpeg');
    });

    it('drops fill bytes before markers', function () {
        $source = F::withJpeg($this->base, [F::exifSegment(F::cameraExif())]);
        $sof = array_values(array_filter(F::jpegMarkers($source), fn ($m) => $m[0] === 0xC0))[0][1];
        $filled = substr($source, 0, $sof)."\xFF\xFF\xFF".substr($source, $sof);

        expect(msStrip($filled, 'image/jpeg'))->toBe(msStrip($source, 'image/jpeg'));
    });

    it('ignores a self-referencing or oversized IFD0 without looping', function () {
        $tiff = 'II'.pack('v', 42).pack('V', 8).pack('v', 0xFFFF).str_repeat("\0", 12);
        $output = msStrip(F::withJpeg($this->base, [F::exifSegment($tiff)]), 'image/jpeg');

        expect(F::jpegHeaderCodes($output))->not->toContain(0xE1);
    });

    it('fails closed on malformed JPEG structure', function (Closure $mutate) {
        msFails($mutate($this->base), 'image/jpeg');
    })->with([
        'no SOI' => fn (string $j) => substr($j, 2),
        'garbage after SOI' => fn (string $j) => "\xFF\xD8garbage".substr($j, 2),
        'segment length past the end' => fn (string $j) => "\xFF\xD8\xFF\xE1\xFF\xFFExif\0\0",
        'segment length below 2' => fn (string $j) => "\xFF\xD8\xFF\xE1\x00\x01".substr($j, 2),
        'unknown marker before SOS' => fn (string $j) => "\xFF\xD8".F::segment(0xF0, 'x').substr($j, 2),
        'reserved marker' => fn (string $j) => "\xFF\xD8".F::segment(0x02, 'x').substr($j, 2),
        'second SOI' => fn (string $j) => "\xFF\xD8\xFF\xD8".substr($j, 2),
        'EOI before SOS' => fn (string $j) => "\xFF\xD8\xFF\xD9",
        'RST outside a scan' => fn (string $j) => "\xFF\xD8\xFF\xD0".substr($j, 2),
        'SOS before SOF' => fn (string $j) => "\xFF\xD8".F::segment(0xDA, "\x01\x01\x00\x00\x3F\x00").substr($j, 2),
        'second frame header' => fn (string $j) => str_replace("\xFF\xDA", F::segment(0xC0, str_repeat("\x01", 9))."\xFF\xDA", $j),
        'no EOI' => fn (string $j) => substr($j, 0, -2),
        'DQT with a smuggled tail' => fn (string $j) => "\xFF\xD8".F::segment(0xDB, "\x00".str_repeat("\x01", 64).F::SECRET).substr($j, 2),
        'DHT with a smuggled tail' => fn (string $j) => "\xFF\xD8".F::segment(0xC4, "\x00\x01".str_repeat("\0", 15)."\x00".F::SECRET).substr($j, 2),
        'JFIF with a smuggled tail' => fn (string $j) => "\xFF\xD8".F::segment(0xE0, "JFIF\0\x01\x01\0\0\1\0\1\0\0".F::SECRET).substr($j, 2),
        'Adobe with a smuggled tail' => fn (string $j) => "\xFF\xD8".F::segment(0xEE, 'Adobe'.str_repeat("\0", 7).F::SECRET).substr($j, 2),
        'endless fill bytes' => fn (string $j) => "\xFF\xD8".str_repeat("\xFF", 70000)."\xE0".substr($j, 4),
    ]);
});

describe('PNG', function () {
    beforeEach(function () {
        $this->base = F::png();
    });

    it('keeps critical and colour chunks and drops text, eXIf, tIME, unknown chunks and data after IEND', function () {
        $iccp = F::chunk('iCCP', "ICC\0\0".gzcompress(F::iccProfile()));
        $kept = [F::chunk('cHRM', str_repeat("\0\0\1\0", 8)), F::chunk('gAMA', pack('N', 45455)), $iccp, F::chunk('sBIT', "\x08\x08\x08"), F::chunk('bKGD', "\0\1\0\1\0\1")];
        $source = F::withPng(
            $this->base,
            $kept,
            [F::chunk('eXIf', F::cameraExif()), F::chunk('tEXt', 'Author'."\0".F::SECRET), F::chunk('zTXt', "Comment\0\0".gzcompress(F::SECRET)), F::chunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0".F::xmp()), F::chunk('prVt', F::SECRET)],
            [F::chunk('tIME', "\x07\xEA\x0A\x1F\x0C\x22\x38"), F::chunk('vpAg', F::SECRET)],
            F::chunk('tEXt', 'After'."\0".F::SECRET),
        );
        $output = msStrip($source, 'image/png');

        // GD itself writes IHDR, pHYs, IDAT…, IEND
        expect(array_column(F::pngChunks($output), 0))->toBe(['IHDR', 'cHRM', 'gAMA', 'iCCP', 'sBIT', 'bKGD', ...array_slice(array_column(F::pngChunks($this->base), 0), 1)])
            ->and(array_column(F::pngChunks($this->base), 0))->toContain('pHYs')
            ->and(F::pngData($output))->toBe(F::pngData($this->base))
            ->and($output)->toContain($iccp);
        msAssertClean($source, $output, 'image/png');
    });

    it('returns an already clean PNG byte for byte', function () {
        expect(msStrip($this->base, 'image/png'))->toBe($this->base);
    });

    it('carries the Orientation over as a minimal eXIf before the first IDAT', function () {
        $source = F::withPng($this->base, afterData: [F::chunk('eXIf', F::cameraExif(8, false))]);
        $output = msStrip($source, 'image/png');
        $chunks = F::pngChunks($output);
        $idat = array_search('IDAT', array_column($chunks, 0), true);

        expect($chunks[$idat - 1])->toBe(['eXIf', Orientation::tiff(8)])
            ->and(array_count_values(array_column($chunks, 0))['eXIf'])->toBe(1)
            ->and($output)->toContain(F::chunk('eXIf', Orientation::tiff(8)));
        msAssertClean($source, $output, 'image/png');
    });

    it('keeps APNG animation chunks', function () {
        $source = F::apng($this->base);
        $output = msStrip(F::withPng($source, afterData: [F::chunk('tEXt', "a\0".F::SECRET)]), 'image/png');

        expect($output)->toBe($source)
            ->and(array_column(F::pngChunks($output), 0))->toContain('acTL', 'fcTL', 'fdAT');
    });

    it('fails closed on malformed PNG structure', function (Closure $mutate) {
        msFails($mutate($this->base), 'image/png');
    })->with([
        'bad CRC' => fn (string $p) => substr_replace($p, chr(ord($p[40]) ^ 0xFF), 40, 1),
        'no IEND' => fn (string $p) => substr($p, 0, -12),
        'truncated chunk' => fn (string $p) => substr($p, 0, -20),
        'huge chunk length' => fn (string $p) => F::withPng($p, [pack('N', 0x7FFFFFF0).'tEXt'.F::SECRET]),
        'length over 2^31' => fn (string $p) => F::withPng($p, [pack('N', 0xFFFFFFFF).'tEXt']),
        'IHDR not first' => fn (string $p) => substr($p, 0, 8).F::chunk('gAMA', pack('N', 1)).substr($p, 8),
        'no IDAT' => fn (string $p) => substr($p, 0, 33).F::chunk('IEND', ''),
        'unknown critical chunk' => fn (string $p) => F::withPng($p, [F::chunk('ABCD', 'x')]),
        'invalid chunk type' => fn (string $p) => F::withPng($p, [F::chunk("t\0Xt", 'x')]),
        'gAMA with a smuggled tail' => fn (string $p) => F::withPng($p, [F::chunk('gAMA', pack('N', 45455).F::SECRET)]),
        'not a PNG' => fn (string $p) => 'x'.substr($p, 1),
    ]);
});

describe('WebP', function () {
    it('drops EXIF and XMP, clears their VP8X flags, keeps ICCP and fixes the RIFF size', function (bool $lossless) {
        $simple = F::webp(lossless: $lossless);
        $iccp = F::webpChunk('ICCP', F::iccProfile());
        $source = F::webpExtended($simple, 48, 32, [$iccp], [F::webpChunk('EXIF', F::cameraExif()), F::webpChunk('XMP ', F::xmp()), F::webpChunk('ZZZZ', F::SECRET)]);
        $output = msStrip($source, 'image/webp');
        $chunks = F::webpChunks($output);

        expect(array_column($chunks, 0))->toBe(['VP8X', 'ICCP', F::webpChunks($simple)[0][0]])
            ->and(ord($chunks[0][1][0]))->toBe(0x20)
            ->and(unpack('V', $output, 4)[1])->toBe(strlen($output) - 8)
            ->and($chunks[2])->toBe(F::webpChunks($simple)[0])
            ->and($output)->toContain($iccp);
        msAssertClean($source, $output, 'image/webp');
    })->with(['lossy' => false, 'lossless' => true]);

    it('returns an already clean simple WebP byte for byte', function (bool $lossless) {
        $webp = F::webp(lossless: $lossless);

        expect(msStrip($webp, 'image/webp'))->toBe($webp);
    })->with(['lossy' => false, 'lossless' => true]);

    it('carries the Orientation over as a minimal EXIF chunk with the VP8X flag', function (bool $prefixed) {
        $exif = ($prefixed ? "Exif\0\0" : '').F::cameraExif(6);
        $source = F::webpExtended(F::webp(), 48, 32, after: [F::webpChunk('EXIF', $exif), F::webpChunk('XMP ', F::xmp())]);
        $output = msStrip($source, 'image/webp');
        $chunks = F::webpChunks($output);

        expect(end($chunks))->toBe(['EXIF', Orientation::tiff(6)])
            ->and(ord($chunks[0][1][0]))->toBe(0x08);
        msAssertClean($source, $output, 'image/webp');
    })->with(['raw TIFF' => false, 'Exif prefix' => true]);

    it('drops EXIF from a simple WebP, which cannot carry it', function () {
        $simple = F::webp();
        $source = F::riff(...array_map(fn ($c) => F::webpChunk($c[0], $c[1]), [...F::webpChunks($simple), ['EXIF', F::cameraExif(6)]]));

        expect(msStrip($source, 'image/webp'))->toBe($simple);
    });

    it('keeps animation and drops metadata of an animated WebP', function () {
        $clean = F::webpAnimated([F::webp(), F::webp(lossless: true)], 48, 32);
        $source = F::webpAnimated([F::webp(), F::webp(lossless: true)], 48, 32, [F::webpChunk('XMP ', F::xmp()), F::webpChunk('EXIF', F::cameraExif())]);
        $output = msStrip($source.F::SECRET, 'image/webp');

        expect($output)->toBe($clean)
            ->and(array_column(F::webpChunks($output), 0))->toBe(['VP8X', 'ANIM', 'ANMF', 'ANMF']);
        msAssertClean($source, $output, 'image/webp', decodable: false);
    });

    it('fails closed on malformed WebP structure', function (Closure $make) {
        msFails($make(), 'image/webp');
    })->with([
        'RIFF size past the end' => fn () => substr_replace(F::webp(), pack('V', 1 << 20), 4, 4),
        'chunk size past the RIFF end' => fn () => F::riff(F::webpChunk('VP8 ', 'x').'EXIF'.pack('V', 0xFFFFFFF0)),
        'partial chunk header' => fn () => F::riff(...[...array_map(fn ($c) => F::webpChunk($c[0], $c[1]), F::webpChunks(F::webp())), 'EXI']),
        'no image chunk' => fn () => F::riff(F::vp8x(0, 48, 32), F::webpChunk('ICCP', 'x')),
        'unknown first chunk' => fn () => F::riff(F::webpChunk('ABCD', 'x'), ...array_map(fn ($c) => F::webpChunk($c[0], $c[1]), F::webpChunks(F::webp()))),
        'VP8X with a wrong size' => fn () => F::riff(F::webpChunk('VP8X', str_repeat("\0", 12)), ...array_map(fn ($c) => F::webpChunk($c[0], $c[1]), F::webpChunks(F::webp()))),
        'second VP8X' => fn () => F::webpExtended(F::webp(), 48, 32, after: [F::vp8x(0, 48, 32)]),
        'metadata inside an animation frame' => fn () => F::riff(F::vp8x(0x02, 48, 32), F::webpChunk('ANIM', "\0\0\0\0\0\0"), F::webpChunk('ANMF', str_repeat("\0", 16).F::webpChunk('EXIF', F::SECRET))),
        'ANIM with a smuggled tail' => fn () => F::webpAnimated([F::webp()], 48, 32, [F::webpChunk('ANIM', "\0\0\0\0\0\0".F::SECRET)]),
        'not a WebP' => fn () => 'RIFF'.pack('V', 4).'WAVE',
    ]);
});

describe('GIF', function () {
    beforeEach(function () {
        $this->base = F::gif();
    });

    it('drops comments and application extensions except looping and keeps image data', function () {
        $graphicControl = F::gifExtension(0xF9, "\x00\x0A\x00\x00");
        $clean = F::withGif($this->base, [F::gifLooping(), $graphicControl]);
        $source = F::withGif($this->base, [
            F::gifExtension(0xFE, 'comment '.F::SECRET), F::gifLooping(), F::gifApplication('XMP DataXMP', F::xmp()),
            F::gifApplication('ICCRGBG1012', F::iccProfile()), F::gifApplication('NETSCAPE2.0', "\x02".F::SECRET),
            $graphicControl, F::gifExtension(0xAB, F::SECRET),
        ], 'trailing '.F::SECRET);
        $output = msStrip($source, 'image/gif');

        expect($output)->toBe($clean);
        msAssertClean($source, $output, 'image/gif');
    });

    it('returns an already clean GIF byte for byte', function () {
        expect(msStrip($this->base, 'image/gif'))->toBe($this->base);
    });

    it('fails closed on malformed GIF structure', function (Closure $mutate) {
        msFails($mutate($this->base), 'image/gif');
    })->with([
        'no trailer' => fn (string $g) => substr($g, 0, -1),
        'truncated sub-blocks' => fn (string $g) => substr($g, 0, -10),
        'invalid block' => fn (string $g) => F::withGif($g, ["\x99"]),
        'no image' => fn (string $g) => substr($g, 0, 13 + 3 * (2 << (ord($g[10]) & 7)))."\x3B",
        'Graphic Control with a smuggled tail' => fn (string $g) => F::withGif($g, ["\x21\xF9\x04\x00\x0A\x00\x00".chr(strlen(F::SECRET)).F::SECRET."\x00"]),
        'Plain Text with a wrong header' => fn (string $g) => F::withGif($g, [F::gifExtension(0x01, F::SECRET)]),
        'invalid LZW code size' => function (string $g) {
            $image = msGifImage($g);
            $local = (ord($g[$image + 9]) & 0x80) === 0 ? 0 : 3 * (2 << (ord($g[$image + 9]) & 7));

            return substr_replace($g, "\x00", $image + 10 + $local, 1);
        },
        'not a GIF' => fn (string $g) => 'GIF90a'.substr($g, 6),
    ]);
});

describe('robustness', function () {
    $cores = [
        'jpeg' => ['camera-gps-orientation6.jpg', 'image/jpeg'],
        'png' => ['camera-gps.png', 'image/png'],
        'webp' => ['camera-gps-orientation6.webp', 'image/webp'],
        'gif' => ['comment-xmp.gif', 'image/gif'],
    ];

    it('fails on every truncation of the image', function (string $file, string $mime) {
        $bytes = file_get_contents(dirname(__DIR__).'/Fixtures/images/'.$file);

        if ($mime === 'image/jpeg') {
            $eoi = array_values(array_filter(F::jpegMarkers($bytes), fn ($m) => $m[0] === 0xD9))[0][1];
            $bytes = substr($bytes, 0, $eoi + 2);
        }

        for ($length = 0; $length < strlen($bytes); $length += max(1, intdiv(strlen($bytes), 300))) {
            msFails(substr($bytes, 0, $length), $mime);
        }
    })->with($cores);

    it('either fails or yields a clean, idempotent file for corrupted input', function (string $file, string $mime) {
        $bytes = file_get_contents(dirname(__DIR__).'/Fixtures/images/'.$file);
        mt_srand(crc32($file));

        for ($i = 0; $i < 150; $i++) {
            $mutated = $bytes;

            for ($n = mt_rand(1, 3); $n > 0; $n--) {
                $mutated[mt_rand(0, strlen($bytes) - 1)] = chr(mt_rand(0, 255));
            }

            try {
                $output = msStrip($mutated, $mime);
            } catch (MetadataStripFailed) {
                continue;
            }

            expect($output)->not->toContain(F::SECRET)
                ->and(msStrip($output, $mime))->toBe($output);
        }
    })->with($cores);

    it('bounds the number of blocks it processes', function () {
        $gif = F::withGif(F::gif(), [str_repeat("\x21\xAB\x00", 100001)]);

        msFails($gif, 'image/gif');
    });
});

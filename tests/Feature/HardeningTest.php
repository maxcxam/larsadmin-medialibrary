<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\Fields\MediaGallery;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Article;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\ConditionalPost;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities\Articles;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities\Posts;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Post;
use Spatie\MediaLibrary\Conversions\Events\ConversionWillStartEvent;

/** Fixes of the 1.0 code and security reviews (CRM-01, 03, 05, 06; SECM-01, 02, 03). */
beforeEach(function () {
    registerArticles(Posts::class);
    $this->actingAs($this->admin);
});

/** @return array<string, list<string>> validation errors of a fill, [] when it passed */
function hardeningErrors(Media $field, Model $record, array $files): array
{
    try {
        test()->fillField($field, $record, [$field->attribute => ['present' => 1]], [$field->attribute => ['upload' => $files]]);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

function avifFile(string $name = 'photo.avif'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "\0\0\0\x1cftypavif\0\0\0\0avifmif1miaf\0\0\0\0");
}

describe('CRM-01: images() does not depend on the framework "image" rule', function () {
    it('checks explicit raster content types and extensions, without AVIF by default', function () {
        $rules = Media::make('Cover', 'cover')->images()->rulesFor(Field::CREATE, new Post)['cover.upload.*'];

        expect($rules)->not->toContain('image')
            ->toContain('mimetypes:image/jpeg,image/png,image/gif,image/webp')
            ->toContain('extensions:jpg,jpeg,png,gif,webp')
            ->toContain('mimes:jpg,jpeg,png,gif,webp');
    });

    it('allows AVIF only when types() names it', function () {
        $rules = Media::make('Cover', 'cover')->images()->types(['jpg', 'avif'])->rulesFor(Field::CREATE, new Post)['cover.upload.*'];

        expect($rules)->toContain('mimetypes:image/jpeg,image/avif')->toContain('extensions:jpg,avif');
    });

    it('refuses AVIF by default, SVG always, and accepts JPEG and PNG', function () {
        $post = Post::create(['title' => 'Post']);
        $field = fn () => Media::make('Cover', 'cover')->images();

        expect(hardeningErrors($field(), $post, [avifFile()]))->toHaveKey('cover.upload.0')
            ->and(hardeningErrors($field(), $post, [UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')]))->toHaveKey('cover.upload.0')
            ->and(hardeningErrors($field(), $post, [photo('a.jpg')]))->toBe([])
            ->and(hardeningErrors($field(), $post, [photo('b.png')]))->toBe([]);
    });

    it('refuses everything when types() names no raster type', function () {
        expect(hardeningErrors(Media::make('Cover', 'cover')->images()->types(['pdf']), Post::create(['title' => 'Post']), [pdfFile()]))
            ->toHaveKey('cover.upload.0');
    });
});

describe('CRM-03: a single Media field never deletes media it did not show', function () {
    it('refuses a declared collection that may hold several media', function () {
        $post = postWith();

        expect(fn () => Media::make('Gallery', 'gallery')->declaredCollection($post))->toThrow(LogicException::class, 'singleFile()')
            ->and(fn () => Media::make('Screened', 'screened')->rulesFor(Field::UPDATE, $post))->toThrow(LogicException::class)
            ->and(Media::make('Cover', 'cover')->declaredCollection($post)?->singleFile)->toBeTrue();
    });

    it('keeps every media of an undeclared collection on a save without changes, even when not removable', function (bool $removable) {
        $post = postWith(['loose' => [photo('a.jpg'), photo('b.jpg')]]);
        [$a, $b] = mediaIds($post, 'loose');
        $field = Media::make('Loose', 'loose')->removable($removable);

        $this->fillField($field, $post, ['loose' => ['present' => 1, 'known' => [$a], 'keep' => [$a]]]);

        expect(mediaIds($post, 'loose'))->toBe([$a, $b]);
    })->with([true, false]);

    it('replaces only the media the form showed', function () {
        $post = postWith(['loose' => [photo('a.jpg'), photo('b.jpg')]]);
        [$a, $b] = mediaIds($post, 'loose');

        $this->fillField(Media::make('Loose', 'loose'), $post, ['loose' => ['present' => 1, 'known' => [$a], 'keep' => [$a]]], ['loose' => ['upload' => [photo('c.jpg')]]]);

        $ids = mediaIds($post, 'loose');

        expect($ids)->toHaveCount(2)->toContain($b)->not->toContain($a);
    });
});

describe('CRM-05: conversions registered per media', function () {
    it('accepts a conversion registered only for some media', function () {
        $post = ConditionalPost::create(['title' => 'Post']);

        expect(Media::make('Cover', 'cover')->conversion('thumb')->declaredCollection($post)?->name)->toBe('cover');
    });

    it('still refuses an unknown conversion of an unconditional registration', function () {
        expect(fn () => Media::make('Cover', 'cover')->conversion('nope')->declaredCollection(new Post))->toThrow(LogicException::class, '[nope]');
    });
});

describe('CRM-06: maxSize()', function () {
    it('is capped by larsadmin.files.max_size and media-library.max_file_size', function () {
        config(['larsadmin.files.max_size' => 100, 'media-library.max_file_size' => 1024 * 1024]);

        expect(Media::make('Cover', 'cover')->maxSize(5000)->maxSizeKilobytes())->toBe(100)
            ->and(Media::make('Cover', 'cover')->maxSize(50)->maxSizeKilobytes())->toBe(50)
            ->and(Media::make('Cover', 'cover')->maxSizeKilobytes())->toBe(100);

        config(['media-library.max_file_size' => 20 * 1024]);

        expect(Media::make('Cover', 'cover')->maxSize(50)->maxSizeKilobytes())->toBe(20);
    });
});

describe('SECM-01: private() is about links, not where files live', function () {
    it('refuses private() on a public upload disk', function () {
        expect(fn () => MediaGallery::make('Docs', 'screened')->disk('media_public')->private()->declaredCollection(new Post))
            ->toThrow(LogicException::class, 'is public')
            ->and(fn () => Media::make('Docs', 'docs')->private()->rulesFor(Field::CREATE, new Post))
            ->toThrow(LogicException::class, 'is public');
    });

    it('applies the public blocked list by the disk, and to public() fields on a private disk', function () {
        config(['larsadmin.files.blocked_extensions' => ['php']]);
        $svg = fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $article = Article::create(['title' => 'Live']);

        expect(hardeningErrors(Media::make('Contract', 'contract')->public(), $article, [$svg()]))->toHaveKey('contract.upload.0')
            ->and(hardeningErrors(Media::make('Contract', 'contract')->private(), $article, [$svg()]))->toBe([])
            ->and(hardeningErrors(Media::make('Docs', 'docs'), Post::create(['title' => 'Post']), [$svg()]))->toHaveKey('docs.upload.0');
    });

    it('serializes no public or original URL for a private field', function () {
        $article = articleWith(['contract' => [pdfFile('c.pdf')]]);

        $json = json_encode($this->serializeField(Media::make('Contract', 'contract')->private(), $article, Field::DETAIL, Articles::class, recordAbilities($article)));

        expect($json)->not->toContain('original_url')
            ->not->toContain('preview_url')
            ->not->toContain('/storage/')
            ->toContain('signature');
    });
});

describe('SECM-02: no orphan files when addMedia() fails after writing', function () {
    beforeEach(fn () => Event::listen(ConversionWillStartEvent::class, fn () => throw new RuntimeException('conversion failed')));

    it('discards the written original through the journal in a panel save', function () {
        $this->withoutExceptionHandling();

        expect(fn () => $this->post('/admin/entities/posts', ['title' => 'Post', 'cover' => ['present' => 1, 'upload' => [photo('a.jpg')]]]))
            ->toThrow(RuntimeException::class, 'conversion failed');

        expect(Post::count())->toBe(0)
            ->and(DB::table('media')->count())->toBe(0)
            ->and(Storage::disk('media_private')->allFiles())->toBe([]);
    });

    it('deletes the row and the files at once outside a panel save', function () {
        $post = Post::create(['title' => 'Post']);
        $request = Request::create('/', 'POST', ['cover' => ['present' => 1]], [], ['cover' => ['upload' => [photo('a.jpg')]]]);

        expect(fn () => Media::make('Cover', 'cover')->fill($request, $post)($post))->toThrow(RuntimeException::class, 'conversion failed');

        expect(DB::table('media')->count())->toBe(0)
            ->and(Storage::disk('media_private')->allFiles())->toBe([]);
    });
});

describe('SECM-03: pixel limit', function () {
    it('defaults to 40 megapixels', function () {
        expect(Media::maxPixels())->toBe(40_000_000);
    });

    it('refuses images with more pixels than larsadmin.files.max_pixels before storing anything', function () {
        config(['larsadmin.files.max_pixels' => 1000]);
        $post = Post::create(['title' => 'Post']);

        $errors = hardeningErrors(Media::make('Cover', 'cover')->images(), $post, [photo('big.jpg', 64, 48)]);

        expect($errors)->toHaveKey('cover.upload.0')
            ->and($errors['cover.upload.0'][0])->toBe(__('larsadmin-medialibrary::messages.too_many_pixels', ['attribute' => 'Cover', 'max' => 0.0]))
            ->and(DB::table('media')->count())->toBe(0)
            ->and(hardeningErrors(Media::make('Cover', 'cover')->images(), $post, [photo('small.jpg', 20, 20)]))->toBe([])
            // Not an image: no limit.
            ->and(hardeningErrors(Media::make('Docs', 'docs'), $post, [pdfFile()]))->toBe([]);
    });
});

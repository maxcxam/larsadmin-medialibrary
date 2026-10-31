<?php

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\Fields\MediaGallery;
use Maxcxam\LarsadminMedialibrary\Rules\StrippableImage;
use Maxcxam\LarsadminMedialibrary\Support\StripWorkspace;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities\Posts;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Post;
use Maxcxam\LarsadminMedialibrary\Tests\Support\ImageFactory as F;
use Spatie\MediaLibrary\MediaCollections\Models\Media as MediaModel;

/*
 * ADR-047, design 1.0 §3.1: opt-in stripMetadata() on panel uploads; fail closed (422 on that file, nothing stored),
 * conversions from the stripped original, non-images untouched, temporary copies removed.
 */

/** The Post collections with stripMetadata() on every field (Posts has the same fields without it). */
class StripMetadataPosts extends Entity
{
    public static string $model = Post::class;

    public static string $title = 'title';

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Title')->rules('required', 'max:255'),
            Media::make('Cover', 'cover')->images()->conversion('thumb')->stripMetadata(),
            MediaGallery::make('Gallery', 'gallery')->images()->conversion('thumb')->limit(5)->stripMetadata(),
            Media::make('Docs', 'docs')->stripMetadata(),
            MediaGallery::make('Screened', 'screened')->stripMetadata(),
        ];
    }
}

beforeEach(function () {
    registerArticles(StripMetadataPosts::class, Posts::class);
    $this->actingAs($this->admin);
    $this->tempBefore = smTempEntries();
});

afterEach(function () {
    Media::discardStripped();
});

function smFixture(string $name): string
{
    return file_get_contents(dirname(__DIR__).'/Fixtures/images/'.$name);
}

function smUpload(string $name, string $bytes): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

function smPhone(string $name = 'phone.jpg'): UploadedFile
{
    return smUpload($name, smFixture('camera-gps-orientation6.jpg'));
}

/** @return list<string> the temporary directories of stripped copies */
function smTempEntries(): array
{
    return glob((new StripWorkspace)->root().DIRECTORY_SEPARATOR.'*') ?: [];
}

function smStored(MediaModel $media, string $conversion = ''): string
{
    $disk = $conversion === '' ? $media->disk : ($media->conversions_disk ?: $media->disk);

    return Storage::disk($disk)->get($media->getPathRelativeToRoot($conversion));
}

/** @return array<string, mixed> EXIF of the stored original (exif_read_data) */
function smExif(MediaModel $media): array
{
    return @exif_read_data(Storage::disk($media->disk)->path($media->getPathRelativeToRoot())) ?: [];
}

function smCreate(array $data, string $entity = 'strip-metadata-posts'): TestResponse
{
    return test()->post("/admin/entities/{$entity}", ['title' => 'Stripped', ...$data]);
}

function smMessage(string $key, string $attribute): string
{
    return __("larsadmin-medialibrary::messages.{$key}", ['attribute' => $attribute]);
}

describe('stripping', function () {
    it('stores the JPEG original without GPS, EXIF or other metadata, keeping the orientation', function () {
        smCreate(['cover' => ['present' => 1, 'upload' => [smPhone()]]])->assertRedirect()->assertSessionHasNoErrors();

        $media = Post::firstWhere('title', 'Stripped')->getFirstMedia('cover');
        $stored = smStored($media);
        $exif = smExif($media);

        expect($stored)->not->toContain(F::SECRET)
            ->and($stored)->not->toBe(smFixture('camera-gps-orientation6.jpg'))
            ->and(F::pixels($stored))->toBe(F::pixels(smFixture('camera-gps-orientation6.jpg')))
            ->and($exif)->not->toHaveKey('GPSLatitude')
            ->and($exif)->not->toHaveKey('Model')
            ->and($exif)->not->toHaveKey('Artist')
            ->and($exif['Orientation'] ?? null)->toBe(6)
            ->and(getimagesizefromstring($stored)[0])->toBe(120);
    });

    it('keeps the original name and MIME type and generates conversions from the stripped original', function () {
        smCreate(['cover' => ['present' => 1, 'upload' => [smPhone('Holiday photo.jpg')]]])->assertSessionHasNoErrors();

        $media = Post::firstWhere('title', 'Stripped')->getFirstMedia('cover');

        expect($media->getCustomProperty('original_name'))->toBe('Holiday photo.jpg')
            ->and($media->name)->toBe('Holiday photo')
            ->and($media->mime_type)->toBe('image/jpeg')
            ->and($media->file_name)->toEndWith('.jpg')
            ->and($media->size)->toBe(strlen(smStored($media)))
            ->and($media->hasGeneratedConversion('thumb'))->toBeTrue()
            ->and(smStored($media, 'thumb'))->not->toContain(F::SECRET);
    });

    it('strips PNG, WebP and GIF uploads of a gallery, each file of the request', function () {
        $files = [
            smUpload('a.png', smFixture('camera-gps.png')),
            smUpload('b.webp', smFixture('camera-gps-orientation6.webp')),
        ];

        smCreate(['gallery' => ['present' => 1, 'upload' => $files]])->assertSessionHasNoErrors();
        smCreate(['title' => 'Gif', 'screened' => ['present' => 1, 'upload' => [smUpload('c.gif', smFixture('comment-xmp.gif'))]]])->assertSessionHasNoErrors();

        $gallery = Post::firstWhere('title', 'Stripped')->getMedia('gallery');
        $gif = Post::firstWhere('title', 'Gif')->getFirstMedia('screened');

        expect($gallery)->toHaveCount(2)
            ->and($gallery->map(fn ($media) => $media->getCustomProperty('original_name'))->all())->toBe(['a.png', 'b.webp'])
            ->and($gallery->every(fn ($media) => ! str_contains(smStored($media), F::SECRET)))->toBeTrue()
            ->and($gallery->every(fn ($media) => $media->hasGeneratedConversion('thumb')))->toBeTrue()
            ->and($gallery[0]->mime_type)->toBe('image/png')
            ->and($gallery[1]->mime_type)->toBe('image/webp')
            ->and(smStored($gif))->not->toContain(F::SECRET)
            ->and($gif->mime_type)->toBe('image/gif');
    });

    it('stores non-images (PDF) unchanged', function () {
        $pdf = pdfFile();
        $bytes = file_get_contents($pdf->getRealPath());

        smCreate(['docs' => ['present' => 1, 'upload' => [$pdf]]])->assertSessionHasNoErrors();

        $media = Post::firstWhere('title', 'Stripped')->getFirstMedia('docs');

        expect(smStored($media))->toBe($bytes)
            ->and($media->mime_type)->toBe('application/pdf');
    });

    it('is off by default: the original is stored byte for byte, GPS included', function () {
        smCreate(['cover' => ['present' => 1, 'upload' => [smPhone()]]], 'posts')->assertSessionHasNoErrors();

        $media = Post::firstWhere('title', 'Stripped')->getFirstMedia('cover');

        expect(smStored($media))->toBe(smFixture('camera-gps-orientation6.jpg'))
            ->and(smExif($media))->toHaveKey('GPSLatitude')
            ->and(Media::make('Cover', 'cover')->stripsMetadata())->toBeFalse()
            ->and(collect(Media::make('Cover', 'cover')->rulesFor(Field::CREATE, new Post)['cover.upload.*'])->contains(fn ($rule) => $rule instanceof StrippableImage))->toBeFalse();
    });

    it('adds the rule last, after the type and user rules', function () {
        $rules = Media::make('Cover', 'cover')->images()->rules('dimensions:min_width=1')->stripMetadata()->rulesFor(Field::CREATE, new Post)['cover.upload.*'];

        expect(end($rules))->toBeInstanceOf(StrippableImage::class)
            ->and(Media::make('Cover', 'cover')->stripMetadata(false)->stripsMetadata())->toBeFalse();
    });

    it('updates a record: the replacement is stripped, the old media removed', function () {
        $post = postWith(['cover' => [photo()]], 'Stripped');

        $this->put("/admin/entities/strip-metadata-posts/{$post->id}", ['title' => 'Stripped', 'cover' => ['present' => 1, 'upload' => [smPhone()]]])
            ->assertSessionHasNoErrors();

        $media = $post->fresh()->getMedia('cover');

        expect($media)->toHaveCount(1)
            ->and(smStored($media[0]))->not->toContain(F::SECRET);
    });
});

describe('fail closed', function () {
    it('answers 422 for unsupported image types and stores nothing', function (string $name, Closure $bytes, string $field) {
        smCreate([$field => ['present' => 1, 'upload' => [smUpload($name, $bytes())]]])
            ->assertSessionHasErrors(["{$field}.upload.0" => smMessage('metadata_unsupported', ucfirst($field))]);

        expect(Post::count())->toBe(0)
            ->and(DB::table('media')->count())->toBe(0)
            ->and(Storage::disk('media_private')->allFiles())->toBe([])
            ->and(smTempEntries())->toBe($this->tempBefore);
    })->with([
        'AVIF' => ['photo.avif', fn () => F::avif(), 'screened'],
        'HEIC' => ['photo.heic', fn () => "\0\0\0\x18ftypheic\0\0\0\0mif1heic", 'screened'],
        'TIFF' => ['photo.tiff', fn () => F::tiff([0x0100 => [3, 1], 0x0101 => [3, 1]]), 'screened'],
    ]);

    it('answers 422 for a damaged JPEG and stores nothing', function (string $field) {
        $corrupt = substr(smFixture('camera-gps-orientation6.jpg'), 0, 400);

        smCreate([$field => ['present' => 1, 'upload' => [smUpload('broken.jpg', $corrupt)]]])
            ->assertSessionHasErrors(["{$field}.upload.0" => smMessage('metadata_failed', ucfirst($field))]);

        expect(DB::table('media')->count())->toBe(0)
            ->and(Storage::disk('media_private')->allFiles())->toBe([])
            ->and(smTempEntries())->toBe($this->tempBefore);
    })->with(['cover', 'screened']);

    it('points at the failing file of several and removes the copies of the others', function () {
        $files = [smPhone('a.jpg'), smUpload('b.avif', F::avif()), smPhone('c.jpg')];

        smCreate(['screened' => ['present' => 1, 'upload' => $files]])
            ->assertSessionHasErrors(['screened.upload.1'])
            ->assertSessionDoesntHaveErrors(['screened.upload.0', 'screened.upload.2']);

        expect(DB::table('media')->count())->toBe(0)
            ->and(Storage::disk('media_private')->allFiles())->toBe([])
            ->and(smTempEntries())->toBe($this->tempBefore);
    });

    it('works alongside onlyKeepLatest(), acceptsMimeTypes() and acceptsFile()', function () {
        smCreate(['gallery' => ['present' => 1, 'upload' => [smPhone('a.jpg'), smPhone('b.jpg'), smPhone('c.jpg')]]])
            ->assertSessionHasErrors(['gallery']);
        smCreate(['docs' => ['present' => 1, 'upload' => [smPhone()]]])->assertSessionHasErrors(['docs.upload.0']);
        smCreate(['screened' => ['present' => 1, 'upload' => [textFile()]]])
            ->assertSessionHasErrors(['screened.upload.0' => smMessage('not_accepted', 'Screened')]);

        expect(DB::table('media')->count())->toBe(0)
            ->and(smTempEntries())->toBe($this->tempBefore);
    });

    it('turns a failure in the save closure into a validation error of that file', function () {
        $post = postWith([], 'Outside');
        $field = MediaGallery::make('Screened', 'screened')->stripMetadata();
        $request = Request::create('/', 'POST', ['screened' => ['present' => 1]]);
        $request->files->set('screened', ['upload' => [smPhone(), smUpload('b.avif', F::avif())]]);

        try {
            $field->fill($request, $post)($post);
            $this->fail('No ValidationException.');
        } catch (ValidationException $e) {
            expect($e->errors())->toBe(['screened.upload.1' => [smMessage('metadata_unsupported', 'Screened')]]);
        }

        expect(smTempEntries())->toBe($this->tempBefore);
    });
});

describe('the stripped copy', function () {
    it('is written once per upload and reused by the save, then deleted', function () {
        $field = Media::make('Cover', 'cover')->stripMetadata();
        $upload = smPhone();

        $first = $field->strippedCopy($upload);

        expect($field->strippedCopy($upload))->toBe($first)
            ->and(MediaGallery::make('Gallery', 'gallery')->stripMetadata()->strippedCopy($upload))->toBe($first)
            ->and(file_get_contents($first))->not->toContain(F::SECRET)
            ->and(file_get_contents($upload->getRealPath()))->toBe(smFixture('camera-gps-orientation6.jpg'))
            ->and($field->strippedCopy(pdfFile()))->toBeNull();

        $post = postWith([], 'Outside');
        $request = Request::create('/', 'POST', ['cover' => ['present' => 1]]);
        $request->files->set('cover', ['upload' => [$upload]]);
        $field->fill($request, $post)($post);

        expect(file_exists($first))->toBeFalse()
            ->and(smTempEntries())->toBe($this->tempBefore)
            ->and(smStored($post->fresh()->getFirstMedia('cover')))->not->toContain(F::SECRET);
    });

    it('strips again in fill() outside the panel when nothing was cached', function () {
        $post = postWith([], 'Outside');
        $request = Request::create('/', 'POST', ['cover' => ['present' => 1]]);
        $request->files->set('cover', ['upload' => [smPhone()]]);

        Media::make('Cover', 'cover')->stripMetadata()->fill($request, $post)($post);

        $media = $post->fresh()->getFirstMedia('cover');

        expect(smStored($media))->not->toContain(F::SECRET)
            ->and(smExif($media)['Orientation'] ?? null)->toBe(6)
            ->and(smTempEntries())->toBe($this->tempBefore);
    });

    it('is discarded when the application terminates', function () {
        $first = Media::make('Cover', 'cover')->stripMetadata()->strippedCopy(smPhone());

        expect(file_exists($first))->toBeTrue();

        app()->terminate();

        expect(file_exists($first))->toBeFalse()
            ->and(smTempEntries())->toBe($this->tempBefore);
    });

    it('does not change media added outside the panel', function () {
        $post = postWith(['cover' => [smPhone()]]);

        expect(smStored($post->getFirstMedia('cover')))->toBe(smFixture('camera-gps-orientation6.jpg'));
    });
});

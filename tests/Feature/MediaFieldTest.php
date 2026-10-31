<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\Fields\MediaGallery;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Article;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\ArticlePolicy;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities\Articles;

beforeEach(function () {
    registerArticles();
    $this->actingAs($this->admin);
});

/** Serialized as the panel does, bound to its entity (serializeField(..., $entity), ADR-035). */
function serialized(Media $field, ?Article $record, string $context = Field::DETAIL): array
{
    return test()->serializeField($field, $record, $context, Articles::class);
}

it('serializes as a multipart extension field with the form payload', function () {
    $article = articleWith(['gallery' => [photo('a.jpg'), photo('b.jpg')]]);
    $ids = mediaIds($article, 'gallery');

    $json = serialized(MediaGallery::make('Gallery', 'gallery')->images()->limit(3), $article, Field::UPDATE);

    expect($json)->toMatchArray([
        'component' => 'maxcxam/media-gallery',
        'multipart' => true,
        'multiple' => true,
        'maxFiles' => 3,
        'value' => ['present' => 1, 'known' => $ids, 'keep' => $ids],
        'displayedAs' => 'a.jpg, b.jpg',
        'accept' => '.jpg,.jpeg,.png,.gif,.webp,.avif',
    ])->and($json)->not->toHaveKey('files')
        ->and(array_column($json['media'], 'name'))->toBe(['a.jpg', 'b.jpg'])
        ->and($json['media'][0]['isImage'])->toBeTrue();
});

it('serializes an empty create payload', function () {
    $json = serialized(Media::make('Cover', 'cover'), null, Field::CREATE);

    expect($json['value'])->toBe(['present' => 1, 'known' => [], 'keep' => []])
        ->and($json['media'])->toBe([]);
});

it('signs relative URLs for media on a private disk', function () {
    $article = articleWith(['gallery' => [photo('a.jpg')]]);
    $id = mediaIds($article, 'gallery')[0];

    $json = serialized(MediaGallery::make('Gallery', 'gallery')->conversion('thumb'), $article, Field::DETAIL);
    $item = $json['media'][0];

    expect($item['url'])->toStartWith("/admin/ext/medialibrary/articles/{$article->id}/gallery/{$id}?expires=")
        ->and($item['downloadUrl'])->toContain('download=1')
        ->and($item['thumbnailUrl'])->toContain('conversion=thumb')
        ->and($json['urlsExpireAt'])->not->toBeNull()
        ->and($json['value'])->toBeNull();
});

it('uses the storage URL for media on a public disk', function () {
    $article = articleWith(['cover' => [photo('c.jpg')]]);

    $item = serialized(Media::make('Cover', 'cover'), $article, Field::DETAIL)['media'][0];

    expect($item['url'])->toStartWith('/storage/')
        ->and($item['url'])->not->toContain('signature');
});

it('emits no URLs when the user may not view the record', function () {
    ArticlePolicy::$view = false;
    $article = articleWith(['gallery' => [photo('a.jpg')]]);

    $item = serialized(MediaGallery::make('Gallery', 'gallery'), $article, Field::DETAIL)['media'][0];

    expect($item['url'])->toBeNull()->and($item['name'])->toBe('a.jpg');
});

it('sends only the first item and a count on the index', function () {
    $article = articleWith(['gallery' => [photo('a.jpg'), photo('b.jpg')]]);

    $json = serialized(MediaGallery::make('Gallery', 'gallery'), $article, Field::INDEX);

    expect($json['media'])->toHaveCount(1)
        ->and($json['count'])->toBe(2)
        ->and($json['media'][0])->not->toHaveKey('downloadUrl');
});

it('cannot be sortable and requires a HasMedia model', function () {
    expect(fn () => Media::make('Cover', 'cover')->sortable())->toThrow(LogicException::class);

    $plain = new class extends Model {};
    $plain->exists = true;

    expect(fn () => Media::make('Cover', 'cover')->resolve($plain))->toThrow(LogicException::class);
});

it('rejects an unknown disk at definition time', function () {
    Media::make('Cover', 'cover')->disk('nope');
})->throws(InvalidArgumentException::class);

describe('fill', function () {
    it('stores an upload under a random name and keeps the sanitized client name', function () {
        $article = Article::create(['title' => 'Live']);
        $field = Media::make('Cover', 'cover')->images()->disk('media_public');

        $this->fillField($field, $article, ['cover' => ['present' => 1]], ['cover' => ['upload' => [photo('My "holiday".jpg')]]]);

        $media = $article->fresh()->getFirstMedia('cover');

        expect($media->disk)->toBe('media_public')
            ->and($media->file_name)->toMatch('/^[A-Za-z0-9]{40}\.jpg$/')
            ->and($field->displayName($media))->toBe('My holiday.jpg')
            ->and(Storage::disk('media_public')->exists($media->getPathRelativeToRoot()))->toBeTrue();
    });

    it('replaces the single file', function () {
        $article = articleWith(['contract' => [UploadedFile::fake()->create('old.pdf', 10, 'application/pdf')]]);
        $old = $article->getFirstMedia('contract');

        $this->fillField(Media::make('Contract', 'contract'), $article, ['contract' => ['present' => 1, 'known' => [$old->id], 'keep' => [$old->id]]], ['contract' => ['upload' => [UploadedFile::fake()->create('new.pdf', 10, 'application/pdf')]]]);

        expect($article->fresh()->getMedia('contract'))->toHaveCount(1)
            ->and($article->fresh()->getFirstMedia('contract')->getCustomProperty('original_name'))->toBe('new.pdf')
            ->and(Storage::disk('media_private')->exists($old->getPathRelativeToRoot()))->toBeFalse();
    });

    it('reorders, removes only known media and appends uploads', function () {
        $article = articleWith(['gallery' => [photo('a.jpg'), photo('b.jpg'), photo('c.jpg')]]);
        [$a, $b, $c] = mediaIds($article, 'gallery');
        $field = MediaGallery::make('Gallery', 'gallery')->limit(5);

        // The form knew a and b only (c was added in another tab); it removes a and moves b first.
        $this->fillField($field, $article, ['gallery' => ['present' => 1, 'known' => [$a, $b], 'keep' => [$b]]], ['gallery' => ['upload' => [photo('d.jpg')]]]);

        $names = $article->fresh()->getMedia('gallery')->map(fn ($media) => $field->displayName($media))->all();

        expect($names)->toBe(['b.jpg', 'c.jpg', 'd.jpg']);
    });

    it('ignores forged ids of other records and a non-array value', function () {
        $other = articleWith(['gallery' => [photo('x.jpg')]], 'Other');
        $article = articleWith(['gallery' => [photo('a.jpg')]]);
        $foreign = mediaIds($other, 'gallery')[0];
        $field = MediaGallery::make('Gallery', 'gallery');

        $this->fillField($field, $article, ['gallery' => ['present' => 1, 'known' => [$foreign], 'keep' => [$foreign]]]);

        expect(mediaIds($other, 'gallery'))->toBe([$foreign])
            ->and($article->fresh()->getMedia('gallery'))->toHaveCount(1);

        expect(fn () => $this->fillField($field, $article, ['gallery' => '../../.env']))->toThrow(ValidationException::class);
    });

    it('keeps everything when the field is absent', function () {
        $article = articleWith(['gallery' => [photo('a.jpg')]]);

        expect(MediaGallery::make('Gallery', 'gallery')->fill(Request::create('/', 'POST', ['title' => 'x']), $article))->toBeNull();
    });
});

describe('validation', function () {
    function rejects(Media $field, array $files, array $input = [], ?Article $article = null): array
    {
        $article ??= Article::create(['title' => 'Live']);

        try {
            test()->fillField($field, $article, $input + [$field->attribute => ['present' => 1]], $files);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    it('rejects a wrong type, an oversize file, a blocked multi-extension and SVG for images', function () {
        $images = Media::make('Cover', 'cover')->images()->maxSize(20);

        expect(rejects($images, ['cover' => ['upload' => [UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')]]]))->toHaveKey('cover.upload.0')
            ->and(rejects($images, ['cover' => ['upload' => [photo('big.jpg', 2000, 2000)->size(100)]]]))->toHaveKey('cover.upload.0')
            ->and(rejects(Media::make('Contract', 'contract'), ['contract' => ['upload' => [UploadedFile::fake()->create('x.php.txt', 1, 'text/plain')]]]))->toHaveKey('contract.upload.0')
            ->and(rejects($images, ['cover' => ['upload' => [UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')]]]))->toHaveKey('cover.upload.0');
    });

    it('rejects PHP content renamed to .jpg', function () {
        // A real upload (fake files report the MIME type of their name, not their content).
        $path = tempnam(sys_get_temp_dir(), 'lml');
        file_put_contents($path, '<?php system($_GET["c"]);');
        $file = new UploadedFile($path, 'shell.jpg', null, null, true);

        expect(rejects(Media::make('Cover', 'cover')->images(), ['cover' => ['upload' => [$file]]]))->toHaveKey('cover.upload.0');
    });

    it('limits the number of media, counting kept ones', function () {
        $article = articleWith(['gallery' => [photo('a.jpg'), photo('b.jpg')]]);
        $ids = mediaIds($article, 'gallery');

        $errors = rejects(MediaGallery::make('Gallery', 'gallery')->limit(3), ['gallery' => ['upload' => [photo('c.jpg'), photo('d.jpg')]]], ['gallery' => ['present' => 1, 'known' => $ids, 'keep' => $ids]], $article);

        expect($errors['gallery'][0])->toContain('3');
    });

    it('requires a file and lifts required out of the per-file rules', function () {
        $field = Media::make('Cover', 'cover')->images()->rules('required');

        expect($field->isRequired())->toBeTrue()
            ->and($field->rulesFor(Field::CREATE)['cover.upload.*'])->not->toContain('required')
            ->and(rejects($field, []))->toHaveKey('cover');

        // Removing the only file of a required field fails too.
        $article = articleWith(['cover' => [photo('c.jpg')]]);
        $id = $article->getFirstMedia('cover')->id;

        expect(rejects($field, [], ['cover' => ['present' => 1, 'known' => [$id]]], $article))->toHaveKey('cover');
    });

    it('applies user rules to every uploaded file', function () {
        $field = MediaGallery::make('Gallery', 'gallery')->images()->rules('dimensions:min_width=100');

        expect(rejects($field, ['gallery' => ['upload' => [photo('ok.jpg', 200, 200), photo('small.jpg', 10, 10)]]]))
            ->toHaveKey('gallery.upload.1')
            ->not->toHaveKey('gallery.upload.0');
    });
});

<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\Fields\MediaGallery;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities\Posts;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Post;

/**
 * The model's collection declaration is the source of truth (ADR-042, design §2.1): useDisk(), singleFile(),
 * onlyKeepLatest(n), acceptsMimeTypes(), acceptsFile() and the registered conversions.
 */
beforeEach(function () {
    registerArticles(Posts::class);
    $this->actingAs($this->admin);
});

function serializedPost(Media $field, ?Post $record, string $context = Field::DETAIL): array
{
    return test()->serializeField($field, $record, $context, Posts::class, $record ? ['view' => true, 'update' => true, 'delete' => true] : []);
}

describe('upload disk', function () {
    it('takes the field disk, then the collection disk, then media-library.disk_name', function () {
        $post = new Post;

        expect(Media::make('Docs', 'docs')->uploadDisk($post))->toBe('media_public')
            ->and(Media::make('Docs', 'docs')->disk('media_private')->uploadDisk($post))->toBe('media_private')
            ->and(Media::make('Cover', 'cover')->uploadDisk($post))->toBe('media_private')
            ->and(Media::make('Loose', 'undeclared')->uploadDisk($post))->toBe('media_private');
    });

    it('stores panel uploads on the collection disk', function () {
        $this->post('/admin/entities/posts', ['title' => 'Docs', 'docs' => ['present' => 1, 'upload' => [pdfFile()]]])
            ->assertRedirect()->assertSessionHasNoErrors();

        $media = Post::firstWhere('title', 'Docs')->getFirstMedia('docs');

        expect($media->disk)->toBe('media_public')
            ->and(Storage::disk('media_public')->exists($media->getPathRelativeToRoot()))->toBeTrue();
    });

    it('treats uploads to a public collection disk as public for the blocked-extension check', function () {
        // .html is refused on public disks only (FileSafety::blockedExtension()).
        $html = UploadedFile::fake()->createWithContent('page.html', '<p>hi</p>');

        $this->post('/admin/entities/posts', ['title' => 'Docs', 'docs' => ['present' => 1, 'upload' => [$html]]])
            ->assertSessionHasErrors(['docs.upload.0']);

        expect(DB::table('media')->count())->toBe(0);
    });
});

describe('configuration errors', function () {
    it('refuses a gallery over a singleFile() collection on the first resolve', function () {
        $post = postWith();

        expect(fn () => serializedPost(MediaGallery::make('Cover', 'cover'), $post))
            ->toThrow(LogicException::class, 'singleFile()')
            ->and(fn () => serializedPost(MediaGallery::make('Cover', 'cover'), null, Field::CREATE))
            ->toThrow(LogicException::class, 'singleFile()')
            ->and(fn () => MediaGallery::make('Cover', 'cover')->rulesFor(Field::CREATE, new Post))
            ->toThrow(LogicException::class);

        // A single Media field over the same collection is fine.
        expect(serializedPost(Media::make('Cover', 'cover'), $post)['media'])->toBe([]);
    });

    it('refuses a preview conversion that is not registered for the collection', function () {
        $post = postWith();

        expect(fn () => serializedPost(Media::make('Cover', 'cover')->conversion('nope'), $post))
            ->toThrow(LogicException::class, '[nope]')
            // "wide" is registered, but for the docs collection only.
            ->and(fn () => serializedPost(MediaGallery::make('Gallery', 'gallery')->conversion('wide'), $post))
            ->toThrow(LogicException::class, '[wide]');

        expect(serializedPost(Media::make('Docs', 'docs')->conversion('wide'), $post)['component'])->toBe('maxcxam/media')
            ->and(fn () => serializedPost(Media::make('Loose', 'undeclared')->conversion('thumb'), $post))->toThrow(LogicException::class)
            ->and(serializedPost(Media::make('Loose', 'undeclared'), $post)['media'])->toBe([]);
    });

    it('inspects a copy of the model, leaving the record conversions untouched', function () {
        $post = postWith();

        serializedPost(Media::make('Cover', 'cover')->conversion('thumb'), $post);

        expect($post->mediaConversions)->toBe([]);
    });

    it('re-inspects after the collection or conversion changes', function () {
        $post = postWith();
        $field = MediaGallery::make('Gallery', 'gallery');

        $field->declaredCollection($post);

        expect(fn () => $field->collection('cover')->declaredCollection($post))->toThrow(LogicException::class);
    });
});

describe('onlyKeepLatest()', function () {
    it('caps the gallery limit', function () {
        $json = serializedPost(MediaGallery::make('Gallery', 'gallery')->limit(5), postWith(), Field::UPDATE);

        expect($json['maxFiles'])->toBe(2)
            ->and(serializedPost(MediaGallery::make('Gallery', 'gallery')->limit(1), postWith(), Field::UPDATE)['maxFiles'])->toBe(1)
            ->and(serializedPost(MediaGallery::make('Screened', 'screened')->limit(5), postWith(), Field::UPDATE)['maxFiles'])->toBe(5);
    });

    it('answers 422 before anything is written when the collection would overflow', function () {
        $this->post('/admin/entities/posts', ['title' => 'Many', 'gallery' => ['present' => 1, 'upload' => [photo('a.jpg'), photo('b.jpg'), photo('c.jpg')]]])
            ->assertSessionHasErrors(['gallery']);

        $post = postWith(['gallery' => [photo('a.jpg')]]);
        $a = $post->getFirstMedia('gallery')->id;

        $this->post("/admin/entities/posts/{$post->id}", [
            '_method' => 'PUT', 'title' => 'Post',
            'gallery' => ['present' => 1, 'known' => [$a], 'keep' => [$a], 'upload' => [photo('b.jpg'), photo('c.jpg')]],
        ])->assertSessionHasErrors(['gallery']);

        expect(DB::table('media')->count())->toBe(1)
            ->and(Storage::disk('media_private')->allFiles())->toHaveCount(2); // a.jpg and its thumb
    });

    it('fills up to the cap without medialibrary clearing anything', function () {
        $post = postWith(['gallery' => [photo('a.jpg')]]);
        $a = $post->getFirstMedia('gallery')->id;

        $this->post("/admin/entities/posts/{$post->id}", [
            '_method' => 'PUT', 'title' => 'Post',
            'gallery' => ['present' => 1, 'known' => [$a], 'keep' => [$a], 'upload' => [photo('b.jpg')]],
        ])->assertSessionHasNoErrors();

        expect($post->fresh()->getMedia('gallery'))->toHaveCount(2)
            ->and($post->fresh()->getFirstMedia('gallery')->id)->toBe($a);
    });
});

describe('acceptsMimeTypes()', function () {
    it('adds a mimetypes rule for every file', function () {
        $rules = Media::make('Docs', 'docs')->rulesFor(Field::CREATE, new Post)['docs.upload.*'];

        expect($rules)->toContain('mimetypes:application/pdf')
            ->and(MediaGallery::make('Gallery', 'gallery')->rulesFor(Field::CREATE, new Post)['gallery.upload.*'])->not->toContain('mimetypes:application/pdf');
    });

    it('answers 422 at the refused file', function () {
        $this->post('/admin/entities/posts', ['title' => 'Docs', 'docs' => ['present' => 1, 'upload' => [textFile()]]])
            ->assertSessionHasErrors(['docs.upload.0']);

        expect(DB::table('media')->count())->toBe(0);
    });
});

describe('acceptsFile()', function () {
    it('turns a refused file into a 422 at that upload, leaving no rows or files', function () {
        $this->post('/admin/entities/posts', [
            'title' => 'Screened',
            'screened' => ['present' => 1, 'upload' => [photo('ok.jpg'), textFile('nope.txt')]],
        ])->assertSessionHasErrors(['screened.upload.1'])->assertSessionDoesntHaveErrors(['screened.upload.0']);

        // Our own message: medialibrary's names the model class, the collection and the stored file name.
        expect(session('errors')->first('screened.upload.1'))->toBe(__('larsadmin-medialibrary::messages.not_accepted', ['attribute' => 'Screened']))
            ->and(session('errors')->first('screened.upload.1'))->not->toContain('Post')
            ->and(Post::count())->toBe(0)
            ->and(DB::table('media')->count())->toBe(0)
            ->and(Storage::disk('media_private')->allFiles())->toBe([]);
    });

    it('throws a validation exception outside the panel too, keyed by the upload index', function () {
        $post = Post::create(['title' => 'Post']);

        try {
            $this->fillField(MediaGallery::make('Screened', 'screened'), $post, ['screened' => ['present' => 1]], ['screened' => ['upload' => [textFile()]]]);
            $this->fail('No validation exception.');
        } catch (ValidationException $e) {
            expect(array_keys($e->errors()))->toBe(['screened.upload.0']);
        }

        expect(DB::table('media')->count())->toBe(0);
    });

    it('accepts what the collection accepts', function () {
        $post = Post::create(['title' => 'Post']);

        $this->fillField(MediaGallery::make('Screened', 'screened'), $post, ['screened' => ['present' => 1]], ['screened' => ['upload' => [photo('a.jpg')]]]);

        expect($post->fresh()->getMedia('screened'))->toHaveCount(1);
    });
});

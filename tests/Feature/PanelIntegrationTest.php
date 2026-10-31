<?php

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\Fields\MediaGallery;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Article;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\ArticlePolicy;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities\Articles;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities\RequiredCoverArticles;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities\SensitiveArticles;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities\ThrowingArticles;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;

/**
 * End to end through larsadmin's own entity routes: the panel validates with Media::rulesFor(), calls fill() and runs
 * the deferred closure inside its save transaction. The request is what EntityForm sends for a form with a
 * `multipart: true` field (POST, _method=PUT for updates).
 */
beforeEach(function () {
    registerArticles();
    $this->actingAs($this->admin);
});

it('creates a record with a cover and a gallery, generating thumbs', function () {
    $this->post('/admin/entities/articles', [
        'title' => 'Live one',
        'cover' => ['present' => 1, 'upload' => [photo('cover.jpg')]],
        'gallery' => ['present' => 1, 'upload' => [photo('g1.jpg'), photo('g2.png')]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $article = Article::firstWhere('title', 'Live one');
    $cover = $article->getFirstMedia('cover');

    expect($cover->disk)->toBe('media_public')
        ->and($cover->hasGeneratedConversion('thumb'))->toBeTrue()
        ->and(Storage::disk('media_public')->exists($cover->getPathRelativeToRoot('thumb')))->toBeTrue()
        ->and($article->getMedia('gallery')->pluck('custom_properties.original_name')->all())->toBe(['g1.jpg', 'g2.png'])
        ->and($article->getFirstMedia('gallery')->disk)->toBe('media_private');
});

it('updates through the multipart form: reorder, remove, add', function () {
    $article = articleWith(['gallery' => [photo('a.jpg'), photo('b.jpg')]]);
    [$a, $b] = mediaIds($article, 'gallery');

    $this->post("/admin/entities/articles/{$article->id}", [
        '_method' => 'PUT',
        'title' => 'Live renamed',
        'gallery' => ['present' => 1, 'known' => [$a, $b], 'keep' => [$b], 'upload' => [photo('c.jpg')]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $names = $article->fresh()->getMedia('gallery')->pluck('custom_properties.original_name')->all();

    expect($names)->toBe([null, 'c.jpg']) // b was added directly by medialibrary (no original_name), c by the panel
        ->and(mediaIds($article, 'gallery')[0])->toBe($b)
        ->and(DB::table('media')->where('id', $a)->exists())->toBeFalse();
});

it('answers 422 with per-file error keys', function () {
    $article = articleWith();

    $this->post("/admin/entities/articles/{$article->id}", [
        '_method' => 'PUT',
        'title' => 'Live',
        'gallery' => ['present' => 1, 'upload' => [photo('ok.jpg'), UploadedFile::fake()->create('bad.exe', 1)]],
    ])->assertSessionHasErrors(['gallery.upload.1'])->assertSessionDoesntHaveErrors(['gallery.upload.0']);

    expect(DB::table('media')->count())->toBe(0);
});

it('requires media on create when the field is required', function () {
    registerArticles(RequiredCoverArticles::class);

    $this->post('/admin/entities/required-cover-articles', ['title' => 'x'])
        ->assertSessionHasErrors(['cover', 'gallery']);
});

it('keeps the stored media when the field is left out (fail closed on the client)', function () {
    $article = articleWith(['gallery' => [photo('a.jpg')]]);

    $this->put("/admin/entities/articles/{$article->id}", ['title' => 'Live 2'])->assertSessionHasNoErrors();

    expect($article->fresh()->getMedia('gallery'))->toHaveCount(1);
});

it('does not store media of a field hidden by canSee', function () {
    Articles::$contractVisible = false;
    $article = articleWith();

    $this->post("/admin/entities/articles/{$article->id}", [
        '_method' => 'PUT',
        'title' => 'Live',
        'contract' => ['present' => 1, 'upload' => [UploadedFile::fake()->create('c.pdf', 3, 'application/pdf')]],
    ])->assertSessionHasNoErrors();

    expect($article->fresh()->getMedia('contract'))->toHaveCount(0);
});

it('serializes the fields on the detail page with management URLs', function () {
    $article = articleWith(['gallery' => [photo('a.jpg'), photo('b.jpg')]]);

    $this->get("/admin/entities/articles/{$article->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('record.fields.3.component', 'maxcxam/media-gallery')
        ->where('record.fields.3.reorderUrl', "/admin/ext/medialibrary/articles/{$article->id}/gallery/order")
        ->where('record.fields.3.media.0.destroyUrl', "/admin/ext/medialibrary/articles/{$article->id}/gallery/".mediaIds($article, 'gallery')[0])
        ->etc());
});

it('deletes the media rows and files with the record', function () {
    $article = articleWith(['cover' => [photo('c.jpg')], 'gallery' => [photo('a.jpg')]]);
    $paths = $article->media->map(fn ($media) => [$media->disk, $media->getPathRelativeToRoot()])->all();
    ArticlePolicy::$delete = true;

    $this->delete("/admin/entities/articles/{$article->id}")->assertRedirect();

    expect(DB::table('media')->count())->toBe(0);

    foreach ($paths as [$disk, $path]) {
        expect(Storage::disk($disk)->exists($path))->toBeFalse();
    }
});

it('rolls back cleanly: removed media stay, uploaded files and their conversions are deleted', function () {
    registerArticles(ThrowingArticles::class);
    $this->withoutExceptionHandling();
    $article = articleWith(['gallery' => [photo('a.jpg')]]);
    $a = mediaIds($article, 'gallery')[0];
    $before = Storage::disk('media_private')->allFiles();

    expect(fn () => $this->post("/admin/entities/throwing-articles/{$article->id}", [
        '_method' => 'PUT', 'title' => 'Live',
        'gallery' => ['present' => 1, 'known' => [$a], 'upload' => [photo('new.jpg'), photo('new2.jpg')]],
    ]))->toThrow(RuntimeException::class, 'boom');

    // The removed media's row comes back with the rollback and its files were never deleted (journal obsolete()).
    expect(mediaIds($article, 'gallery'))->toBe([$a])
        ->and(DB::table('media')->count())->toBe(1)
        // The uploads (originals and their thumbs) were registered with the journal's created() and discarded.
        ->and(Storage::disk('media_private')->allFiles())->toEqualCanonicalizing($before);
});

it('deletes the files of removed media only after the commit', function () {
    $article = articleWith(['gallery' => [photo('a.jpg', 100, 100), photo('b.jpg')]]);
    $removed = $article->getFirstMedia('gallery');
    $files = [$removed->getPathRelativeToRoot(), $removed->getPathRelativeToRoot('thumb')];

    expect(Storage::disk('media_private')->exists($files[1]))->toBeTrue();

    $this->post("/admin/entities/articles/{$article->id}", [
        '_method' => 'PUT', 'title' => 'Live',
        'gallery' => ['present' => 1, 'known' => [$removed->id], 'keep' => []],
    ])->assertSessionHasNoErrors();

    expect(DB::table('media')->where('id', $removed->id)->exists())->toBeFalse();

    foreach ($files as $path) {
        expect(Storage::disk('media_private')->exists($path))->toBeFalse();
    }
});

it('replaces the file of a singleFile() collection without deleting it before the commit', function () {
    $article = articleWith(['cover' => [photo('old.jpg')]]);
    $old = $article->getFirstMedia('cover');

    // Outside a panel save (no journal): the field removes the old media itself before the upload.
    $field = Media::make('Cover', 'cover')->disk('media_public');

    try {
        DB::transaction(function () use ($field, $article) {
            $request = Request::create('/', 'POST', ['cover' => ['present' => 1]], [], ['cover' => ['upload' => [photo('new.jpg')]]]);
            $field->fill($request, $article)($article);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($article->fresh()->getFirstMedia('cover')->id)->toBe($old->id)
        ->and(Storage::disk('media_public')->exists($old->getPathRelativeToRoot()))->toBeTrue();
});

it('shows the original as the preview until a queued conversion ran', function () {
    Queue::fake();
    Article::$queueThumbs = true;
    $article = articleWith(['gallery' => [photo('a.jpg')]]);
    $media = $article->getFirstMedia('gallery');

    $field = MediaGallery::make('Gallery', 'gallery')->conversion('thumb');

    expect($media->hasGeneratedConversion('thumb'))->toBeFalse()
        ->and($this->serializeField($field, $article, entity: Articles::class)['media'][0]['thumbnailUrl'])->not->toContain('conversion=thumb');

    Queue::assertPushed(PerformConversionsJob::class);
    Queue::pushed(PerformConversionsJob::class)->each(fn ($job) => app()->call([$job, 'handle']));

    expect($this->serializeField($field, $article->fresh(), entity: Articles::class)['media'][0]['thumbnailUrl'])->toContain('conversion=thumb');
});

describe('audit log (1.3)', function () {
    beforeEach(fn () => config(['larsadmin.audit.enabled' => true]));

    /** @return list<array{0: string, 1: array<string, mixed>}> [event, changes] */
    function auditEntries(): array
    {
        return DB::table('larsadmin_audit_entries')->orderBy('id')->get()
            ->map(fn ($row) => [$row->event, json_decode($row->changes ?? '[]', true) ?? []])->all();
    }

    /** @return list<string> media names of an audit value */
    function names(?array $value): array
    {
        return array_column($value ?? [], 'name');
    }

    it('records media changes of a form save (Field::auditValue())', function () {
        $article = articleWith(['gallery' => [photo('a.jpg')]]);
        $a = mediaIds($article, 'gallery')[0];

        $this->post("/admin/entities/articles/{$article->id}", [
            '_method' => 'PUT', 'title' => 'Live 2', 'gallery' => ['present' => 1, 'known' => [$a], 'keep' => [$a], 'upload' => [photo('b.jpg')]],
        ])->assertSessionHasNoErrors();

        // A media-only change is an entry of its own.
        $this->post("/admin/entities/articles/{$article->id}", [
            '_method' => 'PUT', 'title' => 'Live 2', 'gallery' => ['present' => 1, 'known' => [$a], 'keep' => []],
        ])->assertSessionHasNoErrors();

        [$first, $second] = auditEntries();

        expect($first[0])->toBe('updated')
            ->and(array_keys($first[1]))->toEqualCanonicalizing(['title', 'gallery'])
            ->and(names($first[1]['gallery']['old']))->toBe(['a.jpg'])
            ->and(names($first[1]['gallery']['new']))->toBe(['a.jpg', 'b.jpg'])
            ->and(array_keys($second[1]))->toBe(['gallery'])
            ->and(names($second[1]['gallery']['new']))->toBe(['b.jpg']);
    });

    it('records nothing when the media did not change', function () {
        $article = articleWith(['gallery' => [photo('a.jpg')]]);
        $a = mediaIds($article, 'gallery')[0];

        $this->post("/admin/entities/articles/{$article->id}", [
            '_method' => 'PUT', 'title' => 'Live article', 'gallery' => ['present' => 1, 'known' => [$a], 'keep' => [$a]],
        ])->assertSessionHasNoErrors();

        expect(auditEntries())->toBe([]);
    });

    it('records the created media', function () {
        $this->post('/admin/entities/articles', ['title' => 'Live new', 'cover' => ['present' => 1, 'upload' => [photo('c.jpg')]]])
            ->assertSessionHasNoErrors();

        [[$event, $changes]] = auditEntries();

        expect($event)->toBe('created')->and(names($changes['cover']['new']))->toBe(['c.jpg']);
    });

    it('records reorder and delete made through the extension routes (Larsadmin::audited())', function () {
        $article = articleWith(['gallery' => [photo('a.jpg'), photo('b.jpg')]]);
        [$a, $b] = mediaIds($article, 'gallery');

        $this->postJson("/admin/ext/medialibrary/articles/{$article->id}/gallery/order", ['ids' => [$b, $a]])->assertOk();
        $this->deleteJson("/admin/ext/medialibrary/articles/{$article->id}/gallery/{$a}")->assertOk();
        // The same order again changes nothing: no entry.
        $this->postJson("/admin/ext/medialibrary/articles/{$article->id}/gallery/order", ['ids' => [$b]])->assertOk();

        $entries = auditEntries();

        expect($entries)->toHaveCount(2)
            ->and(array_column($entries, 0))->toBe(['updated', 'updated'])
            ->and(array_keys($entries[0][1]))->toBe(['gallery'])
            ->and(array_column($entries[0][1]['gallery']['new'], 'id'))->toBe([$b, $a])
            ->and(array_column($entries[1][1]['gallery']['new'], 'id'))->toBe([$b])
            ->and(DB::table('larsadmin_audit_entries')->value('actor_id'))->toBe((string) $this->admin->id);
    });

    it('masks the media of a sensitive field', function () {
        registerArticles(SensitiveArticles::class);
        $article = articleWith();

        $this->post("/admin/entities/sensitive-articles/{$article->id}", [
            '_method' => 'PUT', 'title' => 'Live article', 'contract' => ['present' => 1, 'upload' => [UploadedFile::fake()->create('secret.pdf', 3, 'application/pdf')]],
        ])->assertSessionHasNoErrors();

        $raw = DB::table('larsadmin_audit_entries')->value('changes');

        expect($raw)->not->toContain('secret.pdf')
            ->and(array_keys(auditEntries()[0][1]))->toBe(['contract']);
    });

    it('records the media with the delete of the record', function () {
        $article = articleWith(['gallery' => [photo('a.jpg')]]);

        $this->delete("/admin/entities/articles/{$article->id}")->assertRedirect();

        [[$event, $changes]] = auditEntries();

        expect($event)->toBe('deleted')
            ->and(array_keys($changes))->toEqualCanonicalizing(['id', 'title', 'gallery'])
            ->and(names($changes['gallery']['old']))->toBe(['a.jpg']);
    });
});

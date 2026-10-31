<?php

use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\Fields\MediaGallery;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities\Posts;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities\SoftPosts;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities\ThrowingPosts;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Post;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\SoftPost;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;

/**
 * File lifecycle (ADR-044, design §4): the known files of a media item are the original, its generated conversions
 * and its responsive images; queued conversions run after the commit; record deletion is medialibrary's.
 */
beforeEach(function () {
    registerArticles(Posts::class, SoftPosts::class, ThrowingPosts::class);
    $this->actingAs($this->admin);
});

/** @return list<string> paths on the private disk */
function privateFiles(): array
{
    return Storage::disk('media_private')->allFiles();
}

describe('responsive images', function () {
    it('are part of the files of a media item', function () {
        $post = postWith(['responsive' => [photo('wide.jpg', 400, 300)]]);
        $media = $post->getFirstMedia('responsive');
        $responsive = $media->responsive_images;

        expect($responsive)->not->toBe([]);

        $files = array_column(MediaGallery::make('Responsive', 'responsive')->filesOf($media), 1);
        $names = collect($responsive)->flatMap(fn ($images) => $images['urls'] ?? [])->all();

        expect($names)->not->toBe([]);

        foreach ($names as $name) {
            $match = collect($files)->first(fn ($path) => str_ends_with($path, '/'.$name));

            expect($match)->not->toBeNull()
                ->and(Storage::disk('media_private')->exists($match))->toBeTrue();
        }

        // Every file the media has on the disk is known (original, thumb, responsive images).
        expect($files)->toEqualCanonicalizing(privateFiles());
    });

    it('are deleted with the media after the commit of a form save', function () {
        $post = postWith(['responsive' => [photo('wide.jpg', 400, 300)]]);
        $id = $post->getFirstMedia('responsive')->id;

        expect(privateFiles())->not->toBe([]);

        $this->post("/admin/entities/posts/{$post->id}", [
            '_method' => 'PUT', 'title' => 'Post', 'responsive' => ['present' => 1, 'known' => [$id], 'keep' => []],
        ])->assertSessionHasNoErrors();

        expect(DB::table('media')->count())->toBe(0)
            ->and(privateFiles())->toBe([]);
    });

    it('are deleted with the media through the detail-page route', function () {
        $post = postWith(['responsive' => [photo('a.jpg', 400, 300), photo('b.jpg')]]);
        $id = $post->getFirstMedia('responsive')->id;
        $files = MediaGallery::make('Responsive', 'responsive')->filesOf($post->getFirstMedia('responsive'));

        $this->deleteJson("/admin/ext/medialibrary/posts/{$post->id}/responsive/{$id}")->assertOk();

        foreach ($files as [$disk, $path]) {
            expect(Storage::disk($disk)->exists($path))->toBeFalse();
        }

        expect($post->fresh()->getMedia('responsive'))->toHaveCount(1);
    });

    it('written by a save that rolls back are discarded by the journal', function () {
        $this->withoutExceptionHandling();
        $post = postWith();

        expect(fn () => $this->post("/admin/entities/throwing-posts/{$post->id}", [
            '_method' => 'PUT', 'title' => 'Post', 'responsive' => ['present' => 1, 'upload' => [photo('wide.jpg', 400, 300)]],
        ]))->toThrow(RuntimeException::class, 'boom');

        expect(DB::table('media')->count())->toBe(0)
            ->and(privateFiles())->toBe([]);
    });
});

describe('queued conversions', function () {
    it('are pushed after the commit and generate the preview then', function () {
        Post::$queueThumbs = true;

        $this->post('/admin/entities/posts', ['title' => 'Queued', 'gallery' => ['present' => 1, 'upload' => [photo('a.jpg')]]])
            ->assertSessionHasNoErrors();

        // The sync queue ran the job once the save committed (queue_conversions_after_database_commit).
        $media = Post::firstWhere('title', 'Queued')->getFirstMedia('gallery');

        expect($media->hasGeneratedConversion('thumb'))->toBeTrue()
            ->and(Storage::disk('media_private')->exists($media->getPathRelativeToRoot('thumb')))->toBeTrue();
    });

    it('flag the preview as pending until the conversion ran, without extra queries', function () {
        Queue::fake();
        Post::$queueThumbs = true;
        $post = postWith(['gallery' => [photo('a.jpg')], 'docs' => [pdfFile()]]);
        $abilities = ['view' => true, 'update' => true, 'delete' => true];
        $serialize = fn (Media $field, Post $record) => $this->serializeField($field, $record, Field::DETAIL, Posts::class, $abilities);

        $field = MediaGallery::make('Gallery', 'gallery')->conversion('thumb');
        $serialize($field, $post); // warms the declaration check

        DB::enableQueryLog();
        $pending = $serialize($field, $post)['media'][0];
        $withConversion = count(DB::getQueryLog());
        DB::flushQueryLog();
        $plain = $serialize(MediaGallery::make('Gallery', 'gallery'), $post)['media'][0];
        $withoutConversion = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($pending['previewPending'])->toBeTrue()
            ->and($pending['thumbnailUrl'])->not->toContain('conversion=thumb')
            ->and($plain['previewPending'])->toBeFalse()
            ->and($withConversion)->toBe($withoutConversion)
            // No preview conversion: nothing pending.
            ->and($serialize(Media::make('Docs', 'docs'), $post)['media'][0]['previewPending'])->toBeFalse();

        Queue::pushed(PerformConversionsJob::class)->each(fn ($job) => app()->call([$job, 'handle']));

        expect($serialize($field, $post->fresh())['media'][0]['previewPending'])->toBeFalse();
    });

    it('finish silently for media removed before the job ran', function () {
        Queue::fake();
        Post::$queueThumbs = true;
        $post = postWith(['gallery' => [photo('a.jpg')]]);
        $id = $post->getFirstMedia('gallery')->id;
        $jobs = Queue::pushed(PerformConversionsJob::class);

        expect($jobs)->toHaveCount(1);

        $this->post("/admin/entities/posts/{$post->id}", [
            '_method' => 'PUT', 'title' => 'Post', 'gallery' => ['present' => 1, 'known' => [$id], 'keep' => []],
        ])->assertSessionHasNoErrors();

        // A real queue worker: the job's media is gone, deleteWhenMissingModels drops the job.
        $queue = new SyncQueue;
        $queue->setContainer(app());
        $queue->push($jobs->first());

        expect(DB::table('media')->count())->toBe(0)
            ->and(privateFiles())->toBe([]);
    });
});

describe('record deletion', function () {
    it('deletes media rows and every file with the record', function () {
        $post = postWith(['responsive' => [photo('a.jpg', 400, 300)], 'docs' => [pdfFile()]]);

        $this->delete("/admin/entities/posts/{$post->id}")->assertRedirect();

        expect(DB::table('media')->count())->toBe(0)
            ->and(privateFiles())->toBe([])
            ->and(Storage::disk('media_public')->allFiles())->toBe([]);
    });

    it('keeps the media of a soft-deleted record until the force delete', function () {
        $post = postWith(['gallery' => [photo('a.jpg')]], class: SoftPost::class);
        $files = privateFiles();

        $this->delete("/admin/entities/soft-posts/{$post->id}")->assertRedirect();

        expect(SoftPost::withTrashed()->find($post->id)->trashed())->toBeTrue()
            ->and(DB::table('media')->count())->toBe(1)
            ->and(privateFiles())->toEqualCanonicalizing($files)
            ->and($files)->not->toBe([]);

        SoftPost::withTrashed()->find($post->id)->forceDelete();

        expect(DB::table('media')->count())->toBe(0)
            ->and(privateFiles())->toBe([]);
    });
});

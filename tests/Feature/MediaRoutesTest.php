<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\ArticlePolicy;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities\Articles;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities\LiveArticles;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities\RequiredCoverArticles;

beforeEach(function () {
    registerArticles(Articles::class, LiveArticles::class, RequiredCoverArticles::class);
    $this->actingAs($this->admin);
});

function signedShow(string $entity, int $id, string $field, int $media, array $query = [], int $minutes = 30): string
{
    return URL::temporarySignedRoute('larsadmin.ext.medialibrary.show', now()->addMinutes($minutes), [
        'entity' => $entity, 'id' => $id, 'field' => $field, 'media' => $media, ...$query,
    ], absolute: false);
}

it('registers the routes under the panel with the larsadmin.ext. prefix and the panel middleware', function () {
    $route = Route::getRoutes()->getByName('larsadmin.ext.medialibrary.reorder');

    expect($route->uri())->toBe('admin/ext/medialibrary/{entity}/{id}/{field}/order')
        ->and($route->gatherMiddleware())->toContain('web');
});

it('redirects guests and refuses users without the panel gate', function () {
    $article = articleWith(['gallery' => [photo('a.jpg')]]);
    $url = signedShow('articles', $article->id, 'gallery', mediaIds($article, 'gallery')[0]);

    auth()->logout();
    $this->get($url)->assertRedirect('/admin/login');

    Larsadmin::auth(fn () => false);
    $this->actingAs($this->admin)->get($url)->assertForbidden();
});

describe('show', function () {
    it('streams a private image inline with safe headers', function () {
        $article = articleWith(['gallery' => [photo('a.jpg')]]);

        $response = $this->get(signedShow('articles', $article->id, 'gallery', mediaIds($article, 'gallery')[0]))->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
            ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox')
            ->and($response->headers->get('Cache-Control'))->toContain('private');
    });

    it('serves downloads and non-images as octet-stream attachments', function () {
        $article = articleWith(['contract' => [UploadedFile::fake()->create('c.pdf', 3, 'application/pdf')]]);
        $id = mediaIds($article, 'contract')[0];

        $response = $this->get(signedShow('articles', $article->id, 'contract', $id))->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('application/octet-stream')
            ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('serves the preview conversion only', function () {
        $article = articleWith(['gallery' => [photo('a.jpg', 100, 100)]]);
        $id = mediaIds($article, 'gallery')[0];

        $this->get(signedShow('articles', $article->id, 'gallery', $id, ['conversion' => 'thumb']))->assertOk();
        $this->get(signedShow('articles', $article->id, 'gallery', $id, ['conversion' => 'other']))->assertNotFound();
    });

    it('refuses unsigned, tampered and expired URLs', function () {
        $article = articleWith(['gallery' => [photo('a.jpg')]]);
        $id = mediaIds($article, 'gallery')[0];

        $this->get("/admin/ext/medialibrary/articles/{$article->id}/gallery/{$id}")->assertForbidden();
        $this->get(signedShow('articles', $article->id, 'gallery', $id).'&download=1')->assertForbidden();

        $url = signedShow('articles', $article->id, 'gallery', $id, minutes: 1);
        $this->travel(2)->minutes();
        $this->get($url)->assertForbidden();
    });

    it('re-authorizes the record at use time (IDOR matrix)', function () {
        $article = articleWith(['gallery' => [photo('a.jpg')], 'contract' => [UploadedFile::fake()->create('c.pdf', 3)]]);
        $draft = articleWith(['gallery' => [photo('d.jpg')]], 'Draft');
        $other = articleWith(['gallery' => [photo('o.jpg')]], 'Live other');
        $id = mediaIds($article, 'gallery')[0];

        // Media of another record, or of another collection of the same record.
        $this->get(signedShow('articles', $article->id, 'gallery', mediaIds($other, 'gallery')[0]))->assertNotFound();
        $this->get(signedShow('articles', $article->id, 'gallery', mediaIds($article, 'contract')[0]))->assertNotFound();
        // Outside the detail scope; unknown entity and field.
        $this->get(signedShow('live-articles', $draft->id, 'gallery', mediaIds($draft, 'gallery')[0]))->assertNotFound();
        $this->get(signedShow('nope', $article->id, 'gallery', $id))->assertNotFound();
        $this->get(signedShow('articles', $article->id, 'title', $id))->assertNotFound();

        // A field hidden by canSee.
        Articles::$contractVisible = false;
        $this->get(signedShow('articles', $article->id, 'contract', mediaIds($article, 'contract')[0]))->assertNotFound();

        // Policies, checked when the URL is used, not only when it was issued.
        ArticlePolicy::$view = false;
        $this->get(signedShow('articles', $article->id, 'gallery', $id))->assertForbidden();
        ArticlePolicy::$view = true;
        ArticlePolicy::$viewAny = false;
        $this->get(signedShow('articles', $article->id, 'gallery', $id))->assertForbidden();
    });

    it('answers 404 when the file is gone from the disk', function () {
        $article = articleWith(['gallery' => [photo('a.jpg')]]);
        $media = $article->getFirstMedia('gallery');
        Storage::disk('media_private')->delete($media->getPathRelativeToRoot());

        $this->get(signedShow('articles', $article->id, 'gallery', $media->id))->assertNotFound();
    });
});

describe('reorder', function () {
    it('saves a permutation of the current media', function () {
        $article = articleWith(['gallery' => [photo('a.jpg'), photo('b.jpg'), photo('c.jpg')]]);
        [$a, $b, $c] = mediaIds($article, 'gallery');

        $this->postJson("/admin/ext/medialibrary/articles/{$article->id}/gallery/order", ['ids' => [$c, $a, $b]])
            ->assertOk()->assertExactJson(['order' => [$c, $a, $b]]);

        expect(mediaIds($article, 'gallery'))->toBe([$c, $a, $b]);
    });

    it('rejects foreign, missing and duplicate ids', function () {
        $article = articleWith(['gallery' => [photo('a.jpg'), photo('b.jpg')]]);
        $other = articleWith(['gallery' => [photo('o.jpg')]], 'Live other');
        [$a, $b] = mediaIds($article, 'gallery');
        $url = "/admin/ext/medialibrary/articles/{$article->id}/gallery/order";

        $this->postJson($url, ['ids' => [$a, mediaIds($other, 'gallery')[0]]])->assertUnprocessable();
        $this->postJson($url, ['ids' => [$a]])->assertUnprocessable();
        $this->postJson($url, ['ids' => [$a, $a]])->assertUnprocessable();
        $this->postJson($url, ['ids' => 'x'])->assertUnprocessable();

        expect(mediaIds($article, 'gallery'))->toBe([$a, $b]);
    });

    it('requires the update ability and an editable multiple field', function () {
        $article = articleWith(['gallery' => [photo('a.jpg')], 'cover' => [photo('c.jpg')]]);

        $this->postJson("/admin/ext/medialibrary/articles/{$article->id}/cover/order", ['ids' => mediaIds($article, 'cover')])->assertNotFound();

        ArticlePolicy::$update = false;
        $this->postJson("/admin/ext/medialibrary/articles/{$article->id}/gallery/order", ['ids' => mediaIds($article, 'gallery')])->assertForbidden();
    });
});

describe('destroy', function () {
    it('deletes one media row and its files', function () {
        $article = articleWith(['gallery' => [photo('a.jpg'), photo('b.jpg')]]);
        $media = $article->getFirstMedia('gallery');

        $this->deleteJson("/admin/ext/medialibrary/articles/{$article->id}/gallery/{$media->id}")
            ->assertOk()->assertExactJson(['deleted' => $media->id]);

        expect(DB::table('media')->where('id', $media->id)->exists())->toBeFalse()
            ->and(Storage::disk('media_private')->exists($media->getPathRelativeToRoot()))->toBeFalse()
            ->and($article->fresh()->getMedia('gallery'))->toHaveCount(1);
    });

    it('refuses without update, for another record and for the last media of a required field', function () {
        $article = articleWith(['gallery' => [photo('a.jpg')], 'cover' => [photo('c.jpg')]]);
        $other = articleWith(['gallery' => [photo('o.jpg')]], 'Live other');

        $this->deleteJson("/admin/ext/medialibrary/articles/{$article->id}/gallery/".mediaIds($other, 'gallery')[0])->assertNotFound();
        $this->deleteJson("/admin/ext/medialibrary/required-cover-articles/{$article->id}/cover/".mediaIds($article, 'cover')[0])->assertUnprocessable();

        ArticlePolicy::$update = false;
        $this->deleteJson("/admin/ext/medialibrary/articles/{$article->id}/gallery/".mediaIds($article, 'gallery')[0])->assertForbidden();

        expect(DB::table('media')->count())->toBe(3);
    });
});

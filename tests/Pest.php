<?php

use Illuminate\Http\UploadedFile;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Article;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities\Articles;
use Maxcxam\LarsadminMedialibrary\Tests\TestCase;

pest()->extend(TestCase::class)->use(InteractsWithLarsadmin::class)->in(__DIR__);

function photo(string $name = 'photo.jpg', int $width = 64, int $height = 48): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, $height);
}

/** An article with media added directly through medialibrary (not through the panel). */
function articleWith(array $media = [], string $title = 'Live article'): Article
{
    $article = Article::create(['title' => $title]);

    foreach ($media as $collection => $files) {
        foreach ((array) $files as $file) {
            $article->addMedia($file)->toMediaCollection($collection, $collection === 'cover' ? 'media_public' : 'media_private');
        }
    }

    return $article->fresh();
}

function registerArticles(string ...$entities): void
{
    Larsadmin::entities($entities === [] ? [Articles::class] : $entities);
}

/** @return array<int, int> media ids of the collection in order */
function mediaIds(Article $article, string $collection): array
{
    return $article->fresh()->getMedia($collection)->map(fn ($media) => (int) $media->getKey())->all();
}

/**
 * The record abilities the panel computes for the current user (entity policy), for serializeField(..., $abilities).
 *
 * @return array{view?: bool, update?: bool, delete?: bool}
 */
function recordAbilities(?Article $record): array
{
    if ($record === null) {
        return [];
    }

    $entity = Articles::make($record);

    return [
        'view' => $entity->authorizedToView(request()),
        'update' => $entity->authorizedToUpdate(request()),
        'delete' => $entity->authorizedToDelete(request()),
    ];
}

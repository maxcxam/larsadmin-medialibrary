---
name: larsadmin-medialibrary
description: "Larsadmin media library fields: Media (one file or image) and MediaGallery (ordered gallery) for spatie/laravel-medialibrary collections, preview conversions, public and private disks, signed and temporary URLs, opt-in image metadata (EXIF/GPS) stripping, queued conversions and host tests. Use when adding uploads, images, galleries or attachments stored with spatie/laravel-medialibrary to a Larsadmin entity, in a Laravel app that uses maxcxam/larsadmin-medialibrary."
license: MIT
metadata:
  package: maxcxam/larsadmin-medialibrary
  version: "1.0"
---

# Larsadmin media library

## When to use

- The model uses `spatie/laravel-medialibrary` (`HasMedia`, `InteractsWithMedia`) and the admin needs to
  upload, show, reorder or remove its media.
- One cover image or attachment per record: `Media`. Several ordered images or files: `MediaGallery`.
- Photos from phones or cameras on a public disk: `->stripMetadata()` (EXIF, GPS, XMP, IPTC).
- Files stored in plain model columns (no media library): use the core `File` / `Image` fields instead
  (skill `larsadmin-files`).

Install: `composer require maxcxam/larsadmin-medialibrary`, publish and run the media library migration
(`php artisan vendor:publish --tag=medialibrary-migrations`, `php artisan migrate`). The add-on registers itself
(package discovery) and ships prebuilt assets: no npm build in the host. It has no config file of its own; it
reads `larsadmin.files.*` and `media-library.*`.

## Quick start

```php
// app/Models/Post.php: the collections and conversions are the source of truth.
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Post extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover')->singleFile();
        $this->addMediaCollection('gallery')->onlyKeepLatest(12);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->fit(Fit::Crop, 300, 300)
            ->performOnCollections('cover', 'gallery')
            ->nonQueued();
    }
}
```

```php
// app/Larsadmin/Post.php
namespace App\Larsadmin;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\Fields\MediaGallery;

class Post extends Entity
{
    public static string $model = \App\Models\Post::class;

    public function fields(Request $request): array
    {
        return [
            Media::make('Cover', 'cover')->images()->conversion('thumb')->maxSize(4096),
            // Phone photos on a public disk: strip EXIF/GPS (images() allows jpg, jpeg, png, gif, webp).
            MediaGallery::make('Gallery', 'gallery')->images()->conversion('thumb')->limit(12)->stripMetadata(),
        ];
    }
}
```

`Media::make(name, attribute = null, collection = null)`: the collection defaults to the attribute. Give the
model a Policy (`view`, `update`): the fields take their rights from it. A single `Media` field needs a collection
declared `singleFile()` (or `onlyKeepLatest(1)`); over a collection that may hold more it throws `LogicException`.
Use `MediaGallery` for those. `stripMetadata(bool $strip = true)` returns the field.

## Recipes

### Cover image

`Media::make('Cover', 'cover')->images()->conversion('thumb')->rules('required')`. A new upload replaces the
current media. `required` also blocks deleting the last media on the detail page. The collection must be
`singleFile()` or `onlyKeepLatest(1)` (`LogicException` otherwise).

### Gallery

`MediaGallery::make('Gallery', 'gallery')->images()->conversion('thumb')->limit(12)`. Order follows the
collection's `order_column`; users who may `update` reorder and remove on the detail page. `limit()` defaults to
`larsadmin.files.max_files` and is capped by the collection's `onlyKeepLatest(n)`; more files is a 422 before
anything is written. The collection must not be `singleFile()`.

### Public or private: what decides

Links per media, in this order: the field's `->public()` / `->private()`, else the media's disk config: the disk is
public only when `config('filesystems.disks.<disk>.visibility')` is exactly `'public'`. A disk without a
`visibility` key (Laravel's default `local`, most S3 disks) is private. Uploads go to the field's `disk()`, else the
collection's `useDisk()`, else `media-library.disk_name` (`public` by default, which has `visibility => 'public'`).
`->private()` changes the links only, not where the file lives: on a public upload disk it throws `LogicException`.
Sensitive files need a disk that is not public.

- Public: the permanent storage URL (`$media->getUrl()`), readable by anyone who has it.
- Private on a disk with temporary URLs (S3): provider temporary URLs (`larsadmin.files.url_ttl`); non-image files
  are forced to download.
- Private on any other disk (`local`): a short-lived relative signed panel route. Every download re-checks the
  record (`viewAny`, `detailQuery()`, the Policy's `view`), that the field is visible on the record page or the
  list (or on the update form for a user with `update`), and that the media belongs to that field and record.

### Private PDFs (contracts, invoices, IDs)

```php
// Model: the disk and the accepted MIME types live on the collection ('local' has no visibility key: private).
$this->addMediaCollection('contracts')->useDisk('local')->singleFile()->acceptsMimeTypes(['application/pdf']);

// Entity:
Media::make('Contract', 'contract', 'contracts')->types(['pdf'])->maxSize(10240)->removable(false);
```

### Only users who may update the record see and download the file

`canSee()` receives only the request, so it cannot depend on the record. Use the record-aware visibility closures
of the core `Field` (`fn (Request $request, ?Model $record)`), and hide the field on the list, where the closure gets
no record:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Maxcxam\LarsadminMedialibrary\Fields\Media;

Media::make('Contract', 'contract', 'contracts')->types(['pdf'])
    ->hideFromIndex()
    ->hideFromDetail(fn (Request $request, ?Model $record) => ! $request->user()?->can('update', $record));
```

On the update form the field already needs `update`. Without `update` the field is hidden on the record page, so
no URL is issued, and an old signed link answers 404 because the download route re-checks `view` plus the field's
visibility on every request. Keep the disk private: a public URL bypasses all of this.

### Photos from phones on a public disk: strip metadata

```php
Media::make('Photo', 'photo')->images()->conversion('thumb')->stripMetadata();
MediaGallery::make('Photos', 'photos')->images()->limit(20)->stripMetadata();
```

`stripMetadata(bool $strip = true)` is off by default. With it, every image uploaded through this field loses EXIF
(GPS, device, time), XMP, IPTC, comments and other metadata before it is stored. JPEG, PNG/APNG, WebP and GIF are
rewritten without re-encoding: pixels, colour profile (ICC) and animation stay identical. The EXIF orientation is
kept as a minimal tag, so photos are not shown sideways. Any other image type (AVIF, HEIC/HEIF, TIFF, BMP, SVG,
ICO) is rejected with a 422, and so is a damaged file: it fails closed, an unstripped image is never stored.
`images()` allows jpg, jpeg, png, gif and webp; do not add `avif` to `types()` on a stripping field.
Non-image files (PDF, Office) are stored unchanged. Media added by your own code (`$post->addMedia()`) are not
stripped.

### Queued conversions

```php
$this->addMediaConversion('thumb')->fit(Fit::Crop, 300, 300)->performOnCollections('gallery')->queued();
```

Queued conversions run after the save commits (`media-library.queue_conversions_after_database_commit`, `true`
by default; keep it). Until the job runs, the field shows the original as the preview. Run a queue worker
(`php artisan queue:work`); with `QUEUE_CONNECTION=sync` they run in the request. `->conversion()` must name a
conversion registered for the field's collection.

### Validation errors

Per-file problems (size, type, more than `larsadmin.files.max_pixels` pixels (default 40 MP), `acceptsMimeTypes()`,
blocked extension, `acceptsFile()`, `stripMetadata()`) are
on `{attribute}.upload.N`, e.g. `photos.upload.0`. Field-level problems are on the attribute itself: `required`
and more media than `limit()` / `onlyKeepLatest(n)` give an error on `photos` (too many files in one request also
adds `photos.upload`). Assert them with `assertSessionHasErrors('photos.upload.0')` or `assertSessionHasErrors('photos')`.

### Host tests

**Detect the runner first** (Pest: `vendor/bin/pest` and `tests/Pest.php` exist; otherwise PHPUnit classes) and
write in the app's style; never install Pest into a PHPUnit app. Send uploads in the form payload of the field,
the way the panel's form does and the way to test it:

- `{attribute}[present] = 1` (the field was on the form) and `{attribute}[upload][] = UploadedFile`;
- to keep or reorder existing media: `{attribute}[known][]` (ids the form loaded) and `{attribute}[keep][]` (ids to
  keep, in order); a known id missing from `keep` is removed.

```php
use App\Models\Post;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('creates a post with a cover', function () {
    Storage::fake('public');

    $this->actingAs(admin())
        ->post('/admin/entities/posts', [
            'title' => 'Hello',
            'cover' => ['present' => 1, 'upload' => [UploadedFile::fake()->image('cover.jpg', 800, 600)]],
        ])
        ->assertSessionHasNoErrors();

    expect(Post::sole()->getFirstMedia('cover'))->not->toBeNull();
});
```

Updates are a multipart `POST` with `_method=PUT`. The PHPUnit versions, `fillField()` / `serializeField()` tests
and a `stripMetadata()` (GPS) test are in [references/testing.md](references/testing.md).

## API cheat sheet

| API | Notes |
| --- | --- |
| `Maxcxam\LarsadminMedialibrary\Fields\Media` | one file or image; component `maxcxam/media` |
| `Media::make(name, attribute = null, collection = null)` | collection defaults to the attribute |
| `collection(string)` | the model's collection name |
| `conversion(?string)` | preview conversion; must be registered for the collection (`LogicException` otherwise) |
| `disk(?string)` | upload disk; default: the collection's `useDisk()`, else `media-library.disk_name` |
| `types(array)` | extension allow-list, e.g. `['pdf', 'docx']` (`mimes` + `extensions` rules) |
| `images()` | raster only, never SVG; default jpg, jpeg, png, gif, webp; avif / bmp only if `types()` names them |
| `maxSize(int)` | KB per file; effective `min(maxSize, larsadmin.files.max_size, media-library.max_file_size)` |
| `public(bool = true)`, `private()` | links only: storage URLs, or signed / temporary; `private()` on a public disk throws |
| `removable(bool = true)` | whether users may remove media (form and detail page) |
| `stripMetadata(bool = true)` | strip EXIF/GPS/XMP/IPTC from JPEG, PNG, WebP, GIF; reject other images; off by default |
| `Maxcxam\LarsadminMedialibrary\Fields\MediaGallery` | extends `Media`; ordered, reorderable; component `maxcxam/media-gallery` |
| `MediaGallery::limit(int)` | max media; default `larsadmin.files.max_files`; capped by `onlyKeepLatest(n)` |
| inherited `Field` methods | `rules()`, `help()`, `readonly()`, `sensitive()`, `canSee()`, `hideFromIndex()`, ... |

Collection declarations the fields enforce: `useDisk()` (upload disk), `singleFile()` (not under a gallery),
`onlyKeepLatest(n)` (caps `limit()`), `acceptsMimeTypes()` (a `mimetypes` rule, 422), `acceptsFile()` (a
refused file is a 422 on that upload), `registerMediaConversions()` (checked against `conversion()`).
Everything else in the package (controller, rules, stripper, provider, routes, serialized keys, other public
methods) is internal. The form payload (`present` / `upload` / `known` / `keep`) is what host tests send.

## Security rules

- MUST give the model a Policy with `view` and `update`. URLs are issued only when the field is visible and
  `Field::authorizedTo('view')` is true; reorder and delete need `update` and an editable field. Scope rows in
  `indexQuery()` / `detailQuery()` (skill `larsadmin-authorization-security`).
- MUST keep sensitive files (contracts, IDs, invoices, medical scans) on a disk whose `visibility` is not
  `'public'` (e.g. `useDisk('local')` on the collection). `->private()` alone is not enough: it changes links, not
  the storage, and throws on a public disk. Media on a public disk are readable by anyone who has the URL. Per-record rules belong in record-aware visibility closures, not `canSee()`.
- MUST use `->stripMetadata()` (or a private disk) for photos of people or places on a public disk: phone
  photos carry GPS coordinates, device and time. Without it the original is stored byte for byte.
- MUST set `types()` or `images()` on every field; `larsadmin.files.blocked_extensions` is only a backstop.
  Declare `acceptsMimeTypes()` on the collection where the type matters.
- NEVER let SVG or HTML be shown inline: `images()` accepts raster images only, and the panel serves inline only
  raster images (stored MIME and extension both raster), everything else as an `application/octet-stream`
  download with `nosniff` and a sandbox CSP, through the core's `FileSafety`.
- NEVER build media URLs, paths or file names from the request in your own code. Stored names are random with an
  extension guessed from content; the client name is kept sanitized as `original_name`.
- Treat the model's collection as the source of truth: disk, `onlyKeepLatest`, `acceptsMimeTypes` and
  `singleFile` belong in `registerMediaCollections()`, not duplicated in the field.

## Pitfalls

- `LogicException` on the first page load: a `Media` over a collection that is not `singleFile()` /
  `onlyKeepLatest(1)`, a `MediaGallery` over a `singleFile()` collection, `private()` on a public disk, a `conversion()`
  not registered for the collection, or a model that does not implement `HasMedia`.
- `->sortable()` on a media field throws `LogicException` (no column); `->disk('x')` with an unknown disk throws
  `InvalidArgumentException`; `maxSize(0)` and `limit(0)` too.
- With `stripMetadata()`, AVIF and HEIC uploads are rejected (422), even when `types()` names `avif`.
- `maxSize()` cannot raise the limit above `larsadmin.files.max_size` or `media-library.max_file_size`. Images above
  `larsadmin.files.max_pixels` (default 40 MP) are a 422.
- A failed upload or conversion during the save is cleaned up: files already written are deleted on rollback.
- `canSee(fn (Request $request) => ...)` gets no record: a per-record check there cannot work. Use
  `hideFromDetail(fn (Request $request, ?Model $record) => ...)` plus `hideFromIndex()`.
- The core's MCP server knows nothing specific about these fields: `describe-entity` lists media fields without
  their media options (`rulesComputed: false`), and `search-docs` / `read-docs` do not index the add-on guide.
  Read `vendor/maxcxam/larsadmin-medialibrary/docs/guide/en/medialibrary.md` and the model's
  `registerMediaCollections()` instead.
- Deleting a record: the media library's own observer deletes media rows and files immediately, outside the
  panel's save transaction, so a later rollback cannot bring the files back. Soft deletes keep media.
- Removed media leave empty directories on the disk (directories are never deleted).
- Previews missing on a public disk: run `php artisan storage:link` and set `APP_URL`. Previews stuck on the
  original: the queue worker is not running.
- Uploads failing before Laravel runs: raise `upload_max_filesize` / `post_max_size` (no chunked upload in 1.0).
- Alt text and captions (custom properties), `srcset`, media in action fields and UUID media keys are not in 1.0.

## Reference

- Guide: `vendor/maxcxam/larsadmin-medialibrary/docs/guide/en/medialibrary.md` (installation, collections and
  conversions, private disks and URLs, image metadata, security, queues, limitations, host tests). Not indexed by
  the MCP `search-docs`: read the file.
- Core file uploads (`File`, `Image`, `FileSafety`): `vendor/maxcxam/larsadmin/docs/guide/en/15-files.md`
  (skill `larsadmin-files`).
- Extensions and field hooks: `vendor/maxcxam/larsadmin/docs/guide/en/19-extensions.md` (skill
  `larsadmin-extending`).
- Without Laravel Boost, copy `vendor/maxcxam/larsadmin-medialibrary/resources/boost/skills/larsadmin-medialibrary`
  into `.claude/skills/`.

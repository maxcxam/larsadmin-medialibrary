# Media library: `Media` and `MediaGallery` fields
🇺🇦 [Українська](../medialibrary.md)

The `maxcxam/larsadmin-medialibrary` add-on adds two fields for
[spatie/laravel-medialibrary](https://spatie.be/docs/laravel-medialibrary) collections to the Larsadmin panel:

- `Maxcxam\LarsadminMedialibrary\Fields\Media`: one file or image of a collection (a cover, an avatar, a contract).
  A new upload replaces the current one.
- `Maxcxam\LarsadminMedialibrary\Fields\MediaGallery`: several media of a collection in the collection's order
  (`order_column`), reorderable in the form and on the record page.

The fields upload with the form, show previews from a conversion of your choice, serve private media through
signed or temporary links, re-check the user's rights on every download and keep the files in step with the
save transaction. Opt-in `stripMetadata()` removes EXIF/GPS from photos before they are stored.

The add-on is built on the public extension API of the core only. The core does not depend on the media
library: apps that do not install the add-on get nothing new. Files in plain model columns (no media library)
use the core `File` and `Image` fields (guide 15 of the core, `vendor/maxcxam/larsadmin/docs/guide/en/15-files.md`).

## Requirements

| What | Version |
| --- | --- |
| PHP | 8.3, 8.4, 8.5 |
| Laravel | 12, 13 |
| `maxcxam/larsadmin` | `^1.6` |
| `spatie/laravel-medialibrary` | `^11.23` (needs `ext-exif` and `ext-fileinfo`) |

Conversions are made by `spatie/image`, so GD or Imagick must be installed if you register conversions.
`stripMetadata()` needs no extension. The host needs no Node.js or npm: the add-on ships its built Vue files.

## Installation

```bash
composer require maxcxam/larsadmin-medialibrary
```

The add-on registers itself through package discovery: no `vendor:publish`, no config file, no npm build.

If the media library is new in your app, publish and run its migration (the `media` table):

```bash
php artisan vendor:publish --tag=medialibrary-migrations
php artisan migrate
```

The media library's own config is optional (`php artisan vendor:publish --tag=medialibrary-config`). Things to
check:

- **The disk.** Uploads go to `media-library.disk_name` (`MEDIA_DISK`, `public` by default) unless the collection
  or the field names another one (see [The model is the source of truth](#the-model-is-the-source-of-truth)). For
  sensitive files use a private disk (see [Disks, URLs and access](#disks-urls-and-access)).
- **The `public` disk** is served by the web server and needs the link and a correct `APP_URL`:

  ```bash
  php artisan storage:link
  ```

  `getUrl()` builds absolute URLs from the disk's `url` (`APP_URL.'/storage'`). If the panel is opened on another
  host than `APP_URL`, previews do not load.
- **The queue.** Conversions are queued by default (`media-library.queue_conversions_by_default`). Run a worker
  (`php artisan queue:work`), or use `QUEUE_CONNECTION=sync`. See [Conversions and previews](#conversions-and-previews).

## Quick start

The model declares its collections and conversions as usual:

```php
// app/Models/Post.php
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
        $this->addMediaCollection('contracts')->singleFile()->useDisk('local')->acceptsMimeTypes(['application/pdf']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 300, 300)
            ->performOnCollections('cover', 'gallery')
            ->nonQueued();
    }
}
```

The entity uses the fields:

```php
// app/Larsadmin/Post.php
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\Fields\MediaGallery;

public function fields(Request $request): array
{
    return [
        Text::make('Title', 'title')->rules('required'),
        Media::make('Cover', 'cover')->images()->conversion('thumb')->maxSize(4096)->rules('required'),
        MediaGallery::make('Gallery', 'gallery')->images()->conversion('thumb')->limit(12)->stripMetadata(),
        Media::make('Contract', 'contract', 'contracts')->types(['pdf'])->removable(false),
    ];
}
```

The model needs a Policy with `view` and `update` (guide 05 of the core): the fields take the right to see links
and to change media from it.

What the user gets:

- **List:** the first preview and a `+N` counter.
- **Record page:** previews or file names with download links. For users who may update the record, a gallery
  has "move up / move down" buttons and every media item a "Delete" button; both act at once (with a
  confirmation for delete) and are written to the audit log.
- **Form:** choose files, remove existing media (with "Undo"), reorder a gallery. Everything is saved with the
  form, in the same transaction as the record.

## The model is the source of truth

The field reads the model's declaration of its collection (`registerMediaCollections()`) and conversions
(`registerMediaConversions()`) instead of repeating them:

| Declaration | What the field does |
| --- | --- |
| `useDisk('s3')` | the upload disk: the field's `disk()`, else the collection's `useDisk()`, else `media-library.disk_name` |
| `singleFile()` / `onlyKeepLatest(1)` | required for a `Media` field: over a declared collection that may hold more media, `Media` throws `LogicException` on the first page load (it would hide or delete the others). A `MediaGallery` over a `singleFile()` collection throws `LogicException` too |
| `onlyKeepLatest(n)` | caps the gallery: the limit is `min(limit(), n)`; more files is a 422 before anything is written |
| `acceptsMimeTypes([...])` | a `mimetypes:` rule for every uploaded file (422) |
| `acceptsFile(fn)` | a file the callback refuses is a 422 at that upload; files already written in this save are removed |
| `registerMediaConversions()` | `conversion('thumb')` must be registered for the collection, else `LogicException` on the first page load. Registrations that depend on the media (`if ($media?->mime_type === …)`) cannot be checked reliably and are not checked (see [Limitations](#limitations)) |

Configuration errors are reported when the field is first resolved (any page that shows it), so they show up in
development and in tests, not as a 500 in the middle of a save. The model must implement `HasMedia`; otherwise
the field throws `LogicException` too.

Keep the constraints in the model: code outside the panel (imports, jobs, an API) then follows the same rules.

## The `Media` field

```php
Media::make('Cover', 'cover')                       // collection "cover"
Media::make('Contract', 'contract', 'contracts')    // attribute "contract", collection "contracts"
```

`Media::make(string $name, ?string $attribute = null, ?string $collection = null)`: the attribute defaults to
the snake-cased name, the collection to the attribute. The field holds one media item: a new upload replaces the
current one (the old media and its files are deleted after the commit).

If the model declares the collection, it must be `singleFile()` (or `onlyKeepLatest(1)`); otherwise the field throws
`LogicException` on the first page load, because a one-file field over a collection of several media would hide
them or delete them on save. A collection the model does not declare is accepted: there a new upload replaces only
the media the form showed, and media added meanwhile (by another user or your code) are never removed.

`rules()`, `creationRules()` and `updateRules()` work as for the core file fields: `required` (and `nullable`,
`sometimes`) apply to the field, every other rule to each uploaded file:

```php
Media::make('Cover', 'cover')->images()->rules('required', 'dimensions:min_width=800');
```

`required` means "at least one media item after the save", and it also stops deleting the last media item on the
record page (422).

## The `MediaGallery` field

```php
MediaGallery::make('Gallery', 'gallery')->images()->conversion('thumb')->limit(12);
```

`MediaGallery` extends `Media` and has every method of it plus `limit(int $maxFiles)`: the most media the gallery
may hold. The default is `larsadmin.files.max_files` (10); `onlyKeepLatest(n)` of the collection caps it. A save
that would leave more media is a 422 before anything is written. `limit(0)` throws `InvalidArgumentException`.

The order is the collection's `order_column`. In the form, new uploads are appended and the order is saved with the
form; on the record page "move up / move down" saves the new order at once. The collection must not be
`singleFile()`.

## API reference

Stable for 1.x of the add-on:

| Method | Description |
| --- | --- |
| `Media::make(string $name, ?string $attribute = null, ?string $collection = null)` | component `maxcxam/media`; `MediaGallery::make()` gives `maxcxam/media-gallery` |
| `collection(string $collection)` | the model's collection; the attribute by default |
| `conversion(?string $conversion)` | the conversion used for previews, e.g. `'thumb'`; must be registered for the collection (registrations that depend on the media are not checked). Without it, raster images are previewed by the original scaled down by CSS |
| `disk(?string $disk)` | the disk new uploads go to; `null` (default) is the collection's `useDisk()`, else `media-library.disk_name`. A disk missing from `filesystems.disks` throws `InvalidArgumentException` |
| `types(array $types)` | allowed extensions, e.g. `['pdf', 'docx']`: `mimes` + `extensions` rules and the file picker's `accept` |
| `images()` | raster images only, checked against an explicit list by content type (`mimetypes`) and extension. Without `types()` it allows jpg, jpeg, png, gif and webp; AVIF and BMP only when `types()` names them (`->types(['jpg', 'png', 'avif'])`). SVG is never allowed |
| `maxSize(int $kilobytes)` | the maximum size of one file in KB. The effective limit is `min(maxSize(), larsadmin.files.max_size, media-library.max_file_size)`, so it can only lower the panel's limit; less than 1 throws `InvalidArgumentException` |
| `public(bool $public = true)` | treat the media as public (plain storage URLs and the stricter blocked-extension list) or, with `false`, as `private()`; by default the disk's `visibility` decides |
| `private()` | `public(false)`: signed or temporary links instead of plain URLs. It changes **the links only, not where the files are stored**. On an upload disk whose visibility is `public` it throws `LogicException` (the files would stay reachable by URL): sensitive files need a private disk |
| `removable(bool $removable = true)` | allow removing media in the form and on the record page. With `false` there is no "Remove" button, the server removes nothing (also from a forged request), the delete route answers 403; a new upload in `Media` still replaces the current file |
| `stripMetadata(bool $strip = true)` | remove image metadata (EXIF, GPS, XMP, IPTC) from every image uploaded through this field; off by default (see [Image metadata](#image-metadata-stripmetadata)) |
| `MediaGallery::limit(int $maxFiles)` | the most media in the gallery (see above) |

Also stable: the extension name `maxcxam/larsadmin-medialibrary` and the component keys `maxcxam/media` and
`maxcxam/media-gallery`. Everything inherited from the core `Field` works as usual: `help()`, `readonly()`,
`sensitive()`, `canSee()`, `hideFromIndex()`, `onlyOnForms()` and so on. `sortable()` throws `LogicException` (a
media field has no column); `fillUsing()` replaces saving completely.

Internal (may change in any release): the other public methods of the field classes, the controller, the
validation rules, the metadata stripper, the service provider, the routes, the form payload and the serialized
keys. The Vue part ships with the PHP part, so they always match.

## Conversions and previews

`->conversion('thumb')` picks the conversion shown as the preview in the list, on the record page and in the form.
The original stays available as the download.

- **Non-queued conversions** (`->nonQueued()`) are made during the save, inside the panel's request.
- **Queued conversions** (the media library's default) are dispatched **after the commit**
  (`media-library.queue_conversions_after_database_commit`, `true` by default; keep it). A job never sees a media
  row that a rolled-back save removed again, and a job for a media item deleted in the meantime ends quietly.
- **Until the conversion exists** the preview is the original (for raster images) or the file icon, with the label
  "Generating preview…" (`previewPending`). Reload the page after the worker ran. Files no image generator can
  handle (a ZIP, say) never get a conversion and are not marked as pending.
- If previews stay "Generating preview…", the queue worker is not running.

Conversions of a field with `stripMetadata()` are made from the stripped original.

## Disks, URLs and access

**Who gets links.** URLs are issued only when the field is visible to the user and
`Field::authorizedTo('view')` is true: the panel answers it from the record's Policy, which it already called for the
row, so no extra policy calls are made. Outside an entity (a field resolved by hand) it is `false`: no links,
names only. Rows are scoped by `indexQuery()` / `detailQuery()` of the entity as usual.

**URLs per disk:**

| Disk of the media | URL | Checked on every request |
| --- | --- | --- |
| public (`visibility => 'public'`, or the field has `->public()`) | `$media->getUrl()`: a permanent public URL served by the web server | nothing: public by definition |
| private S3-compatible (provides temporary URLs) | a temporary provider URL, lifetime `larsadmin.files.url_ttl`; non-raster files forced to download (`attachment`, `application/octet-stream`) | the provider's signature |
| private local | the relative signed panel route `larsadmin.ext.medialibrary.show` | see below |

**Public or private.** The field decides per media item, from the disk the item is stored on:

1. `->public()` / `->private()` on the field wins for the links (`->private()` is refused on a public upload disk,
   see below);
2. otherwise the media are public **only if** that disk's config has `'visibility' => 'public'`
   (`config("filesystems.disks.{disk}.visibility") === 'public'`);
3. a disk config **without a `visibility` key is private**. In Laravel's default `config/filesystems.php` that is
   the `local` disk (`storage/app/private`) and `s3`; the `public` disk has `'visibility' => 'public'`.

A private disk whose driver is not `local` and that provides temporary URLs (S3 and compatible) gets temporary
provider URLs; every other private disk (`local`, or a driver without temporary URLs) gets the signed panel route.
**`->private()` does not make files private.** It only changes the links: the file is written to the same upload
disk. On a public disk the file would stay in the web root, reachable by anyone who guesses or keeps the URL, so the
field refuses this combination with a `LogicException` on the first page load. For sensitive files use a private
disk (`useDisk('local')` on the collection, or `->disk('local')`). On a disk that is not public, signed or temporary
links are the default anyway, so `->private()` adds nothing there.

The blocked-extension check follows where the file really lives: an upload counts as public (markup and scripts are
always refused) when the upload disk's visibility is `public` or the field has `->public()`. `->public()` on a disk the
web server does not serve (such as `local`) gives URLs that do not load: use it only for a disk with a public `url`.

The route of private media:

```
GET {path}/ext/medialibrary/{entity}/{id}/{field}/{media}?expires=…&signature=…
```

It runs behind the panel's login and gate, `signed:relative` and the `larsadmin-files` rate limiter
(`larsadmin.files.throttle`). Then, on every request:

1. the record through `Larsadmin::findRecord(…, 'view')`: the entity exists, `viewAny`, the record is in
   `detailQuery`, `view` on the record (404 / 403 otherwise);
2. the field with this attribute is visible on the record page or the list, or on the update form for a user with
   `update` (404 otherwise);
3. the media belongs to the field's collection of **this** record (404 otherwise), so a valid link of one record
   cannot be pointed at another record's media;
4. only the original or the field's own preview conversion is served (404 for any other conversion).

So a link stops working as soon as the user loses access or the media is removed, even before it expires.
Signed and temporary links expire at now + `url_ttl` rounded up to a quarter of the TTL, so a page reload within that
window gets the same URLs and the browser cache works.

**How files are served** (the private local route): through the core's `FileSafety::response()`, streamed, with
`X-Content-Type-Options: nosniff`, `Content-Security-Policy: sandbox`, `Cross-Origin-Resource-Policy: same-origin`
and `Referrer-Policy: no-referrer`. A file is shown inline **only if it is a raster image by both its stored
extension and its stored MIME type**; everything else is an `application/octet-stream` attachment.

**SVG and HTML are never shown inline.** `images()` accepts only the raster types of its list, by content type and
extension, never SVG. On public disks markup and scripts
(SVG, HTML, XML, JS, PHP) are always rejected by `larsadmin.files.blocked_extensions`, whatever `types()` says. On
private disks such files can only be downloaded as attachments.

**Changes.** The form needs the panel's `create` / `update` rights, a visible field that is not `readonly()`.
Reorder (`POST {path}/ext/medialibrary/{entity}/{id}/{field}/order`) and delete
(`DELETE {path}/ext/medialibrary/{entity}/{id}/{field}/{media}`) on the record page need `update` on the record
(`Larsadmin::findRecord(…, 'update')`) and a field that is editable on the update form
(`Larsadmin::findField(…, Field::UPDATE)`); both run through `Larsadmin::audited()`. These routes are internal: use
them only through the field's UI.

## Access per record

Who may see and download a media field is decided per record by the record's Policy and the field's visibility;
the signed route re-checks both on every download (`view` on the record, then the field must be visible to the
user for that record).

`canSee()` cannot depend on the record: its callback receives only the request (`fn (Request $request) => …`),
so it hides the field for all records or none. For a rule that depends on the record, use the per-record
visibility callbacks of the core `Field`: `hideFromDetail()`, `showOnDetail()`, `hideWhenUpdating()`,
`showWhenUpdating()` accept `fn (Request $request, ?Model $record): bool`. On the list (`hideFromIndex()`,
`showOnIndex()`) the callback always gets `null` as the record, so a per-record rule there is not possible: hide
the field from the list instead.

Example: a contract that only users who may **update** the record can see and download, while others may still
view the record:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

Media::make('Contract', 'contract', 'contracts')
    ->types(['pdf'])
    ->hideFromIndex()
    ->hideFromDetail(fn (Request $request, ?Model $record) => ! $request->user()?->can('update', $record));
```

- Users without `update` do not get the field on the record page, so no link is issued to them, and the signed
  route answers 404 for them even with a link copied from someone else: the route accepts the field only when it
  is visible on the record page or the list, or on the update form for a user with `update`.
- The update form shows the field only to users with `update` anyway.
- Keep such files on a private disk (the `contracts` collection above uses `useDisk('local')`): on a public disk
  the URL itself is the only protection, and `->private()` does not change that.

## Validation errors

Every problem is a 422 and nothing is written: no media rows, no files. The error keys:

| Key | Errors |
| --- | --- |
| `{attribute}.upload.N` | everything about one uploaded file (N is its index in the request): size, pixels, type, blocked extension, `acceptsMimeTypes()`, `acceptsFile()`, `stripMetadata()` |
| `{attribute}` | the field as a whole: `required`, and more media than `limit()` / `onlyKeepLatest(n)` after the save |
| `{attribute}.upload` | more files in one request than the field's limit |
| `ids` | the record-page reorder with a stale list |

The form shows each error next to the file or the field it belongs to.

| Case | Error |
| --- | --- |
| a file larger than `min(maxSize(), larsadmin.files.max_size, media-library.max_file_size)` | the standard `max` message |
| a type outside `types()`, or not an allowed raster image with `images()` | the standard `mimes` / `mimetypes` / `extensions` message |
| an image larger than `larsadmin.files.max_pixels` pixels (width × height, read from the header; 40 MP by default) | "The … image may have at most N megapixels." |
| a type outside the collection's `acceptsMimeTypes()` | the standard `mimetypes` message |
| a blocked extension (`larsadmin.files.blocked_extensions`, markup and scripts on public disks) | "The … may not be a file of type …" |
| the collection's `acceptsFile()` refuses the file | "The … file is not accepted." (raised in the save; files written so far are removed) |
| more media than `limit()` / `onlyKeepLatest(n)` after the save | "The … may not have more than N files." |
| a `required` field left empty | "The … field is required." |
| `stripMetadata()`: an image type that cannot be stripped (AVIF, HEIC …) | "The … image must be a JPEG, PNG, WebP or GIF: the metadata of other image types cannot be removed." (see [Image metadata](#image-metadata-stripmetadata)) |
| `stripMetadata()`: a damaged or truncated image | "The metadata of the … image could not be removed; the file may be damaged." |

On the record page:

| Case | Answer |
| --- | --- |
| deleting the last media item of a `required` field | 422 "The … field is required." (checked with the rows locked, so two parallel deletes cannot empty it) |
| reordering with a list that is not exactly the current media (someone changed it meanwhile) | 422 "The media changed meanwhile; reload the page." |
| deleting with `removable(false)` | 403 |
| media of another record or collection, an unknown conversion | 404 |

A request larger than PHP's `post_max_size` never reaches validation: Laravel answers 413 and the form shows the
core's "files are too large" message. Raise `upload_max_filesize` / `post_max_size` (and the web server's limit)
for large galleries.

**Stale forms.** The form sends the ids of the media it loaded. Only those can be removed by the save, so media
another user added in the meantime are never deleted by an old form.

## Image metadata: `stripMetadata()`

Photos from phones and cameras carry metadata: EXIF (GPS coordinates, device, time), XMP and IPTC. The media library
stores the original byte for byte, so on a public disk anyone with the URL can read where and when a photo was
taken. **Without `stripMetadata()` the original keeps all of it.**

```php
// The types images() allows by default (jpg, jpeg, png, gif, webp) are exactly the ones stripMetadata() can clean.
Media::make('Photo', 'photo')
    ->images()
    ->types(['jpg', 'jpeg', 'png', 'gif', 'webp'])
    ->conversion('thumb')
    ->stripMetadata();

MediaGallery::make('Photos', 'photos')
    ->images()
    ->types(['jpg', 'jpeg', 'png', 'gif', 'webp'])
    ->conversion('thumb')
    ->limit(20)
    ->stripMetadata();
```

`images()` and `stripMetadata()` check different things: `images()` decides which raster types may be uploaded
(jpg, jpeg, png, gif and webp by default; AVIF and BMP only when `types()` names them); `stripMetadata()` can only
clean JPEG, PNG/APNG, WebP and GIF and rejects every other image with a 422. So do not add `avif` or `bmp` to
`types()` of a field with `stripMetadata()`: such uploads pass the type check and are then rejected. Spelling the
types out, as above, keeps the field correct whatever the default is.

`stripMetadata(bool $strip = true)` is off by default. When on, every file uploaded through this field whose
content is an image (`image/*`, detected from the content) is cleaned before it is stored. The file is **not
re-encoded**: the add-on rewrites the image container and copies the pixel data byte for byte, so there is no loss
of quality, the colour profile (ICC) and animation stay, and no PHP extension is needed.

| Format | Kept | Removed |
| --- | --- | --- |
| JPEG (baseline and progressive) | image data, JFIF/JFXX, the ICC profile, the Adobe segment | EXIF, XMP (also extended), MPF, IPTC/Photoshop, other application segments, comments, everything after the end of the image (MPF images, motion photos) |
| PNG / APNG | image data and palette, transparency, gamma, chromaticity, sRGB, the ICC profile, `sBIT`, `bKGD`, `pHYs`, `hIST`, animation chunks | `eXIf`, `tEXt`, `zTXt`, `iTXt`, `tIME` and every unknown chunk; everything after `IEND` |
| WebP | image data, alpha, animation, the ICC profile | EXIF, XMP and unknown chunks (the VP8X flags and the RIFF size are fixed) |
| GIF | image data, colour tables, frame control, looping (`NETSCAPE2.0`) | comments and other application extensions (XMP, ICC …); everything after the trailer |
| any other image (AVIF, HEIC/HEIF, TIFF, BMP, SVG, ICO …) | — | **the file is rejected with a 422** |
| not an image (PDF, Office …) | the file unchanged | — (only images are cleaned) |

Lists of what is kept are allow-lists: metadata of a kind the add-on does not know is removed, never kept.

**Orientation is kept.** Phone photos are often stored sideways with an EXIF Orientation tag. Removing it would turn
the photo on its side, and rotating the pixels would mean re-encoding. So when the original had an Orientation of
2…8, the cleaned file gets a minimal EXIF block with **only that tag** (no other data). Browsers and `spatie/image`
conversions apply it, so the original and the previews are shown the right way up.

**Fail closed.** An unsupported image type, a damaged or truncated file, an unknown JPEG segment before the image
data, a failed self-check of the result (the add-on re-reads it, compares the size and the pixel data) or a write
error: the upload is rejected with a 422 next to that file. **An unstripped image is never stored.** The message
does not reveal parser details. Cleaning is idempotent: a cleaned file cleaned again is byte-for-byte the same.

Notes:

- With `stripMetadata()` AVIF and HEIC uploads are rejected. Tell users to upload JPEG, PNG or WebP (phones can
  export them).
- Only panel uploads of this field are cleaned. Media your own code adds (`$post->addMedia()`, imports) and media
  stored before you turned the option on are not changed.
- The stored name and the original name (`original_name`) are the same as without the option.
- The cleaned copy is written to a private working directory, `storage/app/larsadmin-medialibrary-strip`, with one
  directory per upload (mode 0700, unpredictable name). It is deleted after the media is added and at the end of the
  request; directories left behind by a crash are removed when they are more than an hour old (at the next upload).
  The directory needs no setup; keep it out of backups and off any public disk.
- Metadata of PDF and Office files is not touched. Keep such files on a private disk.

## File lifecycle

Disks are not transactional, so the add-on ties files to the panel's save through the core's file journal
(`FileJournal`):

| Event | Media rows | Files |
| --- | --- | --- |
| form save | written and deleted in the save's transaction | new files (original, non-queued conversions, responsive images) are deleted again if the save fails, also when `addMedia()` itself fails after writing them; files of removed media are deleted **after the commit** |
| queued conversions | — | made after the commit; until then the preview is the original |
| delete on the record page | deleted in the audited transaction | deleted after the commit |
| deleting the record | the media library deletes them; soft deletes keep them until the force delete | deleted by the media library at once (see below) |

**What is deleted with a media item:** the original, every generated conversion and the responsive images of the
original and its conversions (resolved through the media's path generator). Directories are never deleted, because a
custom path generator may put several media in one directory, so empty directories can remain.

**A rolled-back save** (a validation error in the deferred save, `acceptsFile()` refusing a file, an exception of
your model hook) leaves no new files and no new rows behind, and the media it would have removed stay.

**A failure inside `addMedia()`.** The media library writes the row and the original first, then makes the non-queued
conversions and responsive images and fires its events. If one of these steps throws (an image GD or Imagick cannot
read, a listener of yours, a full disk), the field looks up the half-added media item by its stored name and removes
its files: in a panel save through the journal when the transaction rolls back, outside the panel at once together
with the row. Only a process that dies mid-request (a fatal error, a killed worker) can still leave a file without a
row.

**Deleting a record (limitation).** The core 1.6 has no field hook for record deletion, so it is left to the media
library: its observer deletes the media rows and their files as soon as the model is deleted, before the panel's
transaction commits. If something later in that transaction fails (only the audit write follows), the record and its
media rows are restored, but the files are already gone. Soft-deleted records keep their media. A stable field hook
for record deletion is planned for the core; the add-on will then move record deletion onto the journal in a minor
release.

**Outside the panel.** `fill()` without a journal (your own code calling the field) deletes the files of removed media
after the commit too; files it writes are not tracked.

## Audit log

With the core's audit log on, a media field is recorded as the list of its media, `[{id, name}]` in order, never URLs
or paths. Form saves, reorders and deletes on the record page are all recorded. `->sensitive()` masks the value as
usual.

## Configuration

The add-on has no config file of its own. It reads:

| Key | Used for |
| --- | --- |
| `larsadmin.files.max_size` | the upper bound of `maxSize()`, KB |
| `larsadmin.files.max_pixels` | the largest image in pixels (width × height), `40000000` (40 MP) by default. Not in the core's published config: add it to the `files` section of your `config/larsadmin.php` to change it |
| `larsadmin.files.max_files` | the default `limit()` of a gallery |
| `larsadmin.files.url_ttl` | the lifetime of signed and temporary links, minutes |
| `larsadmin.files.throttle` | downloads per minute through the private route (limiter `larsadmin-files`) |
| `larsadmin.files.blocked_extensions` | extensions never accepted (markup and scripts always blocked on public disks) |
| `media-library.disk_name` | the upload disk when neither the field nor the collection names one |
| `media-library.max_file_size` | another upper bound of `maxSize()`, bytes |
| `media-library.queue_conversions_by_default`, `queue_conversions_after_database_commit` | see [Conversions and previews](#conversions-and-previews) |

## Security

- Give the model a Policy with `view` and `update`, and scope rows in `indexQuery()` / `detailQuery()`. Links,
  reorder and delete follow them.
- **Media on a public disk are readable by anyone who has the URL**, without login. Keep contracts, IDs, invoices and
  medical files on a private disk (`useDisk('local')` on the collection, or `->disk('local')`). `->private()` alone
  only changes the links, and on a public disk the field refuses it.
- Photos of people or places on a public disk: use `->stripMetadata()` (or a private disk).
- Always set `types()` or `images()`; `blocked_extensions` is only a backstop. Declare `acceptsMimeTypes()` on the
  collection where the type matters.
- Stored names are random (40 characters) with an extension detected from the content, never the browser's; the
  client name is kept sanitized as the custom property `original_name`.
- The public disk is served by the web server, which the panel cannot add headers to. Add `nosniff`,
  `Content-Security-Policy: sandbox` and `Content-Disposition: attachment` for non-images to `/storage` in the web
  server config, as the core recommends for its own file fields.
- Never build media URLs, paths or file names from request input in your own code.

To report a vulnerability, see `SECURITY.md` of the package.

## AI assistants

The add-on ships a skill for AI coding assistants, `larsadmin-medialibrary`
(`resources/boost/skills/larsadmin-medialibrary/SKILL.md`), and a short guideline
(`resources/boost/guidelines/core.md`), in English. The skill holds the recipes (cover, gallery, private PDFs, phone
photos with `stripMetadata()`, queued conversions, host tests), the API cheat sheet and the security rules.

**With Laravel Boost (2.x):**

```bash
composer require laravel/boost --dev
php artisan boost:install
```

Select `maxcxam/larsadmin-medialibrary` (and `maxcxam/larsadmin`) among the third-party packages. Boost sees
**direct** dependencies only: both packages must be in the `require` section of your `composer.json`, or the core's
skills are not installed. After updating the add-on run `php artisan boost:update`.

**Without Boost:** copy the skill directory into your assistant's skills directory, for Claude Code:

```bash
mkdir -p .claude/skills
cp -r vendor/maxcxam/larsadmin-medialibrary/resources/boost/skills/larsadmin-medialibrary .claude/skills/
```

Copy it again after updating the add-on. `php artisan larsadmin:ai --skills` of the core copies the core's skills
only. The core's MCP tools `search-docs` / `read-docs` do not index this guide; the skill links to it at
`vendor/maxcxam/larsadmin-medialibrary/docs/guide/en/medialibrary.md`.

## Testing in host apps

Test the fields over HTTP, like the rest of the panel (guide 21 of the core), or call one field directly with the
core's test helpers. Either way you drive a media field with the **form payload**: the field's value is an array
under its attribute, sent as `multipart/form-data`, exactly as the panel's form sends it.

| Key | Value | Meaning |
| --- | --- | --- |
| `{attr}[present]` | `1` | the field was sent. Without the attribute in the request the field is left alone: stored media stay |
| `{attr}[known][]` | media ids | the media the form loaded. Only these can be removed by this request |
| `{attr}[keep][]` | media ids, in order | the media to keep, in the new order (a gallery is saved in this order, new uploads appended) |
| `{attr}[upload][]` | files | new files. In `Media` an upload replaces the current media item |

So: removing media = list it in `known` and leave it out of `keep`; reordering = `keep` in the new order; a request
with only `present` and `upload` adds files and removes nothing. Updates go as `POST` with `_method=PUT`, as the
form does. The payload is meant for tests and the bundled form only: do not build it in application code.

### Pest

```php
use App\Models\Post;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('creates a post with a cover', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)
        ->post('/admin/entities/posts', [
            'title' => 'Hello',
            'cover' => ['present' => 1, 'upload' => [UploadedFile::fake()->image('cover.jpg', 800, 600)]],
        ])
        ->assertSessionHasNoErrors();

    $media = Post::sole()->getFirstMedia('cover');

    expect($media)->not->toBeNull()
        ->and($media->getCustomProperty('original_name'))->toBe('cover.jpg');
    Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
});

it('reorders and removes gallery media', function () {
    Storage::fake('public');
    $post = Post::factory()->create(['title' => 'Hello']);
    $a = $post->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection('gallery');
    $b = $post->addMedia(UploadedFile::fake()->image('b.jpg'))->toMediaCollection('gallery');
    $c = $post->addMedia(UploadedFile::fake()->image('c.jpg'))->toMediaCollection('gallery');

    $this->actingAs($this->admin)
        ->post("/admin/entities/posts/{$post->id}", [
            '_method' => 'PUT',
            'title' => 'Hello',
            // b is removed (known, not kept); c now comes before a.
            'gallery' => ['present' => 1, 'known' => [$a->id, $b->id, $c->id], 'keep' => [$c->id, $a->id]],
        ])
        ->assertSessionHasNoErrors();

    expect($post->fresh()->getMedia('gallery')->pluck('id')->all())->toBe([$c->id, $a->id]);
});

it('rejects a file of the wrong type', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)
        ->post('/admin/entities/posts', [
            'title' => 'Hello',
            'cover' => ['present' => 1, 'upload' => [UploadedFile::fake()->create('cover.exe', 1)]],
        ])
        ->assertSessionHasErrors('cover.upload.0');

    expect(Post::count())->toBe(0);
});
```

The core's `Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin` helpers work for media fields too. In Pest add the
trait in `tests/Pest.php` (`pest()->extend(Tests\TestCase::class)->use(InteractsWithLarsadmin::class)->in('Feature')`):

```php
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\LarsadminMedialibrary\Fields\Media;

it('registers the add-on', function () {
    $this->assertExtensionRegistered('maxcxam/larsadmin-medialibrary');
});

it('matches the model collections and conversions', function () {
    // A gallery over singleFile() or an unregistered conversion throws LogicException here.
    $field = Media::make('Cover', 'cover')->images()->conversion('thumb');

    expect(fn () => $this->serializeField($field, Post::factory()->create(), Field::DETAIL, \App\Larsadmin\Post::class, ['view' => true]))
        ->not->toThrow(LogicException::class);
});

it('stores phone photos without GPS', function () {
    Storage::fake('public');
    $post = Post::factory()->create();
    $photo = UploadedFile::fake()->createWithContent('phone.jpg', file_get_contents(base_path('tests/Fixtures/phone-photo-gps.jpg')));

    // fillField($field, $record, $input, $files): the payload without files, then the files under the same keys.
    $this->fillField(Media::make('Cover', 'cover')->images()->stripMetadata(), $post, ['cover' => ['present' => 1]], ['cover' => ['upload' => [$photo]]]);

    $exif = @exif_read_data($post->getFirstMedia('cover')->getPath()) ?: [];
    expect($exif)->not->toHaveKey('GPSLatitude');
});
```

### PHPUnit

The same tests as a PHPUnit class. Use the trait in the test class to get the helpers:

```php
namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin;
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Tests\TestCase;

class PostMediaTest extends TestCase
{
    use InteractsWithLarsadmin;
    use RefreshDatabase;

    public function test_the_add_on_is_registered(): void
    {
        $this->assertExtensionRegistered('maxcxam/larsadmin-medialibrary');
    }

    public function test_a_post_is_created_with_a_cover(): void
    {
        Storage::fake('public');

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post('/admin/entities/posts', [
                'title' => 'Hello',
                'cover' => ['present' => 1, 'upload' => [UploadedFile::fake()->image('cover.jpg', 800, 600)]],
            ])
            ->assertSessionHasNoErrors();

        $media = Post::sole()->getFirstMedia('cover');

        $this->assertNotNull($media);
        $this->assertSame('cover.jpg', $media->getCustomProperty('original_name'));
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
    }

    public function test_a_file_of_the_wrong_type_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post('/admin/entities/posts', [
                'title' => 'Hello',
                'cover' => ['present' => 1, 'upload' => [UploadedFile::fake()->create('cover.exe', 1)]],
            ])
            ->assertSessionHasErrors('cover.upload.0');

        $this->assertSame(0, Post::count());
    }

    public function test_phone_photos_are_stored_without_gps(): void
    {
        Storage::fake('public');
        $post = Post::factory()->create();
        $photo = UploadedFile::fake()->createWithContent('phone.jpg', file_get_contents(base_path('tests/Fixtures/phone-photo-gps.jpg')));

        $this->fillField(Media::make('Cover', 'cover')->images()->stripMetadata(), $post, ['cover' => ['present' => 1]], ['cover' => ['upload' => [$photo]]]);

        $exif = @exif_read_data($post->getFirstMedia('cover')->getPath()) ?: [];
        $this->assertArrayNotHasKey('GPSLatitude', $exif);
    }
}
```

The examples assume a panel gate that lets in users with `is_admin` (see guide 21 of the core for the gate in tests).

Notes:

- `fillField()` validates with the field's rules and saves through a file journal, as the panel does; a validation
  error throws `ValidationException` with the keys listed in [Validation errors](#validation-errors).
- `UploadedFile::fake()->image()` needs GD and has no metadata. For the `stripMetadata()` test use a real photo with
  GPS (a fixture in your repository).
- Queued conversions run in the request with `QUEUE_CONNECTION=sync` (the Laravel default for tests).

## Limitations

- **No alt text or caption editing** (custom properties) in 1.0; planned for a minor release of the add-on.
- **Uploads go with the form**: no pre-upload or chunked upload, so the total size is bounded by `post_max_size`.
- No `srcset` / responsive images in the panel's previews (responsive image files are deleted correctly).
- Media fields are not supported in action modals (`LogicException`, like every field that uploads files) and cannot
  be sorted.
- `stripMetadata()` cleans JPEG, PNG/APNG, WebP and GIF only; AVIF, HEIC/HEIF and other image types are rejected, and
  PDF / Office metadata is not touched.
- Deleting a record: the media library deletes media files before the panel's transaction commits (see
  [File lifecycle](#file-lifecycle)); empty directories can remain after removals.
- Media models with UUID primary keys are not supported by the record-page routes.
- No media browser, ZIP download or moving media between collections.
- Conversions registered conditionally (`registerMediaConversions()` that looks at the media, e.g. its MIME type)
  are not checked against `conversion()`: the add-on cannot know which media will get them. A misspelt name there
  is not reported; the preview then stays the original with "Generating preview…".
- The core's MCP server does not know the add-on yet: `describe-entity` does not show the options of media fields
  (collection, conversion, disk, types, `stripMetadata()`), and `search-docs` / `read-docs` do not index this
  guide. Both are planned for core 1.7.
- `spatie/laravel-medialibrary` 12 is not supported yet (the range is widened after it is released and tested).

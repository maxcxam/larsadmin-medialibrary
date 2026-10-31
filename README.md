# Larsadmin Media Library

[spatie/laravel-medialibrary](https://spatie.be/docs/laravel-medialibrary) fields for the
[Larsadmin](https://github.com/maxcxam/larsadmin) admin panel: `Media` (one file or image) and `MediaGallery`
(an ordered gallery).

[![Latest Version on Packagist](https://img.shields.io/packagist/v/maxcxam/larsadmin-medialibrary.svg)](https://packagist.org/packages/maxcxam/larsadmin-medialibrary)
[![Tests](https://github.com/maxcxam/larsadmin-medialibrary/actions/workflows/tests.yml/badge.svg)](https://github.com/maxcxam/larsadmin-medialibrary/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/maxcxam/larsadmin-medialibrary.svg)](LICENSE)

## Features

- **Two fields**: `Media` for a cover, an avatar or a contract (a new upload replaces the current file) and
  `MediaGallery` for several media in the collection's order, reorderable in the form and on the record page.
- **The model is the source of truth**: the field follows the collection's `useDisk()`, `singleFile()`,
  `onlyKeepLatest(n)`, `acceptsMimeTypes()` and `acceptsFile()`; a misconfigured field fails early with a
  `LogicException`, a refused file is a 422 next to that file.
- **Previews from your conversions**, queued conversions run after the commit, and the original is shown until the
  preview is ready.
- **Private media**: temporary URLs on private S3-compatible disks, signed panel links on private local disks,
  re-authorized on every download (the record's policy, field visibility, collection membership). Only raster
  images are ever shown inline; SVG and HTML never are.
- **Files follow the save transaction**: uploads of a failed save are deleted, files of removed media (with their
  conversions and responsive images) are deleted after the commit.
- **Opt-in image metadata stripping**: `->stripMetadata()` removes EXIF (GPS, device, time), XMP and IPTC from JPEG,
  PNG, WebP and GIF uploads without re-encoding, keeps the orientation, and rejects what it cannot clean.
- **Audit log** entries for form saves, reorders and deletes; `->sensitive()` masks them.
- **AI skill** `larsadmin-medialibrary` for Laravel Boost (or a manual copy).
- Prebuilt frontend: your application needs no Node.js or npm. Ukrainian and English translations.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- `maxcxam/larsadmin` 1.6+
- `spatie/laravel-medialibrary` 11.23+ (needs `ext-exif` and `ext-fileinfo`; GD or Imagick for conversions)

## Installation

```bash
composer require maxcxam/larsadmin-medialibrary
```

The add-on registers itself (package discovery). If the media library is new in your app, publish and run its
migration:

```bash
php artisan vendor:publish --tag=medialibrary-migrations
php artisan migrate
```

For the `public` disk run `php artisan storage:link` and set `APP_URL`. For queued conversions run a queue worker.

## Quick start

```php
// app/Models/Post.php
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
        $this->addMediaConversion('thumb')->fit(Fit::Crop, 300, 300)->performOnCollections('cover', 'gallery')->nonQueued();
    }
}
```

```php
// app/Larsadmin/Post.php
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\Fields\MediaGallery;

public function fields(Request $request): array
{
    return [
        Media::make('Cover', 'cover')->images()->conversion('thumb')->maxSize(4096)->rules('required'),
        MediaGallery::make('Gallery', 'gallery')->images()->conversion('thumb')->limit(12)->stripMetadata(),
        Media::make('Contract', 'contract', 'contracts')->types(['pdf'])->removable(false),
    ];
}
```

Give the model a Policy with `view` and `update`: media links, reorder and delete follow it.

Stable field API (1.x): `Media::make(name, attribute = null, collection = null)`, `collection()`, `conversion()`,
`disk()`, `types()`, `images()`, `maxSize()`, `public()`, `private()`, `removable()`, `stripMetadata()`,
`MediaGallery::limit()`, plus everything inherited from the core `Field`.

## Security notes

- Media on a **public disk** are readable by anyone who has the URL. Keep sensitive files on a private disk
  (`useDisk('local')` on the collection, or `->disk('local')`). `->private()` changes the links only, not where the
  files are stored, and is refused (`LogicException`) on a public upload disk.
- Without `->stripMetadata()` the original is stored byte for byte, **including the GPS position of phone photos**.
  Use it (or a private disk) for photos of people or places. It cleans JPEG, PNG, WebP and GIF and rejects other
  images (AVIF, HEIC …).
- Always restrict a field with `types()` or `images()` (jpg, jpeg, png, gif, webp by default; never SVG).
- A single `Media` field needs a collection declared `singleFile()` (or `onlyKeepLatest(1)`).
- Images above `larsadmin.files.max_pixels` (40 MP by default) are rejected before any conversion runs.

## Documentation

The full guide, in English and Ukrainian:

- [Media library guide](docs/guide/en/medialibrary.md) (English)
- [Посібник медіатеки](docs/guide/medialibrary.md) (Українська)

It covers installation, collections and conversions, both fields, disks and URLs, validation errors, image
metadata, the file lifecycle, the audit log, security, AI assistants, testing and limitations.

The core's guides: [Larsadmin documentation](https://github.com/maxcxam/larsadmin#documentation), in particular
"15. Files and images" and "19. Extensions".

## AI assistants

With [Laravel Boost](https://github.com/laravel/boost) 2.x, `php artisan boost:install` installs the skill
`larsadmin-medialibrary` and the add-on's guideline when you select `maxcxam/larsadmin-medialibrary`. Boost sees
direct dependencies only, so keep `maxcxam/larsadmin` in your `require` too. Without Boost, copy the skill:

```bash
mkdir -p .claude/skills
cp -r vendor/maxcxam/larsadmin-medialibrary/resources/boost/skills/larsadmin-medialibrary .claude/skills/
```

## Development

The package is developed in the Larsadmin monorepo (`packages/larsadmin-medialibrary`) and published to
`github.com/maxcxam/larsadmin-medialibrary` by a subtree split. In the monorepo, use the development manifest, which
resolves the core from `../larsadmin`:

```bash
COMPOSER=composer.monorepo.json composer update
vendor/bin/pest                      # PHP (Testbench, SQLite in memory, fake disks)
npm ci && npm test && npm run build  # Vue (vitest); dist/ is committed
node vendor/maxcxam/larsadmin/kit/check-public-api.mjs .
```

In a standalone clone plain `composer update` installs the core from Packagist.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Security

Please do not report vulnerabilities in public issues. Use GitHub private vulnerability reporting as described
in [SECURITY.md](SECURITY.md).

## License

MIT, see [LICENSE](LICENSE).

This package is an independent project and is not affiliated with, sponsored or endorsed by Spatie or Laravel
Holdings Inc.

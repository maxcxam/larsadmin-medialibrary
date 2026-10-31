# Larsadmin media library

`maxcxam/larsadmin-medialibrary` adds `spatie/laravel-medialibrary` fields to the Larsadmin panel:
`Maxcxam\LarsadminMedialibrary\Fields\Media` (one file or image) and `Maxcxam\LarsadminMedialibrary\Fields\MediaGallery`
(an ordered gallery). For uploads, images, galleries or attachments of a model that implements `HasMedia`, use the
skill `larsadmin-medialibrary`. Plain file columns without the media library use the core `File` / `Image` fields
(skill `larsadmin-files`).

## Golden rules

- The model's `registerMediaCollections()` / `registerMediaConversions()` are the source of truth: declare disk
  (`useDisk()`), `singleFile()`, `onlyKeepLatest()` and `acceptsMimeTypes()` there; `conversion()` must name a
  registered conversion.
- Give the model a Policy with `view` and `update`: media URLs, reorder and delete follow it.
- Sensitive files go to a disk whose `visibility` is not `'public'` (e.g. `local`). Photos of people or
  places on a public disk need `->stripMetadata()` (EXIF/GPS is removed; AVIF/HEIC are rejected). `->private()`
  changes links only and throws on a public disk. Per-record access: `hideFromDetail(fn ($request, $record) => ...)`.
- A single `Media` field needs a `singleFile()` / `onlyKeepLatest(1)` collection; use `MediaGallery` otherwise.
- Use only the stable field API: `Media::make()`, `collection()`, `conversion()`, `disk()`, `types()`, `images()`,
  `maxSize()`, `public()`, `private()`, `removable()`, `stripMetadata()`, `MediaGallery::limit()`. Never edit
  `vendor/maxcxam/larsadmin-medialibrary`.
- Guide of the installed version: `vendor/maxcxam/larsadmin-medialibrary/docs/guide/en/medialibrary.md`. The core's
  MCP `search-docs` does not index it and `describe-entity` omits media options: read the guide file instead.

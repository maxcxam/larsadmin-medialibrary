# maxcxam/larsadmin-medialibrary (UNRELEASED spike)

Acceptance gate of the larsadmin 1.5 extension API (Q-68, R-22): `spatie/laravel-medialibrary` fields built with
the public extension API and the author kit only. Not published, not required by the sandbox.

```php
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\Fields\MediaGallery;

Media::make('Cover', 'cover')->images()->conversion('thumb')->disk('public')->maxSize(2048)->rules('required');
MediaGallery::make('Gallery', 'gallery')->images()->conversion('thumb')->limit(8);
Media::make('Contract', 'contract')->types(['pdf'])->private();
```

The model implements `HasMedia` (`InteractsWithMedia`) and registers the collections / conversions as usual.

- Upload: the fields set `multipart: true`; the form sends `{attr}[present|known|keep|upload]` (only media in
  `known` can be removed; `keep` order is the new order). Validation per file: size, types (`mimes` + `extensions`),
  `image`, larsadmin's `files.blocked_extensions`, user rules; count and `required` at field level.
- Storage: random 40-char file names with a content-guessed extension; the sanitized client name is kept as the
  custom property `original_name`. In a panel save the field uses `Field::fillInto()` and the save's `FileJournal`:
  uploaded files (and conversions generated during the save) are `created()` (deleted on rollback), files of removed
  media `obsolete()` (deleted after the commit); media rows change inside the save's transaction.
- Audit (1.3): `hasAuditValue()` / `auditValue()` record the collection as `[{id, name}]` in order; detail-page
  reorder / delete run through `Larsadmin::audited()`. `->sensitive()` masks as usual.
- URLs: public disks → storage URL; S3-like private disks → `temporaryUrl()`; private local disks → a relative signed
  extension route (`larsadmin.ext.medialibrary.show`) re-authorized with `Larsadmin::findRecord(…, 'view')`, field
  visibility and collection membership; safe headers (nosniff, sandbox CSP, octet-stream attachments).
- Detail page: immediate reorder (`POST …/order`) and delete (`DELETE …/{media}`) through `Larsadmin::routes()`,
  authorized with `findRecord(…, 'update')` and `findField(…, Field::UPDATE)`.
- Record delete: medialibrary deletes the media (rows and files); soft deletes keep them.

```
composer install && vendor/bin/pest     # PHP (Testbench, SQLite in memory, fake disks)
npm install && npm test && npm run build
```

API gaps: `docs/reviews/2026-10-31-1.5-medialibrary-spike.md` (triage in design 1.5 §18). Public API check:
`node packages/larsadmin/kit/check-public-api.mjs packages/larsadmin-medialibrary`. Since larsadmin 1.6 (requires
`^1.6`) the gaps deferred from 1.5 are closed: uploads and served files go through the public `Files\FileSafety` (A6a),
and the field and the show route take the record's rights from `Field::authorizedTo()` instead of asking the policy
(A4). The path repository pins the core at `1.x-dev` (its `dev-main` branch alias) so it resolves from any branch. The Vue side uses
the stock kit preset and theme, `errorsByKey` for per-upload errors and `mountDisplay` in tests.

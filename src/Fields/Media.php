<?php

namespace Maxcxam\LarsadminMedialibrary\Fields;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Files\FileJournal;
use Maxcxam\Larsadmin\Files\FileSafety;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\LarsadminMedialibrary\MedialibraryServiceProvider;
use Maxcxam\LarsadminMedialibrary\Rules\MediaFieldState;
use Maxcxam\LarsadminMedialibrary\Rules\StrippableImage;
use Maxcxam\LarsadminMedialibrary\Support\MetadataStripFailed;
use Maxcxam\LarsadminMedialibrary\Support\MetadataStripper;
use Maxcxam\LarsadminMedialibrary\Support\StripWorkspace;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\ImageGenerators\ImageGeneratorFactory;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileCannotBeAdded;
use Spatie\MediaLibrary\MediaCollections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media as MediaModel;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Mime\MimeTypes;
use Throwable;
use WeakReference;

/**
 * One file or image of a spatie/laravel-medialibrary collection; a new upload replaces the current one.
 *
 * The model's collection (registerMediaCollections()) is the source of truth (ADR-042): its disk (useDisk()) is
 * the upload disk unless the field sets disk(), its acceptsMimeTypes() becomes a "mimetypes" rule, a file its
 * acceptsFile() refuses is a validation error of that upload, and conversion() must name a conversion registered
 * for the collection.
 *
 * Stable API (1.x of the add-on): make(name, attribute = null, collection = null), collection(), conversion(),
 * disk(), types(), images(), maxSize(), public(), private(), removable(), stripMetadata(), plus everything inherited
 * from Field.
 * The other public members, the wire format and the serialized keys are internal.
 *
 * Wire format (form → server, multipart): {attr}[present]=1, {attr}[known][]=id (media the form loaded),
 * {attr}[keep][]=id (in display order), {attr}[upload][]=binary. Only media in "known" can be removed, so a stale
 * form never deletes media added meanwhile. Media are written in the deferred fill() closure (the record needs a
 * key first); files of removed media are deleted after the commit (ADR-044).
 */
class Media extends Field
{
    public string $component = 'maxcxam/media';

    public bool $multipart = true;

    protected string $collection;

    protected ?string $conversion = null;

    protected bool $multiple = false;

    protected int $maxFiles = 1;

    protected ?int $maxSize = null;

    /** @var list<string> */
    protected array $types = [];

    protected bool $imagesOnly = false;

    protected ?bool $public = null;

    protected bool $removable = true;

    protected ?string $disk = null;

    protected bool $stripMetadata = false;

    /**
     * Stripped copies written during the request (validation, then the save): upload key => [workspace, its
     * directory, path].
     *
     * @var array<string, array{0: StripWorkspace, 1: string, 2: string}>
     */
    protected static array $strippedUploads = [];

    /** The application whose terminating() callback discards the stripped copies. */
    protected static ?WeakReference $strippedCleanup = null;

    /**
     * The raster types images() allows, by extension, with their content types (finfo). Explicit, so the result does
     * not depend on the framework's "image" rule (Laravel 12 and 13 differ on AVIF). SVG is never one of them.
     *
     * @internal
     */
    public const RASTER_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif', 'bmp' => 'image/bmp',
    ];

    /**
     * images() without types(): the formats every browser shows and stripMetadata() handles. AVIF is opt-in through
     * types() (stripMetadata() refuses it; its content detection depends on the server's libmagic).
     *
     * @internal
     */
    public const DEFAULT_IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * The default largest image in pixels (width x height), read from the header without decoding;
     * larsadmin.files.max_pixels overrides it. Bigger images would exhaust memory in conversions (SECM-03).
     *
     * @internal
     */
    public const DEFAULT_MAX_PIXELS = 40_000_000;

    /** @var class-string<Entity>|null */
    protected ?string $entity = null;

    /** @var list<array<string, mixed>> */
    protected array $media = [];

    protected ?string $urlsExpireAt = null;

    protected ?string $reorderUrl = null;

    /** The model class whose collection declaration was inspected (declaredCollection()). */
    protected ?string $inspectedModel = null;

    protected ?MediaCollection $declared = null;

    /**
     * @param  string  $name  the label
     * @param  string|null  $attribute  the field key; defaults to the snake-cased name
     * @param  string|null  $collection  the media collection; defaults to the attribute
     */
    public function __construct(string $name, ?string $attribute = null, ?string $collection = null)
    {
        parent::__construct($name, $attribute);

        $this->collection = $collection ?? $this->attribute;
    }

    /** The media collection of the model; defaults to the attribute. */
    public function collection(string $collection): static
    {
        $this->collection = $collection;
        $this->inspectedModel = null;

        return $this;
    }

    /**
     * The conversion used for previews (e.g. 'thumb'); the original is shown until it is generated. It must be
     * registered for the collection (registerMediaConversions()), else the first resolve throws a LogicException.
     */
    public function conversion(?string $conversion): static
    {
        $this->conversion = $conversion;
        $this->inspectedModel = null;

        return $this;
    }

    /**
     * The disk new uploads are stored on. Null (default) = the collection's useDisk(), else media-library.disk_name.
     *
     * @throws InvalidArgumentException when the disk is not configured
     */
    public function disk(?string $disk): static
    {
        if ($disk !== null && config("filesystems.disks.{$disk}") === null) {
            throw new InvalidArgumentException("The disk [{$disk}] is not configured.");
        }

        $this->disk = $disk;

        return $this;
    }

    /**
     * Extension allow-list (e.g. ['pdf', 'jpg']): "mimes" + "extensions" rules and the input's accept.
     *
     * @param  list<string>  $types
     */
    public function types(array $types): static
    {
        $this->types = array_values(array_unique(array_map(fn ($type) => strtolower(ltrim((string) $type, '.')), $types)));

        return $this;
    }

    /**
     * Raster images only, checked by content type and extension (never SVG). Without types() it allows jpg, jpeg,
     * png, gif and webp; name 'avif' (or 'bmp') in types() to allow it too.
     */
    public function images(): static
    {
        $this->imagesOnly = true;

        if ($this->types === []) {
            $this->types = self::DEFAULT_IMAGE_TYPES;
        }

        return $this;
    }

    /**
     * Max size per file in kilobytes; capped by larsadmin.files.max_size and media-library.max_file_size (the
     * smallest of the three applies).
     *
     * @throws InvalidArgumentException when below 1
     */
    public function maxSize(int $kilobytes): static
    {
        if ($kilobytes < 1) {
            throw new InvalidArgumentException('maxSize() must be at least 1 kilobyte.');
        }

        $this->maxSize = $kilobytes;

        return $this;
    }

    /**
     * Treat the media as public (true: plain storage URLs) or private (false: signed / temporary URLs). Null
     * (default) = the disk's "visibility" setting. public(true) also applies the stricter list of blocked
     * extensions for public files; public(false) is only allowed on a disk that is not public (see private()).
     */
    public function public(bool $public = true): static
    {
        $this->public = $public;

        return $this;
    }

    /**
     * Shorthand for public(false): signed or temporary URLs. It changes the links only, not where the file lives,
     * so combining it with an upload disk whose visibility is 'public' is a configuration error (LogicException on
     * the first resolve): sensitive files need a disk that is not public.
     */
    public function private(): static
    {
        return $this->public(false);
    }

    /** Whether users may remove media in the form and on the detail page (default true). */
    public function removable(bool $removable = true): static
    {
        $this->removable = $removable;

        return $this;
    }

    /**
     * Remove the metadata (EXIF incl. GPS, XMP, IPTC, comments, text chunks …) of every image uploaded through the
     * panel before it is stored, without re-encoding: pixels, ICC profile and animation stay byte for byte, and a
     * non-trivial EXIF orientation is kept as a minimal EXIF. Conversions are made from the stripped original.
     * JPEG, PNG, WebP and GIF only: any other image (AVIF, HEIC, TIFF …) and a damaged image are validation errors
     * of that file, and nothing unstripped is ever stored. Other files (PDF …) and media added outside the panel are
     * not changed. Off by default (ADR-047).
     */
    public function stripMetadata(bool $strip = true): static
    {
        $this->stripMetadata = $strip;

        return $this;
    }

    /** @internal */
    public function stripsMetadata(): bool
    {
        return $this->stripMetadata;
    }

    /** @internal */
    public function collectionName(): string
    {
        return $this->collection;
    }

    /** @internal */
    public function previewConversion(): ?string
    {
        return $this->conversion;
    }

    /** @internal */
    public function isRemovable(): bool
    {
        return $this->removable;
    }

    /** @internal */
    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    /**
     * The limit of media in the field: 1, or the gallery's limit() capped by the collection's onlyKeepLatest(n)
     * once the declaration was inspected.
     *
     * @internal
     */
    public function maxFiles(): int
    {
        if (! $this->multiple) {
            return 1;
        }

        $declared = $this->declared?->collectionSizeLimit;

        return is_int($declared) && $declared > 0 ? min($this->maxFiles, $declared) : $this->maxFiles;
    }

    /**
     * @internal Field hook: a media field has no column to sort by.
     *
     * @throws LogicException
     */
    public function sortable(bool $sortable = true): static
    {
        if ($sortable) {
            throw new LogicException('A media field cannot be sortable: it has no column.');
        }

        return $this;
    }

    /** @internal Field hook: the field's entity, for route URLs and policy checks (ADR-035). */
    public function bindTo(Request $request, string $entity): void
    {
        $this->entity = $entity;
    }

    /**
     * @internal
     *
     * @return class-string<Entity>|null
     */
    public function entityClass(): ?string
    {
        return $this->entity;
    }

    /** @internal Field hook. */
    public function eagerLoads(): array
    {
        return ['media'];
    }

    /** Whether this field is shown on the update form of the record and editable there (Larsadmin::findField()). */
    protected function editableOn(Request $request, Model $record): bool
    {
        try {
            Larsadmin::findField($request, $this->entity::uriKey(), $record, $this->attribute, self::UPDATE);

            return true;
        } catch (HttpExceptionInterface) {
            return false;
        }
    }

    // ----------------------------------------------------------------------------------------------------------
    // The model's collection declaration (ADR-042)
    // ----------------------------------------------------------------------------------------------------------

    /**
     * The model's declaration of the field's collection (null when the model does not declare it), checked once
     * per model class: a gallery over a singleFile() collection and a preview conversion not registered for the
     * collection are configuration errors.
     *
     * @internal
     *
     * @throws LogicException
     */
    public function declaredCollection(Model $record): ?MediaCollection
    {
        $this->assertHasMedia($record);

        if ($this->inspectedModel === $record::class) {
            return $this->declared;
        }

        /** @var Model&HasMedia $record */
        $declared = $record->getMediaCollection($this->collection);

        if ($this->multiple && $declared?->singleFile) {
            throw new LogicException("The media collection [{$this->collection}] of [".$record::class.'] is singleFile(); use Media, not MediaGallery, for the field ['.$this->attribute.'].');
        }

        // A single Media field shows and replaces one file: over a collection that may hold more it would hide
        // media or delete them on save (CRM-03).
        if (! $this->multiple && $declared !== null && $declared->collectionSizeLimit !== 1) {
            throw new LogicException("The media collection [{$this->collection}] of [".$record::class.'] may hold several media; declare it singleFile() or use MediaGallery for the field ['.$this->attribute.'].');
        }

        $disk = $this->disk ?? ($declared !== null && $declared->diskName !== '' ? $declared->diskName : (string) config('media-library.disk_name', 'public'));

        if ($this->public === false && config("filesystems.disks.{$disk}.visibility") === 'public') {
            throw new LogicException("The media field [{$this->attribute}] is private(), but its upload disk [{$disk}] is public: the files would stay reachable by URL. Use a disk whose visibility is not 'public'.");
        }

        if ($this->conversion !== null && ! $this->conversionMayExist($record)) {
            throw new LogicException("The conversion [{$this->conversion}] of the media field [{$this->attribute}] is not registered for the collection [{$this->collection}] of [".$record::class.'].');
        }

        $this->declared = $declared;
        $this->inspectedModel = $record::class;

        return $declared;
    }

    /**
     * Whether the preview conversion is registered for the collection. Registrations often depend on the media
     * (`if ($media?->mime_type === ...)`), so they are read without a media and with a probe image of the
     * collection; when the two differ (or the probe throws) the registration depends on the media and is not
     * checked (CRM-05).
     */
    protected function conversionMayExist(Model $record): bool
    {
        $withoutMedia = $this->conversionsFor($record, null);

        if (in_array($this->conversion, $withoutMedia, true)) {
            return true;
        }

        try {
            /** @var Model&HasMedia $record */
            $class = $record->getMediaModel();
            /** @var MediaModel $probe */
            $probe = new $class;
            $probe->forceFill([
                'collection_name' => $this->collection, 'name' => 'probe', 'file_name' => 'probe.jpg',
                'mime_type' => 'image/jpeg', 'disk' => (string) config('media-library.disk_name', 'public'), 'size' => 0,
                'custom_properties' => [], 'generated_conversions' => [], 'manipulations' => [], 'responsive_images' => [],
            ]);
            $withMedia = $this->conversionsFor($record, $probe);
        } catch (Throwable) {
            return true;
        }

        sort($withoutMedia);
        sort($withMedia);

        return in_array($this->conversion, $withMedia, true) || $withMedia !== $withoutMedia;
    }

    /**
     * The names of the conversions registered for the field's collection, read from a copy of the model so the
     * record's own conversion list is not touched.
     *
     * @return list<string>
     */
    protected function conversionsFor(Model $record, ?MediaModel $media): array
    {
        /** @var Model&HasMedia $probe */
        $probe = clone $record;
        $probe->mediaConversions = [];
        $probe->registerAllMediaConversions($media);

        return collect($probe->mediaConversions)
            ->filter(fn (Conversion $conversion) => $conversion->shouldBePerformedOn($this->collection))
            ->map(fn (Conversion $conversion) => $conversion->getName())
            ->unique()
            ->values()
            ->all();
    }

    /** The record to read the declaration from: the given one, else a new model of the bound entity. */
    protected function modelFor(?Model $record): ?Model
    {
        return $record ?? ($this->entity !== null ? $this->entity::newModel() : null);
    }

    protected function assertHasMedia(Model $record): void
    {
        if (! $record instanceof HasMedia) {
            throw new LogicException('The model ['.$record::class.'] of the media field ['.$this->attribute.'] must implement '.HasMedia::class.'.');
        }
    }

    // ----------------------------------------------------------------------------------------------------------
    // Audit (Field::hasAuditValue() / auditValue(), ADR-035)
    // ----------------------------------------------------------------------------------------------------------

    /** @internal Field hook. */
    public function hasAuditValue(): bool
    {
        return true;
    }

    /**
     * @internal Field hook: the collection as the audit log records it, [{id, name}] in order, read fresh from the
     * database (no URLs or paths).
     *
     * @return list<array{id: int, name: string}>
     */
    public function auditValue(Model $record): mixed
    {
        if (! $record instanceof HasMedia || ! $record->exists) {
            return [];
        }

        return $record->media()
            ->where('collection_name', $this->collection)
            ->orderBy('order_column')
            ->orderBy($record->media()->getRelated()->getKeyName())
            ->get()
            ->map(fn (MediaModel $media) => ['id' => (int) $media->getKey(), 'name' => $this->displayName($media)])
            ->values()
            ->all();
    }

    // ----------------------------------------------------------------------------------------------------------
    // Resolve / serialize
    // ----------------------------------------------------------------------------------------------------------

    /** @internal Field hook. */
    public function resolve(Model $record): static
    {
        $this->declaredCollection($record);

        $items = $this->currentMedia($record);
        $ids = $items->map(fn (MediaModel $media) => $media->getKey())->values()->all();
        $request = request();

        $this->value = in_array($this->context, [self::CREATE, self::UPDATE], true)
            ? ['present' => 1, 'known' => $ids, 'keep' => $ids]
            : null;

        $this->displayedAs = $items->map(fn (MediaModel $media) => $this->displayName($media))->implode(', ');

        // The per-record rights the panel already computed for the row (Field::authorizedTo(), 1.6): no policy
        // calls here; false outside an entity (fail closed).
        $viewable = $this->entity !== null && $this->authorizedTo('view');
        $manageable = $this->context === self::DETAIL && $this->entity !== null && ! $this->readonly
            && $this->authorizedTo('update')
            && $this->editableOn($request, $record);

        $routeParams = $this->routeParameters($record);
        $expires = $this->urlExpiry();
        $expiring = false;

        if ($this->context === self::INDEX) {
            $items = $items->take(1);
        }

        $this->media = $items->map(function (MediaModel $media) use ($viewable, $manageable, $routeParams, $expires, &$expiring) {
            $isImage = FileSafety::isRasterImage($media->file_name, (string) $media->mime_type);
            $urls = $viewable && $routeParams !== null ? $this->urlsFor($media, $routeParams, $expires, $isImage) : null;
            $expiring = $expiring || ($urls['expiring'] ?? false);

            $item = [
                'id' => $media->getKey(),
                'name' => $this->displayName($media),
                'size' => (int) $media->size,
                'mime' => $media->mime_type,
                'isImage' => $isImage,
                'url' => $urls['url'] ?? null,
                'thumbnailUrl' => $urls['thumbnailUrl'] ?? null,
                // The preview conversion can be made but is not generated yet (queued): read from the loaded row,
                // no query. Files no image generator handles never get one, so they are not "pending".
                'previewPending' => $this->conversion !== null && ! $media->hasGeneratedConversion($this->conversion)
                    && ImageGeneratorFactory::forMedia($media) !== null,
            ];

            if ($this->context !== self::INDEX) {
                $item['downloadUrl'] = $urls['downloadUrl'] ?? null;
                $item['destroyUrl'] = $manageable && $routeParams !== null && $this->removable
                    ? route('larsadmin.ext.medialibrary.destroy', [...$routeParams, 'media' => $media->getKey()], false)
                    : null;
            }

            return $item;
        })->values()->all();

        $this->urlsExpireAt = $expiring ? $expires->toIso8601String() : null;
        $this->reorderUrl = $manageable && $routeParams !== null && $this->multiple
            ? route('larsadmin.ext.medialibrary.reorder', $routeParams, false)
            : null;

        if ($this->context === self::INDEX) {
            $this->meta['count'] = count($ids);
        }

        return $this;
    }

    /** @internal Field hook. */
    public function resolveDefault(Request $request): static
    {
        if (($model = $this->modelFor(null)) !== null) {
            $this->declaredCollection($model);
        }

        $this->value = ['present' => 1, 'known' => [], 'keep' => []];
        $this->media = [];

        return $this;
    }

    /** @internal Field hook; the serialized keys are internal (the Vue part ships with the PHP part). */
    public function jsonSerialize(): array
    {
        return [
            ...parent::jsonSerialize(),
            'multiple' => $this->multiple,
            'maxFiles' => $this->maxFiles(),
            'maxSize' => $this->maxSizeKilobytes() * 1024,
            'types' => $this->types,
            'accept' => $this->accept(),
            'removable' => $this->removable,
            'media' => $this->media,
            'urlsExpireAt' => $this->urlsExpireAt,
            'reorderUrl' => $this->reorderUrl,
        ];
    }

    /**
     * URLs per disk (ADR-043): public disk → storage URL; private S3-like → temporary URL (non-raster files as
     * octet-stream attachments); private local → the relative signed route.
     *
     * @internal
     *
     * @param  array<string, mixed>  $routeParams
     * @return array{url: ?string, thumbnailUrl: ?string, downloadUrl: ?string, expiring: bool}
     */
    public function urlsFor(MediaModel $media, array $routeParams, DateTimeInterface $expires, bool $isImage): array
    {
        $thumb = $this->conversion !== null && $media->hasGeneratedConversion($this->conversion) ? $this->conversion : '';

        if ($this->isPublicMedia($media)) {
            $url = $media->getUrl();

            return [
                'url' => $url,
                'downloadUrl' => $url,
                'thumbnailUrl' => $isImage || $thumb !== '' ? $media->getUrl($thumb) : null,
                'expiring' => false,
            ];
        }

        $disk = Storage::disk($media->disk);

        if (config("filesystems.disks.{$media->disk}.driver") !== 'local' && $disk->providesTemporaryUrls()) {
            $name = FileSafety::sanitizeName($media->file_name);
            $attachment = ['ResponseContentDisposition' => 'attachment; filename="'.FileSafety::asciiName($name).'"', 'ResponseContentType' => 'application/octet-stream'];
            $download = $media->getTemporaryUrl($expires, '', $attachment);

            return [
                'url' => $isImage ? $media->getTemporaryUrl($expires) : $download,
                'downloadUrl' => $download,
                'thumbnailUrl' => $isImage || $thumb !== '' ? $media->getTemporaryUrl($expires, $thumb) : null,
                'expiring' => true,
            ];
        }

        $sign = fn (array $query = []) => URL::temporarySignedRoute(
            'larsadmin.ext.medialibrary.show',
            $expires,
            [...$routeParams, 'media' => $media->getKey(), ...$query],
            absolute: false,
        );

        return [
            'url' => $isImage ? $sign() : $sign(['download' => 1]),
            'downloadUrl' => $sign(['download' => 1]),
            'thumbnailUrl' => $thumb !== '' ? $sign(['conversion' => $thumb]) : ($isImage ? $sign() : null),
            'expiring' => true,
        ];
    }

    /**
     * @internal
     *
     * @return array{entity: string, id: string|int, field: string}|null
     */
    public function routeParameters(Model $record): ?array
    {
        $key = $this->entity !== null ? $this->entity::pathSegment($record->getKey()) : null;

        return $key === null ? null : ['entity' => $this->entity::uriKey(), 'id' => $key, 'field' => $this->attribute];
    }

    /** @internal */
    public function isPublicMedia(MediaModel $media): bool
    {
        return $this->public ?? config("filesystems.disks.{$media->disk}.visibility") === 'public';
    }

    /**
     * Signed / temporary URLs expire at now + url_ttl rounded up to a quarter of the TTL (stable within a bucket).
     *
     * @internal
     */
    public function urlExpiry(): Carbon
    {
        $ttl = max(1, (int) config('larsadmin.files.url_ttl', 30)) * 60;
        $bucket = max(60, intdiv($ttl, 4));
        $at = now()->getTimestamp() + $ttl;

        return Carbon::createFromTimestamp((int) (ceil($at / $bucket) * $bucket));
    }

    /** @internal */
    public function displayName(MediaModel $media): string
    {
        $original = $media->getCustomProperty('original_name');

        if (is_string($original) && ($original = FileSafety::sanitizeName($original)) !== '') {
            return $original;
        }

        $name = FileSafety::sanitizeName((string) $media->name);
        $extension = pathinfo($media->file_name, PATHINFO_EXTENSION);

        if ($name === '') {
            return $this->name.($extension !== '' ? '.'.$extension : '');
        }

        return $extension !== '' && ! str_ends_with(mb_strtolower($name), '.'.mb_strtolower($extension)) ? $name.'.'.$extension : $name;
    }

    /**
     * @internal
     *
     * @return Collection<int, MediaModel>
     */
    public function currentMedia(Model $record): Collection
    {
        $this->assertHasMedia($record);

        /** @var Model&HasMedia $record */
        return $record->exists ? collect($record->getMedia($this->collection)->all())->values() : collect();
    }

    // ----------------------------------------------------------------------------------------------------------
    // Validation
    // ----------------------------------------------------------------------------------------------------------

    /** @internal Field hook. */
    public function rulesFor(string $context, ?Model $record = null): array
    {
        $model = $this->modelFor($record);
        $declared = $model !== null ? $this->declaredCollection($model) : null;

        [$fieldRules, $fileRules] = $this->splitRules($context);
        $attribute = $this->attribute;
        $max = $this->maxFiles();
        $limit = $this->multiple ? max($max, $this->currentMediaCount($record)) + $max : 1;
        $id = ['regex:/^[0-9]{1,18}$/'];

        return [
            $attribute => ['array:present,known,keep,upload', new MediaFieldState($this, $model, in_array('required', $fieldRules, true))],
            "{$attribute}.present" => ['sometimes', 'in:1'],
            "{$attribute}.known" => ['sometimes', 'array', 'max:'.$limit],
            "{$attribute}.known.*" => $id,
            "{$attribute}.keep" => ['sometimes', 'array', 'max:'.$limit],
            "{$attribute}.keep.*" => $id,
            "{$attribute}.upload" => ['sometimes', 'array', 'max:'.$max],
            "{$attribute}.upload.*" => [
                'file',
                'max:'.$this->maxSizeKilobytes(),
                $this->notBlocked($model),
                ...$this->typeRulesForFiles(),
                $this->notTooManyPixels(),
                ...($declared !== null && $declared->acceptsMimeTypes !== [] ? ['mimetypes:'.implode(',', $declared->acceptsMimeTypes)] : []),
                ...$fileRules,
                ...($this->stripMetadata ? [new StrippableImage($this)] : []),
            ],
        ];
    }

    /** @internal Field hook. */
    public function validationAttributeNames(): array
    {
        return [
            $this->attribute => $this->name,
            "{$this->attribute}.keep" => $this->name,
            "{$this->attribute}.upload" => $this->name,
            "{$this->attribute}.upload.*" => $this->name,
        ];
    }

    /** @internal Field hook. */
    public function isRequired(): bool
    {
        return $this->requiredOn($this->context === self::UPDATE ? self::UPDATE : self::CREATE);
    }

    /** @internal */
    public function requiredOn(string $context): bool
    {
        return in_array('required', $this->splitRules($context)[0], true);
    }

    /**
     * Field-level tokens (required, nullable, sometimes, array) and per-file rules.
     *
     * @return array{0: list<mixed>, 1: list<mixed>}
     */
    protected function splitRules(string $context): array
    {
        $rules = array_merge($this->rules, $context === self::CREATE ? $this->creationRules : $this->updateRules);
        $field = array_values(array_filter($rules, fn ($rule) => in_array($rule, ['required', 'nullable', 'sometimes', 'array'], true)));
        $file = array_values(array_filter($rules, fn ($rule) => ! in_array($rule, ['required', 'nullable', 'sometimes', 'array'], true)));

        return [$field, $file];
    }

    /** @return list<mixed> */
    protected function typeRulesForFiles(): array
    {
        $rules = [];

        if ($this->imagesOnly) {
            // By content type, from the allowed raster types; when types() names none, every raster type here and
            // the mimes / extensions rules below refuse the file (fail closed).
            $raster = array_intersect_key(self::RASTER_TYPES, array_flip($this->types)) ?: self::RASTER_TYPES;
            $rules[] = 'mimetypes:'.implode(',', array_values(array_unique($raster)));
            $rules[] = 'extensions:'.implode(',', array_keys($raster));
        }

        if ($this->types !== []) {
            $rules[] = 'mimes:'.implode(',', $this->types);
            $rules[] = 'extensions:'.implode(',', $this->types);
        }

        return $rules;
    }

    protected function notBlocked(?Model $record): Closure
    {
        $public = $this->uploadsArePublic($record);

        return function (string $attribute, mixed $value, Closure $fail) use ($public) {
            if ($value instanceof UploadedFile && ($extension = FileSafety::blockedExtension($value, $public)) !== null) {
                $fail(MedialibraryServiceProvider::LANG.'::messages.blocked')->translate(['extension' => $extension]);
            }
        };
    }

    /**
     * Images (any upload whose header getimagesize() reads; pixels are not decoded) may have at most
     * larsadmin.files.max_pixels pixels (default 40 MP), so conversions cannot exhaust memory (SECM-03).
     */
    protected function notTooManyPixels(): Closure
    {
        $max = static::maxPixels();

        return function (string $attribute, mixed $value, Closure $fail) use ($max) {
            $path = $value instanceof UploadedFile ? $value->getRealPath() : false;
            $size = $path !== false ? @getimagesize($path) : false;

            if (is_array($size) && (int) $size[0] * (int) $size[1] > $max) {
                $fail(MedialibraryServiceProvider::LANG.'::messages.too_many_pixels')->translate(['max' => round($max / 1_000_000, 1)]);
            }
        };
    }

    /** @internal */
    public static function maxPixels(): int
    {
        return max(1, (int) config('larsadmin.files.max_pixels', self::DEFAULT_MAX_PIXELS));
    }

    /** @internal */
    public function maxSizeKilobytes(): int
    {
        $panel = (int) config('larsadmin.files.max_size', 10240);
        $library = intdiv((int) config('media-library.max_file_size', PHP_INT_MAX), 1024);

        return max(1, min($this->maxSize ?? $panel, $panel, $library));
    }

    protected function accept(): ?string
    {
        if ($this->types === []) {
            return $this->imagesOnly ? 'image/*' : null;
        }

        return implode(',', array_map(fn ($type) => '.'.$type, $this->types));
    }

    /**
     * The disk new uploads go to: the field's disk(), else the collection's useDisk(), else media-library.disk_name.
     *
     * @internal
     */
    public function uploadDisk(?Model $record = null): string
    {
        if ($this->disk !== null) {
            return $this->disk;
        }

        $model = $this->modelFor($record);
        $declared = $model !== null ? $this->declaredCollection($model)?->diskName : null;

        return $declared !== null && $declared !== '' ? $declared : (string) config('media-library.disk_name', 'public');
    }

    protected function uploadsArePublic(?Model $record): bool
    {
        // Where the file physically lives decides the blocked list: private() never loosens it (SECM-01).
        return $this->public === true || config('filesystems.disks.'.$this->uploadDisk($record).'.visibility') === 'public';
    }

    protected function currentMediaCount(?Model $record): int
    {
        return $record instanceof HasMedia && $record->exists ? $record->getMedia($this->collection)->count() : 0;
    }

    // ----------------------------------------------------------------------------------------------------------
    // Fill
    // ----------------------------------------------------------------------------------------------------------

    /**
     * The plan of a submission against the record's current media. Uploads keep their index in the request, so
     * errors point at {attr}.upload.N.
     *
     * @internal
     *
     * @return array{keep: list<int>, remove: list<int>, uploads: array<int, UploadedFile>}|null
     */
    public function plan(Request $request, Model $record): ?array
    {
        $input = $request->input($this->attribute);

        if (! is_array($input)) {
            return null;
        }

        $this->declaredCollection($record);
        $current = $this->currentMedia($record)->map(fn (MediaModel $media) => (int) $media->getKey())->all();
        $ids = fn (mixed $list) => array_values(array_unique(array_map('intval', array_filter((array) $list, 'is_scalar'))));
        $uploads = array_filter((array) $request->file("{$this->attribute}.upload", []), fn ($file) => $file instanceof UploadedFile);

        $keep = array_values(array_intersect($ids($input['keep'] ?? []), $current));
        $known = array_values(array_intersect($ids($input['known'] ?? []), $current));
        $removed = $this->removable ? array_values(array_diff($known, $keep)) : [];
        $staying = [...$keep, ...array_values(array_diff($current, $keep, $removed))];

        if (! $this->multiple && $uploads !== []) {
            // A new file replaces what the form showed. A singleFile() collection would clear the rest itself, at
            // once and outside the journal, so there all current media go through the journal; an undeclared
            // collection keeps media the form did not know (added meanwhile).
            $remove = $this->declared?->collectionSizeLimit === 1 ? $current : $known;

            return ['keep' => array_values(array_diff($current, $remove)), 'remove' => $remove, 'uploads' => array_slice($uploads, 0, 1, true)];
        }

        return ['keep' => $staying, 'remove' => array_values(array_diff($current, $staying)), 'uploads' => $uploads];
    }

    /**
     * @internal Field hook. Outside a panel save (no journal): removed media files are deleted after the commit,
     * written files are not tracked.
     */
    public function fill(Request $request, Model $record): ?Closure
    {
        return $this->fillWith($request, $record, null);
    }

    /**
     * @internal Field hook. The panel's save (Entity::save()): files written in the deferred closure are
     * registered with $journal->created() (deleted when the save rolls back), files of removed media with
     * $journal->obsolete() (deleted after the commit). Media rows are written and deleted inside the save's
     * transaction.
     */
    public function fillInto(Request $request, Model $record, FileJournal $journal): ?Closure
    {
        return $this->fillWith($request, $record, $journal);
    }

    protected function fillWith(Request $request, Model $record, ?FileJournal $journal): ?Closure
    {
        if ($this->fillCallback) {
            return parent::fill($request, $record);
        }

        if (! is_array($request->input($this->attribute))) {
            return null;
        }

        return function (Model $record) use ($request, $journal) {
            /** @var Model&HasMedia $record */
            $record->unsetRelation('media');
            $plan = $this->plan($request, $record);

            if ($plan === null) {
                return;
            }

            $current = $this->currentMedia($record)->keyBy(fn (MediaModel $media) => (int) $media->getKey());

            // Removed first, so a singleFile() / onlyKeepLatest() collection finds nothing to clear (medialibrary
            // would delete those files at once, outside the journal).
            $this->removeMedia(collect($plan['remove'])->map(fn (int $id) => $current->get($id))->filter(), $record, $journal);

            $disk = $this->uploadDisk($record);
            $public = $this->uploadsArePublic($record);
            $added = [];

            foreach ($plan['uploads'] as $index => $file) {
                $media = $this->store($record, $file, $index, $disk, $public, $journal);

                foreach ($this->filesOf($media->refresh()) as [$fileDisk, $path]) {
                    $journal?->created($fileDisk, $path);
                }

                $added[] = (int) $media->getKey();
            }

            if ($this->multiple) {
                $class = $record->getMediaModel();
                $class::setNewOrder([...$plan['keep'], ...$added]);
            }

            $record->unsetRelation('media');
        };
    }

    /**
     * Add one upload to the collection under a random name, keeping the sanitized client name. A file the
     * collection refuses (acceptsFile(), acceptsMimeTypes(), …) is a validation error of that upload; medialibrary
     * checks before it writes, and the save's journal discards the files written so far (ADR-042).
     *
     * @throws ValidationException
     */
    protected function store(Model $record, UploadedFile $file, int|string $index, string $disk, bool $public, ?FileJournal $journal = null): MediaModel
    {
        /** @var Model&HasMedia $record */
        $name = FileSafety::sanitizeName($file->getClientOriginalName());
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $fileName = Str::random(40).'.'.FileSafety::storedExtension($file, $public);

        try {
            return $record->addMedia($this->uploadSource($file))
                ->usingName($stem !== '' ? $stem : $this->name)
                ->usingFileName($fileName)
                ->withCustomProperties(['original_name' => $name])
                ->toMediaCollection($this->collection, $disk);
        } catch (Throwable $e) {
            $this->discardFailedAdd($record, $fileName, $journal);

            throw $this->uploadError($e, $index);
        } finally {
            static::discardStripped($file);
        }
    }

    /**
     * After addMedia() failed, medialibrary may already have written the row, the original and some conversions (a
     * failing conversion, responsive images or event listener). In a panel save their files are journaled as
     * created, so the rollback deletes them; outside it they and the row are deleted at once (SECM-02).
     */
    protected function discardFailedAdd(Model $record, string $fileName, ?FileJournal $journal): void
    {
        /** @var Model&HasMedia $record */
        $media = $record->media()->where('collection_name', $this->collection)->where('file_name', $fileName)->first();

        if ($media === null) {
            return;
        }

        $files = $this->filesOf($media);

        if ($journal !== null) {
            foreach ($files as [$disk, $path]) {
                $journal->created($disk, $path);
            }

            return;
        }

        $media->deleteQuietly();

        foreach ($files as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }
    }

    /** The exception of a failed upload: a validation error at that file when the user can act on it. */
    protected function uploadError(Throwable $e, int|string $index): Throwable
    {
        $key = match (true) {
            $e instanceof FileCannotBeAdded => 'not_accepted',
            $e instanceof MetadataStripFailed => static::stripFailureKey($e),
            default => null,
        };

        return $key === null ? $e : ValidationException::withMessages([
            "{$this->attribute}.upload.{$index}" => __(MedialibraryServiceProvider::LANG.'::messages.'.$key, ['attribute' => $this->name]),
        ]);
    }

    /**
     * The file handed to medialibrary for an upload: the upload itself, or with stripMetadata() the stripped copy of
     * an image (cached by the validation rule, else stripped now, e.g. fill() outside the panel). The single place
     * where the stored bytes are chosen (stored name and original_name always come from the upload).
     *
     * @throws MetadataStripFailed
     */
    protected function uploadSource(UploadedFile $file): UploadedFile|string
    {
        return $this->stripMetadata ? ($this->strippedCopy($file) ?? $file) : $file;
    }

    // ----------------------------------------------------------------------------------------------------------
    // Metadata stripping (stripMetadata(), ADR-047)
    // ----------------------------------------------------------------------------------------------------------

    /**
     * The stripped copy of an upload, written once per request into its own temporary directory; null when the
     * upload is not an image by content (finfo), so it is stored as it is.
     *
     * @internal
     *
     * @throws MetadataStripFailed when the image type is not supported or the file cannot be stripped
     */
    public function strippedCopy(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_file($path)) {
            throw MetadataStripFailed::failed('the upload cannot be read.');
        }

        $key = static::strippedKey($file);

        if (isset(static::$strippedUploads[$key]) && is_file(static::$strippedUploads[$key][2])) {
            return static::$strippedUploads[$key][2];
        }

        try {
            $mime = (string) MimeTypes::getDefault()->guessMimeType($path);
        } catch (Throwable $e) {
            throw MetadataStripFailed::failed('the content type cannot be detected.', $e);
        }

        if (! MetadataStripper::applies($mime)) {
            return null;
        }

        if (! MetadataStripper::supports($mime)) {
            throw MetadataStripFailed::unsupported($mime);
        }

        static::discardStripped($file);
        $workspace = new StripWorkspace;
        $directory = null;

        try {
            // One private directory per upload (0700, SECM-04); it throws MetadataStripFailed itself.
            $directory = $workspace->create();
            $stripped = (new MetadataStripper)->strip($path, $mime, $directory);
        } catch (Throwable $e) {
            if ($directory !== null) {
                $workspace->delete($directory);
            }

            throw $e instanceof MetadataStripFailed ? $e : MetadataStripFailed::failed('the temporary directory cannot be created.', $e);
        }

        static::$strippedUploads[$key] = [$workspace, $directory, $stripped];
        static::discardStrippedOnTerminate();

        return $stripped;
    }

    /**
     * Delete the stripped copy of an upload (after addMedia()), or every copy of the request (on terminate).
     *
     * @internal
     */
    public static function discardStripped(?UploadedFile $file = null): void
    {
        $keys = $file === null ? array_keys(static::$strippedUploads) : [static::strippedKey($file)];

        foreach ($keys as $key) {
            if (isset(static::$strippedUploads[$key])) {
                static::$strippedUploads[$key][0]->delete(static::$strippedUploads[$key][1]);
                unset(static::$strippedUploads[$key]);
            }
        }
    }

    /**
     * The message key of a strip failure; the message names no parser details.
     *
     * @internal
     */
    public static function stripFailureKey(MetadataStripFailed $e): string
    {
        return $e->isUnsupported() ? 'metadata_unsupported' : 'metadata_failed';
    }

    /** The cache key of an upload: its path, size and mtime (a temp path reused later is another file). */
    protected static function strippedKey(UploadedFile $file): string
    {
        $path = (string) $file->getRealPath();

        return $path.'|'.(@filesize($path) ?: 0).'|'.(@filemtime($path) ?: 0);
    }

    /** Copies left after the response (a later file failed, the save threw) are deleted when the app terminates. */
    protected static function discardStrippedOnTerminate(): void
    {
        $app = app();

        if (static::$strippedCleanup?->get() === $app) {
            return;
        }

        static::$strippedCleanup = WeakReference::create($app);
        $app->terminating(fn () => static::discardStripped());
    }

    /**
     * Delete media rows now (inside the caller's transaction, so a rollback restores them) and their files after
     * the commit: through the journal in a panel save, else an after-commit callback.
     *
     * @internal
     *
     * @param  Collection<int, MediaModel>  $media
     */
    public function removeMedia(Collection $media, Model $record, ?FileJournal $journal = null): void
    {
        $files = [];

        foreach ($media as $item) {
            array_push($files, ...$this->filesOf($item));
            // Quietly: medialibrary's observer would delete the files immediately, before the commit.
            $item->deleteQuietly();
        }

        if ($files === []) {
            return;
        }

        if ($journal !== null) {
            foreach ($files as [$disk, $path]) {
                $journal->obsolete($disk, $path);
            }

            return;
        }

        $record->getConnection()->afterCommit(function () use ($files) {
            foreach ($files as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
        });
    }

    /**
     * The known files of a media item (ADR-044): the original, its generated conversions and its responsive
     * images (resolved through the media's path generator). Directories are never included.
     *
     * @internal
     *
     * @return list<array{0: string, 1: string}>
     */
    public function filesOf(MediaModel $media): array
    {
        $conversionsDisk = $media->conversions_disk ?: $media->disk;
        $files = [[$media->disk, $media->getPathRelativeToRoot()]];

        foreach ($media->getGeneratedConversions()->filter()->keys() as $conversion) {
            $files[] = [$conversionsDisk, $media->getPathRelativeToRoot($conversion)];
        }

        $responsive = (array) ($media->responsive_images ?? []);

        if ($responsive !== []) {
            $directory = PathGeneratorFactory::create($media)->getPathForResponsiveImages($media);

            foreach ($responsive as $images) {
                foreach ((array) ($images['urls'] ?? []) as $file) {
                    if (is_string($file) && $file !== '' && ! str_contains($file, '/') && ! str_contains($file, '\\')) {
                        $files[] = [$conversionsDisk, $directory.$file];
                    }
                }
            }
        }

        return array_values(array_unique($files, SORT_REGULAR));
    }
}

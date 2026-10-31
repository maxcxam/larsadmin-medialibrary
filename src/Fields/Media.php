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
use InvalidArgumentException;
use LogicException;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Files\FileJournal;
use Maxcxam\Larsadmin\Files\FileSafety;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\LarsadminMedialibrary\MedialibraryServiceProvider;
use Maxcxam\LarsadminMedialibrary\Rules\MediaFieldState;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as MediaModel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * One file or image of a spatie/laravel-medialibrary collection.
 *
 * Wire format (form → server, multipart): {attr}[present]=1, {attr}[known][]=id (media the form loaded),
 * {attr}[keep][]=id (in display order), {attr}[upload][]=binary. Only media in "known" can be removed, so a stale
 * form never deletes media added meanwhile. Media are written in the deferred fill() closure (the record needs a
 * key first); removals run after the commit.
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

    /** @var class-string<Entity>|null */
    protected ?string $entity = null;

    /** @var list<array<string, mixed>> */
    protected array $media = [];

    protected ?string $urlsExpireAt = null;

    protected ?string $reorderUrl = null;

    public function __construct(string $name, ?string $attribute = null, ?string $collection = null)
    {
        parent::__construct($name, $attribute);

        $this->collection = $collection ?? $this->attribute;
    }

    /** The media collection; defaults to the attribute. */
    public function collection(string $collection): static
    {
        $this->collection = $collection;

        return $this;
    }

    public function collectionName(): string
    {
        return $this->collection;
    }

    /** The conversion used for previews (e.g. 'thumb'); the original is shown until it is generated. */
    public function conversion(?string $conversion): static
    {
        $this->conversion = $conversion;

        return $this;
    }

    public function previewConversion(): ?string
    {
        return $this->conversion;
    }

    /** The disk new uploads are stored on; null = the collection's / media-library default. */
    public function disk(?string $disk): static
    {
        if ($disk !== null && config("filesystems.disks.{$disk}") === null) {
            throw new InvalidArgumentException("The disk [{$disk}] is not configured.");
        }

        $this->disk = $disk;

        return $this;
    }

    /** Extension allow-list (e.g. ['pdf', 'jpg']): mimes + extensions rules and the input's accept. */
    public function types(array $types): static
    {
        $this->types = array_values(array_unique(array_map(fn ($type) => strtolower(ltrim((string) $type, '.')), $types)));

        return $this;
    }

    /** Raster images only (the "image" rule rejects SVG). */
    public function images(): static
    {
        $this->imagesOnly = true;

        if ($this->types === []) {
            $this->types = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
        }

        return $this;
    }

    /** Max size per file in kilobytes. */
    public function maxSize(int $kilobytes): static
    {
        if ($kilobytes < 1) {
            throw new InvalidArgumentException('maxSize() must be at least 1 kilobyte.');
        }

        $this->maxSize = $kilobytes;

        return $this;
    }

    public function public(bool $public = true): static
    {
        $this->public = $public;

        return $this;
    }

    public function private(): static
    {
        return $this->public(false);
    }

    public function removable(bool $removable = true): static
    {
        $this->removable = $removable;

        return $this;
    }

    public function isRemovable(): bool
    {
        return $this->removable;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    public function maxFiles(): int
    {
        return $this->multiple ? $this->maxFiles : 1;
    }

    public function sortable(bool $sortable = true): static
    {
        if ($sortable) {
            throw new LogicException('A media field cannot be sortable: it has no column.');
        }

        return $this;
    }

    /** The field's entity, for route URLs and policy checks (bindTo() is stable since 1.5, ADR-035). */
    public function bindTo(Request $request, string $entity): void
    {
        $this->entity = $entity;
    }

    /** @return class-string<Entity>|null */
    public function entityClass(): ?string
    {
        return $this->entity;
    }

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
    // Audit (Field::hasAuditValue() / auditValue(), ADR-035)
    // ----------------------------------------------------------------------------------------------------------

    public function hasAuditValue(): bool
    {
        return true;
    }

    /**
     * The collection as the audit log records it: [{id, name}] in order, read fresh from the database.
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

    public function resolve(Model $record): static
    {
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

    public function resolveDefault(Request $request): static
    {
        $this->value = ['present' => 1, 'known' => [], 'keep' => []];
        $this->media = [];

        return $this;
    }

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

    /** @return array{entity: string, id: string|int, field: string}|null */
    public function routeParameters(Model $record): ?array
    {
        $key = $this->entity !== null ? $this->entity::pathSegment($record->getKey()) : null;

        return $key === null ? null : ['entity' => $this->entity::uriKey(), 'id' => $key, 'field' => $this->attribute];
    }

    public function isPublicMedia(MediaModel $media): bool
    {
        return $this->public ?? config("filesystems.disks.{$media->disk}.visibility") === 'public';
    }

    /** Signed / temporary URLs expire at now + url_ttl rounded up to a quarter of the TTL (stable within a bucket). */
    public function urlExpiry(): Carbon
    {
        $ttl = max(1, (int) config('larsadmin.files.url_ttl', 30)) * 60;
        $bucket = max(60, intdiv($ttl, 4));
        $at = now()->getTimestamp() + $ttl;

        return Carbon::createFromTimestamp((int) (ceil($at / $bucket) * $bucket));
    }

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

    /** @return Collection<int, MediaModel> */
    public function currentMedia(Model $record): Collection
    {
        if (! $record instanceof HasMedia) {
            throw new LogicException('The model ['.$record::class.'] of the media field ['.$this->attribute.'] must implement '.HasMedia::class.'.');
        }

        return $record->exists ? collect($record->getMedia($this->collection)->all())->values() : collect();
    }

    // ----------------------------------------------------------------------------------------------------------
    // Validation
    // ----------------------------------------------------------------------------------------------------------

    public function rulesFor(string $context, ?Model $record = null): array
    {
        [$fieldRules, $fileRules] = $this->splitRules($context);
        $attribute = $this->attribute;
        $max = $this->maxFiles();
        $limit = $this->multiple ? max($max, $this->currentMediaCount($record)) + $max : 1;
        $id = ['regex:/^[0-9]{1,18}$/'];

        return [
            $attribute => ['array:present,known,keep,upload', new MediaFieldState($this, $record, in_array('required', $fieldRules, true))],
            "{$attribute}.present" => ['sometimes', 'in:1'],
            "{$attribute}.known" => ['sometimes', 'array', 'max:'.$limit],
            "{$attribute}.known.*" => $id,
            "{$attribute}.keep" => ['sometimes', 'array', 'max:'.$limit],
            "{$attribute}.keep.*" => $id,
            "{$attribute}.upload" => ['sometimes', 'array', 'max:'.$max],
            "{$attribute}.upload.*" => [
                'file',
                'max:'.$this->maxSizeKilobytes(),
                $this->notBlocked(),
                ...$this->typeRulesForFiles(),
                ...$fileRules,
            ],
        ];
    }

    public function validationAttributeNames(): array
    {
        return [
            $this->attribute => $this->name,
            "{$this->attribute}.keep" => $this->name,
            "{$this->attribute}.upload" => $this->name,
            "{$this->attribute}.upload.*" => $this->name,
        ];
    }

    public function isRequired(): bool
    {
        return $this->requiredOn($this->context === self::UPDATE ? self::UPDATE : self::CREATE);
    }

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
        $rules = $this->imagesOnly ? ['image'] : [];

        if ($this->types !== []) {
            $rules[] = 'mimes:'.implode(',', $this->types);
            $rules[] = 'extensions:'.implode(',', $this->types);
        }

        return $rules;
    }

    protected function notBlocked(): Closure
    {
        $public = $this->uploadsArePublic();

        return function (string $attribute, mixed $value, Closure $fail) use ($public) {
            if ($value instanceof UploadedFile && ($extension = FileSafety::blockedExtension($value, $public)) !== null) {
                $fail(MedialibraryServiceProvider::LANG.'::messages.blocked')->translate(['extension' => $extension]);
            }
        };
    }

    public function maxSizeKilobytes(): int
    {
        $field = $this->maxSize ?? (int) config('larsadmin.files.max_size', 10240);
        $library = intdiv((int) config('media-library.max_file_size', PHP_INT_MAX), 1024);

        return max(1, min($field, $library));
    }

    protected function accept(): ?string
    {
        if ($this->types === []) {
            return $this->imagesOnly ? 'image/*' : null;
        }

        return implode(',', array_map(fn ($type) => '.'.$type, $this->types));
    }

    public function uploadDisk(): string
    {
        return $this->disk ?? (string) config('media-library.disk_name', 'public');
    }

    protected function uploadsArePublic(): bool
    {
        return $this->public ?? config('filesystems.disks.'.$this->uploadDisk().'.visibility') === 'public';
    }

    protected function currentMediaCount(?Model $record): int
    {
        return $record instanceof HasMedia && $record->exists ? $record->getMedia($this->collection)->count() : 0;
    }

    // ----------------------------------------------------------------------------------------------------------
    // Fill
    // ----------------------------------------------------------------------------------------------------------

    /**
     * The plan of a submission against the record's current media.
     *
     * @return array{keep: list<int>, remove: list<int>, uploads: list<UploadedFile>}
     */
    public function plan(Request $request, Model $record): ?array
    {
        $input = $request->input($this->attribute);

        if (! is_array($input)) {
            return null;
        }

        $current = $this->currentMedia($record)->map(fn (MediaModel $media) => (int) $media->getKey())->all();
        $ids = fn (mixed $list) => array_values(array_unique(array_map('intval', array_filter((array) $list, 'is_scalar'))));
        $uploads = array_values(array_filter((array) $request->file("{$this->attribute}.upload", []), fn ($file) => $file instanceof UploadedFile));

        $keep = array_values(array_intersect($ids($input['keep'] ?? []), $current));
        $known = array_values(array_intersect($ids($input['known'] ?? []), $current));
        $removed = $this->removable ? array_values(array_diff($known, $keep)) : [];
        $staying = [...$keep, ...array_values(array_diff($current, $keep, $removed))];

        if (! $this->multiple && $uploads !== []) {
            return ['keep' => [], 'remove' => $current, 'uploads' => [$uploads[0]]];
        }

        if (! $this->multiple) {
            $staying = array_slice($staying, 0, 1);
        }

        return ['keep' => $staying, 'remove' => array_values(array_diff($current, $staying)), 'uploads' => $uploads];
    }

    /**
     * Outside a panel save (no journal): removed media files are deleted after the commit, written files are not
     * tracked.
     */
    public function fill(Request $request, Model $record): ?Closure
    {
        return $this->fillWith($request, $record, null);
    }

    /**
     * The panel's save (Entity::save()): files written in the deferred closure are registered with
     * $journal->created() (deleted when the save rolls back), files of removed media with $journal->obsolete()
     * (deleted after the commit). Media rows are written and deleted inside the save's transaction.
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

            // Removed first, so a singleFile() collection finds nothing to clear (it would delete files at once).
            $this->removeMedia(collect($plan['remove'])->map(fn (int $id) => $current->get($id))->filter(), $record, $journal);

            $public = $this->uploadsArePublic();
            $added = [];

            foreach ($plan['uploads'] as $file) {
                $name = FileSafety::sanitizeName($file->getClientOriginalName());

                $media = $record->addMedia($file)
                    ->usingName(pathinfo($name, PATHINFO_FILENAME) !== '' ? pathinfo($name, PATHINFO_FILENAME) : $this->name)
                    ->usingFileName(Str::random(40).'.'.FileSafety::storedExtension($file, $public))
                    ->withCustomProperties(['original_name' => $name])
                    ->toMediaCollection($this->collection, $this->uploadDisk());

                foreach ($this->filesOf($media->refresh()) as [$disk, $path]) {
                    $journal?->created($disk, $path);
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
     * Delete media rows now (inside the caller's transaction, so a rollback restores them) and their files after
     * the commit: through the journal in a panel save, else an after-commit callback.
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
     * The files of a media item: the original and its generated conversions.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function filesOf(MediaModel $media): array
    {
        $files = [[$media->disk, $media->getPathRelativeToRoot()]];

        foreach ($media->getGeneratedConversions()->filter()->keys() as $conversion) {
            $files[] = [$media->conversions_disk ?: $media->disk, $media->getPathRelativeToRoot($conversion)];
        }

        return $files;
    }
}

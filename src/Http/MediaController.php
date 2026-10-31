<?php

namespace Maxcxam\LarsadminMedialibrary\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\MedialibraryServiceProvider;
use Maxcxam\LarsadminMedialibrary\Support\FileSafety;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as MediaModel;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Extension routes ({path}/ext/medialibrary/...). The panel gate is applied by Larsadmin::routes(); everything
 * else here: the record through Larsadmin::findRecord() (viewAny → detail scope → policy), the field through
 * Larsadmin::findField() (visibility, read-only), then the media's membership in the field's collection.
 * Changes run through Larsadmin::audited(), so they are in the audit log like form saves.
 */
class MediaController
{
    public function show(Request $request, string $entity, string $id, string $field, string $media): StreamedResponse
    {
        $record = Larsadmin::findRecord($request, $entity, $id, 'view');
        $field = $this->visibleField($request, $entity, $record, $field);
        $item = $this->media($field, $record, $media);

        $conversion = (string) $request->query('conversion', '');

        if ($conversion !== '' && ($conversion !== $field->previewConversion() || ! $item->hasGeneratedConversion($conversion))) {
            abort(404);
        }

        $path = $item->getPathRelativeToRoot($conversion);
        $disk = Storage::disk($conversion === '' ? $item->disk : $item->conversions_disk);

        abort_unless($disk->exists($path), 404);

        $name = $field->displayName($item);
        $inline = ! $request->boolean('download') && FileSafety::isRasterFile($path) && FileSafety::isRasterMime($disk->mimeType($path) ?: null);
        $ttl = max(1, (int) config('larsadmin.files.url_ttl', 30));

        return $disk->response($path, $name, [
            'Content-Type' => $inline ? FileSafety::RASTER[strtolower(pathinfo($path, PATHINFO_EXTENSION))] : 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $name,
                FileSafety::asciiName($name),
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => FileSafety::CONTENT_SECURITY_POLICY,
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cache-Control' => $inline ? 'private, max-age='.($ttl * 60) : 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function reorder(Request $request, string $entity, string $id, string $field): JsonResponse
    {
        $record = Larsadmin::findRecord($request, $entity, $id, 'update');
        $field = $this->mediaField(Larsadmin::findField($request, $entity, $record, $field, Field::UPDATE), $record);

        abort_unless($field->isMultiple(), 404);

        $current = $field->currentMedia($record)->map(fn (MediaModel $media) => (int) $media->getKey())->all();
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:'.max(1, count($current))],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $ids = array_map('intval', $data['ids']);

        // The new order must be a permutation of the current media: no foreign ids, nothing missing.
        if (count($ids) !== count($current) || array_diff($ids, $current) !== []) {
            throw ValidationException::withMessages(['ids' => __(MedialibraryServiceProvider::LANG.'::messages.stale')]);
        }

        Larsadmin::audited($request, $entity, $record, function (Model $record) use ($ids) {
            /** @var Model&HasMedia $record */
            $class = $record->getMediaModel();
            $class::setNewOrder($ids);
        });

        return response()->json(['order' => $ids]);
    }

    public function destroy(Request $request, string $entity, string $id, string $field, string $media): JsonResponse
    {
        $record = Larsadmin::findRecord($request, $entity, $id, 'update');
        $field = $this->mediaField(Larsadmin::findField($request, $entity, $record, $field, Field::UPDATE), $record);
        $item = $this->media($field, $record, $media);

        abort_unless($field->isRemovable(), 403);

        // The row is deleted in the audited transaction; its files after the commit. The "required: keep one" check
        // counts inside the same transaction with the collection's rows locked, so two parallel deletes cannot
        // empty a required collection (SECX-09).
        Larsadmin::audited($request, $entity, $record, function (Model $record) use ($field, $item) {
            /** @var Model&HasMedia $record */
            $remaining = $record->media()
                ->where('collection_name', $field->collectionName())
                ->lockForUpdate()
                ->pluck($item->getKeyName());

            abort_unless($remaining->contains($item->getKey()), 404);

            if ($field->requiredOn(Field::UPDATE) && $remaining->count() <= 1) {
                throw ValidationException::withMessages([$field->attribute => __(MedialibraryServiceProvider::LANG.'::messages.required', ['attribute' => $field->name])]);
            }

            $field->removeMedia(collect([$item]), $record);
        });

        return response()->json(['deleted' => (int) $item->getKey()]);
    }

    /**
     * The media field shown on the detail page, the index or (for users who may update the record) the update
     * form, or 404. Forms show previews too, so the update form counts.
     */
    protected function visibleField(Request $request, string $entity, Model $record, string $attribute): Media
    {
        foreach ([Field::DETAIL, Field::INDEX, Field::UPDATE] as $context) {
            try {
                $field = Larsadmin::findField($request, $entity, $record, $attribute, $context);
            } catch (HttpExceptionInterface) {
                continue;
            }

            // SPIKE GAP (A4, deferred to 1.6): fields get no per-record rights; the form context needs "update".
            if ($context === Field::UPDATE && ! Larsadmin::entityForKey($entity)::make($record)->authorizedToUpdate($request)) {
                continue;
            }

            return $this->mediaField($field, $record);
        }

        abort(404);
    }

    protected function mediaField(Field $field, Model $record): Media
    {
        abort_unless($field instanceof Media && $record instanceof HasMedia, 404);

        return $field;
    }

    protected function media(Media $field, Model $record, string $media): MediaModel
    {
        $item = $field->currentMedia($record)->first(fn (MediaModel $item) => (string) $item->getKey() === $media);

        abort_if($item === null, 404);

        return $item;
    }
}

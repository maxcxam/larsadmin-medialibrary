<?php

namespace Maxcxam\LarsadminMedialibrary\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\MedialibraryServiceProvider;
use Maxcxam\LarsadminMedialibrary\Support\MetadataStripFailed;

/**
 * The last rule of {attr}.upload.* of a field with stripMetadata() (ADR-047): writes the stripped copy of an image
 * upload now (the field caches it for the save), so an image whose metadata cannot be removed (unsupported type,
 * damaged file) is a validation error of that file and nothing is stored. Skipped when an earlier rule of the file
 * already failed; non-image files pass untouched.
 *
 * @internal
 */
class StrippableImage implements ValidationRule, ValidatorAwareRule
{
    protected ?Validator $validator = null;

    public function __construct(protected Media $field) {}

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || $this->validator?->errors()->has($attribute)) {
            return;
        }

        try {
            $this->field->strippedCopy($value);
        } catch (MetadataStripFailed $e) {
            $fail(MedialibraryServiceProvider::LANG.'::messages.'.Media::stripFailureKey($e))->translate();
        }
    }
}

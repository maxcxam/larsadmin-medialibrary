<?php

namespace Maxcxam\LarsadminMedialibrary\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\MedialibraryServiceProvider;

/**
 * Implicit rule on {attr}: runs when the key is absent. Required → the final set is not empty; multiple → the
 * final count is at most maxFiles. The final set is computed exactly as fill() does (Media::plan()).
 *
 * @internal
 */
class MediaFieldState implements DataAwareRule, ValidationRule
{
    public bool $implicit = true;

    /** @var array<string, mixed> */
    protected array $data = [];

    public function __construct(
        protected Media $field,
        protected ?Model $record,
        protected bool $required,
    ) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && ! is_array($value)) {
            return; // the "array" rule reports it
        }

        // No record and no bound entity (a field used outside a panel without a record): nothing is stored yet.
        $record = $this->record;
        $current = $record !== null ? $this->field->currentMedia($record)->count() : 0;

        if ($value === null) {
            $final = $current;
        } else {
            $request = Request::create('/', 'POST', [$attribute => array_diff_key($value, ['upload' => true])]);
            $uploads = array_filter((array) ($value['upload'] ?? []), fn ($file) => $file instanceof UploadedFile);
            $request->files->set($attribute, ['upload' => array_values($uploads)]);
            $plan = $record !== null ? $this->field->plan($request, $record) : null;
            $final = $plan !== null ? count($plan['keep']) + count($plan['uploads']) : ($this->field->isMultiple() ? count($uploads) : min(1, count($uploads)));
        }

        if ($this->required && $final === 0) {
            $fail(MedialibraryServiceProvider::LANG.'::messages.required')->translate();
        }

        if ($this->field->isMultiple() && $final > $this->field->maxFiles()) {
            $fail(MedialibraryServiceProvider::LANG.'::messages.too_many')->translate(['max' => $this->field->maxFiles()]);
        }
    }
}

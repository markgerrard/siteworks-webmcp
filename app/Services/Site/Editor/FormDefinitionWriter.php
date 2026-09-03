<?php

namespace App\Services\Site\Editor;

use App\Models\GeneratedPage;
use App\Models\Site\PageRevision;
use App\Services\Site\FormFieldsWriter;
use App\Support\FormFieldDefinition;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FormDefinitionWriter
{
    public function __construct(private readonly FormFieldsWriter $fields) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function write(
        GeneratedPage $page,
        int $sectionIndex,
        array $input,
        int $expectedBaseRevisionId,
        ?int $userId,
        bool $draftOnly, // REQUIRED on the editor-namespace writer: operations MUST pass true, the legacy route false
    ): PageRevision {
        $content = $this->currentEditableContent($page);
        $sectionData = $content['sections'][$sectionIndex] ?? null;

        if (! is_array($sectionData)) {
            throw ValidationException::withMessages([
                'stored_index' => 'Section index out of range.',
            ]);
        }

        $sectionType = (string) ($sectionData['type'] ?? '');

        if (! in_array($sectionType, ['contact_form', 'lead_form'], true)) {
            throw ValidationException::withMessages([
                'stored_index' => 'Section is not a form.',
            ]);
        }

        return $this->writeValidated($page, $sectionIndex, $this->validate($input, $sectionType), $expectedBaseRevisionId, $userId, $draftOnly);
    }

    /**
     * Non-throwing validation of a form definition. Empty array means valid.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, list<string>>
     */
    public function check(array $input, string $sectionType): array
    {
        try {
            $this->prepare($input, $sectionType);

            return [];
        } catch (ValidationException $exception) {
            return $exception->errors();
        }
    }

    /**
     * Validation + normalisation only (no write). The legacy controller runs this BEFORE its
     * revision-base header check so an invalid body still 422s ahead of a 409, byte-for-byte as before.
     *
     * @param  array<string, mixed>  $input
     * @return array{fields: list<array<string, mixed>>, extras: array<string, string|null>}
     */
    public function validate(array $input, string $sectionType): array
    {
        return $this->prepare($input, $sectionType);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{fields: list<array<string, mixed>>, extras: array<string, string|null>}
     */
    private function prepare(array $input, string $sectionType): array
    {
        $validated = Validator::make($input, [
            'title' => ['nullable', 'string', 'max:60'],
            'submit_label' => ['nullable', 'string', 'max:32'],
            'fields' => ['present', 'array'],
            'fields.*.label' => ['nullable', 'string', 'max:'.FormFieldDefinition::MAX_LABEL],
            'fields.*.type' => ['nullable', 'string', Rule::in(FormFieldDefinition::TYPES)],
            'fields.*.required' => ['nullable', 'boolean'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:100'],
            'fields.*.options' => ['nullable', 'array', 'max:'.FormFieldDefinition::MAX_OPTIONS],
            'fields.*.options.*' => ['nullable', 'string'],
            'fields.*.name' => ['nullable', 'string', 'max:60'],
        ])->validate();

        $existingKeys = [];
        $fields = [];

        foreach ($validated['fields'] as $index => $field) {
            $normalised = FormFieldDefinition::normalise($field, $existingKeys);

            if (in_array($normalised['name'], FormFieldDefinition::RESERVED_KEYS, true)) {
                throw ValidationException::withMessages([
                    "fields.{$index}.label" => 'This key is reserved.',
                ]);
            }

            $existingKeys[] = $normalised['name'];
            $fields[] = $normalised;
        }

        $cap = FormFieldDefinition::capFor($sectionType);

        if (FormFieldDefinition::countableFieldTotal($sectionType, $fields) > $cap) {
            throw ValidationException::withMessages([
                'fields' => "This form allows at most {$cap} custom fields.",
            ]);
        }

        $extras = [];
        if (array_key_exists('title', $validated)) {
            $extras['title'] = filled($validated['title'] ?? null)
                ? (string) $validated['title']
                : null;
        }
        if (array_key_exists('submit_label', $validated)) {
            $extras['submit_label'] = filled($validated['submit_label'] ?? null)
                ? (string) $validated['submit_label']
                : null;
        }

        return ['fields' => $fields, 'extras' => $extras];
    }

    /**
     * @param  array{fields: list<array<string, mixed>>, extras: array<string, string|null>}  $prepared
     */
    public function writeValidated(GeneratedPage $page, int $sectionIndex, array $prepared, int $expectedBaseRevisionId, ?int $userId, bool $draftOnly): PageRevision
    {
        return $this->fields->write(
            $page,
            $sectionIndex,
            $prepared['fields'],
            userId: $userId,
            expectedBaseRevisionId: $expectedBaseRevisionId,
            sectionExtras: $prepared['extras'],
            draftOnly: $draftOnly,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function currentEditableContent(GeneratedPage $page): array
    {
        $rid = $page->draft_revision_id ?? $page->published_revision_id;

        if ($rid) {
            return PageRevision::find($rid)?->content_data ?? $page->content_data ?? [];
        }

        return $page->content_data ?? [];
    }
}

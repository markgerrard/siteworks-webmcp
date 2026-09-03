<?php

namespace App\Services\Site\Editor;

use Normalizer;

final class ApprovalPresentation
{
    private const ARGUMENT_KEYS = [
        'scope',
        'version_id',
        'image_model',
        'concept_id',
        'page_id',
        'stored_index',
        'section_id',
        'field_path',
    ];

    public function __construct(private readonly OperationRegistry $operations) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function for(EditorContext $ctx, string $operation, array $input): array
    {
        $fields = [
            'site' => $this->clean($ctx->site->business_name),
            'side_effects' => $this->clean($this->operations->get($operation)->sideEffects()),
        ];

        foreach (self::ARGUMENT_KEYS as $key) {
            if (! array_key_exists($key, $input) || ! is_scalar($input[$key])) {
                continue;
            }

            $fields[$key] = $this->clean($input[$key]);
        }

        if ($this->hasPositionalAssignmentTarget($operation, $input)) {
            $fields['assignment_target_binding'] = 'not_bound';
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function hasPositionalAssignmentTarget(string $operation, array $input): bool
    {
        if ($operation !== 'upload_image') {
            return false;
        }

        return collect(['page_id', 'stored_index', 'field_path'])
            ->every(fn (string $key): bool => array_key_exists($key, $input));
    }

    private function clean(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $normalised = Normalizer::normalize((string) $value, Normalizer::FORM_C);
        $withoutControls = preg_replace('/\p{C}+/u', '', $normalised === false ? '' : $normalised) ?? '';

        return mb_substr($withoutControls, 0, 120);
    }
}

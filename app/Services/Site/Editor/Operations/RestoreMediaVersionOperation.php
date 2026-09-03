<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\SiteMedia;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;

final class RestoreMediaVersionOperation extends BaseOperation
{
    public function __construct(
        private readonly EditFieldOperation $editField,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'restore_media_version';
    }

    public function readOnly(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function delegatesTo(): array
    {
        return ['edit_field'];
    }

    public function sideEffects(): string
    {
        return 'Assigns a previous site media version to a draft field.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['page_id', 'stored_index', 'field_path', 'media_id', 'revision_base'],
            'properties' => [
                'page_id' => ['type' => 'integer'],
                'stored_index' => ['type' => 'integer', 'minimum' => 0],
                'field_path' => ['type' => 'string', 'maxLength' => 200],
                'media_id' => ['type' => 'integer'],
                'revision_base' => ['type' => 'integer'],
                'structure_epoch' => ['type' => 'integer'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);

        $pageId = self::intOrNull($input['page_id'] ?? null);
        $storedIndex = self::intOrNull($input['stored_index'] ?? null);
        $fieldPath = is_string($input['field_path'] ?? null) ? $input['field_path'] : null;
        $mediaId = self::intOrNull($input['media_id'] ?? null);
        $base = self::intOrNull($input['revision_base'] ?? null);

        if ($pageId === null || $storedIndex === null || $fieldPath === null || $fieldPath === '' || $mediaId === null || $base === null) {
            return OperationResult::fail('validation', 'page_id, stored_index, field_path, media_id and revision_base are required.', $state, [
                'fields' => [
                    'page_id' => $pageId === null ? ['required integer'] : [],
                    'stored_index' => $storedIndex === null ? ['required integer'] : [],
                    'field_path' => ($fieldPath === null || $fieldPath === '') ? ['required string'] : [],
                    'media_id' => $mediaId === null ? ['required integer'] : [],
                    'revision_base' => $base === null ? ['required integer'] : [],
                ],
            ]);
        }

        $media = SiteMedia::query()
            ->where('site_id', $ctx->site->id)
            ->find($mediaId);

        if (! $media) {
            return OperationResult::fail('not_found', 'Media not found.', $state);
        }

        // Only image fields may receive a media reference (the schema type decides, not the path suffix).
        $page = \App\Models\GeneratedPage::query()->where('site_id', $ctx->site->id)->whereNull('archived_at')->find((int) $pageId);
        $content = $page?->draftRevision?->content_data ?? $page?->publishedRevision?->content_data ?? $page?->content_data ?? [];
        if ($page === null) {
            return OperationResult::fail('not_found', 'Page not found.', $state);
        }
        if (! isset($content['sections'][$storedIndex]) || ! is_array($content['sections'][$storedIndex])) {
            return OperationResult::fail('validation', 'stored_index is out of range.', $state, ['fields' => ['stored_index' => ['out of range']]]);
        }
        $sectionType = $content['sections'][$storedIndex]['type'] ?? null;
        $field = is_string($sectionType) ? app(\App\Services\Site\SectionSchema::class)->resolveField($sectionType, $fieldPath) : null;
        if (($field['type'] ?? null) !== 'image') {
            return OperationResult::fail('unsupported_field', 'restore_media_version targets image fields only.', $state, [
                'fields' => ['field_path' => ['must be an image field']],
            ]);
        }

        $delegated = [
            'page_id' => $pageId,
            'stored_index' => $storedIndex,
            'field_path' => $fieldPath,
            'value' => str_ends_with($fieldPath, '_id') ? $media->id : $media->url,
            'revision_base' => $base,
        ];

        if (array_key_exists('structure_epoch', $input)) {
            $delegated['structure_epoch'] = $input['structure_epoch'];
        }
        if (array_key_exists('parent_origin', $input)) {
            $delegated['parent_origin'] = $input['parent_origin'];
        }

        return $this->delegate($this->editField, $ctx, $delegated);
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|-?[1-9][0-9]*)$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}

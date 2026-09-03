<?php

namespace App\Services\Site\Editor\Operations;

use App\Exceptions\UnsupportedImageException;
use App\Models\GeneratedPage;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorState;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\HeroVersionRegistrar;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\OperationFailed;
use App\Services\Site\Editor\SiteMediaIngestService;
use App\Services\Site\CompositionService;
use App\Services\Site\SectionSchema;
use App\Support\MediaStorage;

final class UploadImageOperation extends BaseOperation
{
    private const HERO_SECTION_TYPES = [
        'hero',
        'hero_compact',
        'projects_hero',
        'project_detail_hero',
    ];

    private const ASSIGNMENT_KEYS = [
        'page_id',
        'stored_index',
        'field_path',
        'revision_base',
        'structure_epoch',
    ];

    public function __construct(
        private readonly SiteMediaIngestService $mediaIngest,
        private readonly SectionSchema $schema,
        private readonly EditorStateFactory $states,
        private readonly CompositionService $composition,
        private readonly HeroVersionRegistrar $heroVersions,
        private readonly DraftAssetSelections $draftAssetSelections,
    ) {}

    /**
     * Unwrapped: the ingest (Imagick decode + object store PUT) must never run under the site_drafts lock,
     * so it happens in the deferred closure after run()'s transaction; the optional assignment wraps
     * itself in applyAdminChange (like update_form's writer) so the page write still bumps admin_revision.
     */
    public function wrapInAdminChange(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'upload_image';
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function allowedRoles(): ?array
    {
        return ['staff', 'client'];
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
        // Union of two independent additions, not a choice between them: dev's approval-conditional
        // caveat about the assignment target not being fully bound, and this arc's hero-version
        // registration. Dropping either would leave the exported tool description lying about what
        // this operation does.
        $description = 'Ingests site media and optionally assigns it to a draft image field; hero-family background assignments also register an inactive hero version and make it the drafted hero selection.';

        if (! (bool) config('editor.agent_approval.enabled')) {
            return $description;
        }

        return $description.' For assignments, structure_epoch is bound, but repeatable image entries can be re-pointed without an epoch bump, so the assignment target is not fully bound.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['data_base64', 'composition_revision'],
            'properties' => [
                'data_base64' => ['type' => 'string'],
                'composition_revision' => ['type' => 'integer'],
                'mime' => ['type' => 'string'],
                'filename' => ['type' => 'string'],
                'page_id' => ['type' => 'integer'],
                'stored_index' => ['type' => 'integer', 'minimum' => 0],
                'field_path' => ['type' => 'string', 'maxLength' => 200],
                'revision_base' => ['type' => 'integer'],
                'structure_epoch' => ['type' => 'integer'],
            ],
        ];
    }

    public function address(): string
    {
        return 'site';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);
        $dataBase64 = is_string($input['data_base64'] ?? null) ? $input['data_base64'] : null;

        if ($dataBase64 === null || $dataBase64 === '') {
            return OperationResult::fail('validation', 'data_base64 is required.', $state, [
                'fields' => ['data_base64' => ['required string']],
            ]);
        }

        $assignmentKeyCount = collect(self::ASSIGNMENT_KEYS)
            ->filter(fn (string $key): bool => array_key_exists($key, $input))
            ->count();

        if ($assignmentKeyCount !== 0 && $assignmentKeyCount !== count(self::ASSIGNMENT_KEYS)) {
            return OperationResult::fail(
                'validation',
                'page_id, stored_index, field_path, revision_base and structure_epoch must be provided together.',
                $state,
                ['fields' => collect(self::ASSIGNMENT_KEYS)
                    ->mapWithKeys(fn (string $key): array => [$key => array_key_exists($key, $input) ? [] : ['required']])
                    ->all()],
            );
        }

        $assignment = null;
        if ($assignmentKeyCount === count(self::ASSIGNMENT_KEYS)) {
            $assignment = $this->resolveAssignment($ctx, $input, $state);

            if ($assignment instanceof OperationResult) {
                return $assignment;
            }
        }

        $result = OperationResult::ok([], $state);
        $result->deferred = function () use ($ctx, $dataBase64, $assignment, $state): OperationResult {
            try {
                $media = $this->mediaIngest->ingestBase64($ctx->site, $dataBase64, $ctx->channel);
            } catch (UnsupportedImageException $exception) {
                return OperationResult::fail('validation', 'Image upload failed validation.', $state, [
                    'fields' => ['data_base64' => [$exception->getMessage()]],
                ]);
            }

            $data = [
                'media_id' => $media->id,
                'url' => $media->url,
            ];
            $ctx->changes->record(
                'site',
                "site_media.{$media->id}",
                null,
                $data,
                'insert',
            );

            if ($assignment === null) {
                return OperationResult::ok($data, $state);
            }

            $editField = app(OperationRegistry::class)->get('edit_field');
            $edited = null;
            $heroVersion = null;
            try {
                $this->composition->applyAdminChange(
                    $ctx->site,
                    function () use (&$edited, &$heroVersion, $editField, $ctx, $assignment, $media): void {
                        $edited = $this->delegate($editField, $ctx, [
                            'page_id' => $assignment['page_id'],
                            'stored_index' => $assignment['stored_index'],
                            'field_path' => $assignment['field_path'],
                            'value' => str_ends_with($assignment['field_path'], '_id') ? $media->id : $media->url,
                            'revision_base' => $assignment['revision_base'],
                            'structure_epoch' => $assignment['structure_epoch'],
                        ]);
                        if (! $edited->ok) {
                            throw new OperationFailed($edited); // rolls back the page write + bump
                        }

                        if ($assignment['is_hero_field']) {
                            $heroVersion = $this->heroVersions->registerFromMedia(
                                $ctx->site,
                                $media,
                                $assignment['page_type'],
                                'hero',
                                $ctx->actor->id,
                            );
                            $this->draftAssetSelections->setHero(
                                $ctx->site,
                                $assignment['page_type'],
                                'hero',
                                $heroVersion,
                                $ctx->actor->id,
                            );
                        }
                    },
                    $ctx->actor->id,
                    invalidatePublicCache: false,
                );
            } catch (OperationFailed $exception) {
                // The media row was created outside this transaction: remove row + object so a failed
                // assignment leaves nothing behind (spec § 3.1: never an untracked object).
                $media->delete(); // row first: a row pointing at a deleted object is a visible broken image
                try {
                    MediaStorage::disk()->delete($media->s3_key);
                } catch (\Throwable) {
                }

                return $exception->result;
            }

            return OperationResult::ok([
                ...$data,
                ...$edited->data,
                ...($heroVersion === null ? [] : ['hero_version_id' => $heroVersion->id]),
            ], $edited->state);
        };

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{page_id: int, stored_index: int, field_path: string, revision_base: int, structure_epoch: int, page_type: string, is_hero_field: bool}|OperationResult
     */
    private function resolveAssignment(EditorContext $ctx, array $input, EditorState $state): array|OperationResult
    {
        $pageId = self::intOrNull($input['page_id']);
        $storedIndex = self::intOrNull($input['stored_index']);
        $fieldPath = is_string($input['field_path']) ? $input['field_path'] : null;
        $revisionBase = self::intOrNull($input['revision_base']);
        $structureEpoch = self::intOrNull($input['structure_epoch']);

        if ($pageId === null || $storedIndex === null || $fieldPath === null || $fieldPath === '' || $revisionBase === null || $structureEpoch === null) {
            return OperationResult::fail('validation', 'Assignment inputs are invalid.', $state, [
                'fields' => [
                    'page_id' => $pageId === null ? ['required integer'] : [],
                    'stored_index' => $storedIndex === null ? ['required integer'] : [],
                    'field_path' => ($fieldPath === null || $fieldPath === '') ? ['required string'] : [],
                    'revision_base' => $revisionBase === null ? ['required integer'] : [],
                    'structure_epoch' => $structureEpoch === null ? ['required integer'] : [],
                ],
            ]);
        }

        $page = GeneratedPage::query()
            ->where('site_id', $ctx->site->id)
            ->whereNull('archived_at')
            ->find($pageId);

        if ($page === null) {
            return OperationResult::fail('not_found', 'Page not found.', $state);
        }

        $content = $page->draftRevision?->content_data
            ?? $page->publishedRevision?->content_data
            ?? $page->content_data
            ?? [];

        if (! isset($content['sections'][$storedIndex]) || ! is_array($content['sections'][$storedIndex])) {
            return OperationResult::fail('validation', 'stored_index is out of range.', $state, [
                'fields' => ['stored_index' => ['out of range']],
            ]);
        }

        $sectionType = $content['sections'][$storedIndex]['type'] ?? null;
        $field = is_string($sectionType) ? $this->schema->resolveField($sectionType, $fieldPath) : null;

        if (($field['type'] ?? null) !== 'image') {
            return OperationResult::fail('unsupported_field', 'upload_image targets image fields only.', $state, [
                'fields' => ['field_path' => ['must be an image field']],
            ]);
        }

        return [
            'page_id' => $pageId,
            'stored_index' => $storedIndex,
            'field_path' => $fieldPath,
            'revision_base' => $revisionBase,
            'structure_epoch' => $structureEpoch,
            'page_type' => $page->page_type,
            'is_hero_field' => $fieldPath === 'background_image'
                && in_array($sectionType, self::HERO_SECTION_TYPES, true),
        ];
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

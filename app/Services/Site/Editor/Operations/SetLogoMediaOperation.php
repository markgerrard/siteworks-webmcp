<?php

namespace App\Services\Site\Editor\Operations;

use App\Enums\LogoConceptSource;
use App\Models\LogoConcept;
use App\Models\SiteMedia;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;

final class SetLogoMediaOperation extends BaseOperation
{
    public function __construct(
        private readonly SelectLogoOperation $selectLogo,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'set_logo_media';
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function delegatesTo(): array
    {
        return ['select_logo'];
    }

    public function address(): string
    {
        return 'site';
    }

    public function sideEffects(): string
    {
        return 'Creates an unselected uploaded logo concept from site media and writes only the draft selection; does not flip is_selected.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['media_id', 'composition_revision'],
            'properties' => [
                'media_id' => ['type' => 'integer'],
                'composition_revision' => ['type' => 'integer'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);
        $mediaId = self::intOrNull($input['media_id'] ?? null);

        if ($mediaId === null) {
            return OperationResult::fail('validation', 'media_id is required.', $state, [
                'fields' => ['media_id' => ['required integer']],
            ]);
        }

        $media = SiteMedia::query()
            ->where('site_id', $ctx->site->id)
            ->find($mediaId);

        if ($media === null) {
            return OperationResult::fail('not_found', 'Media not found.', $state);
        }

        $version = (int) (LogoConcept::query()->where('site_id', $ctx->site->id)->max('version') ?? 0) + 1;

        $concept = LogoConcept::query()->create([
            'site_id' => $ctx->site->id,
            'source' => LogoConceptSource::Uploaded,
            'is_selected' => false,
            'path' => $media->s3_key,
            'version' => $version,
        ]);

        $data = [
            'logo_concept_id' => $concept->id,
            'media_id' => $media->id,
        ];
        $ctx->changes->record(
            'site',
            "logo_concepts.{$concept->id}",
            null,
            $data,
            'insert',
        );

        $selected = $this->delegate($this->selectLogo, $ctx, [
            'concept_id' => $concept->id,
            'composition_revision' => $input['composition_revision'] ?? null,
        ]);

        if (! $selected->ok) {
            return $selected;
        }

        return OperationResult::ok($data, $selected->state);
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

<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\HeroVersion;
use App\Models\LogoConcept;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;

final class RestoreImageVersionOperation extends BaseOperation
{
    public function __construct(
        private readonly DraftAssetSelections $selections,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'restore_image_version';
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function address(): string
    {
        return 'site';
    }

    public function sideEffects(): string
    {
        return 'Writes a draft hero or logo selection; does not activate it.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['scope', 'version_id', 'composition_revision'],
            'properties' => [
                'scope' => ['type' => 'string', 'enum' => ['hero', 'logo']],
                'version_id' => ['type' => 'integer'],
                'composition_revision' => ['type' => 'integer'],
                'page_type' => ['type' => 'string'],
                'slot' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);
        $scope = $input['scope'] ?? null;

        if (! in_array($scope, ['hero', 'logo'], true)) {
            return OperationResult::fail('validation', 'scope must be hero or logo.', $state, [
                'fields' => ['scope' => ['must be hero or logo']],
            ]);
        }

        $versionId = self::intOrNull($input['version_id'] ?? null);
        if ($versionId === null) {
            return OperationResult::fail('validation', 'version_id is required.', $state, [
                'fields' => ['version_id' => ['required integer']],
            ]);
        }

        if ($scope === 'logo') {
            $concept = LogoConcept::query()
                ->where('site_id', $ctx->site->id)
                ->find($versionId);

            if (! $concept) {
                return OperationResult::fail('not_found', 'Logo concept not found.', $state);
            }

            $this->selections->setLogo($ctx->site, $concept, $ctx->actor->id);

            return OperationResult::ok([
                'scope' => 'logo',
                'version_id' => $concept->id,
            ], $state);
        }

        $query = HeroVersion::query()
            ->where('site_id', $ctx->site->id)
            ->whereKey($versionId);

        $pageType = is_string($input['page_type'] ?? null) ? $input['page_type'] : null;
        $slot = is_string($input['slot'] ?? null) ? $input['slot'] : null;

        if ($pageType !== null && $pageType !== '') {
            $query->where('page_type', $pageType);
        }
        if ($slot !== null && $slot !== '') {
            $query->where('slot', $slot);
        }

        $version = $query->first();
        if (! $version) {
            return OperationResult::fail('not_found', 'Hero version not found.', $state);
        }

        $this->selections->setHero($ctx->site, $version->page_type, $version->slot, $version, $ctx->actor->id);

        return OperationResult::ok([
            'scope' => 'hero',
            'version_id' => $version->id,
        ], $state);
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

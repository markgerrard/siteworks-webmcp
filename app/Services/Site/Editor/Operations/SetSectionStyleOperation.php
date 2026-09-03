<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\Site\SiteDraft;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\ThemeResolver;
use Illuminate\Validation\ValidationException;

final class SetSectionStyleOperation extends BaseOperation
{
    public function __construct(
        private readonly StructureWrite $structure,
        private readonly ThemeResolver $resolver,
    ) {}

    public function name(): string
    {
        return 'set_section_style';
    }

    public function readOnly(): bool
    {
        return false;
    }

    /**
     * @return list<string>|null
     */
    public function allowedRoles(): ?array
    {
        return ['staff'];
    }

    public function address(): string
    {
        return 'page';
    }

    public function sideEffects(): string
    {
        return 'Merges operator style_overrides onto a section instance in the page draft. Staff only; does not publish. Also accepts per-section texture tokens: texture (library key, none, or image), texture_opacity (0.01–0.5), texture_size (sm|md|lg), texture_image_path (site media path), texture_image_mode (tile|cover).';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['page_id', 'section_id', 'tokens', 'revision_base', 'structure_epoch'],
            'properties' => [
                'page_id' => ['type' => 'integer'],
                'section_id' => [
                    'type' => 'string',
                    'description' => 'Stable section identifier of the instance to restyle.',
                ],
                'tokens' => [
                    'type' => 'object',
                    'description' => 'Patch map keyed by emitted CSS variable names without the -- prefix. Explicit null removes a key.',
                    'additionalProperties' => ['type' => ['string', 'null']],
                ],
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
        $prepared = $this->structure->prepare($ctx, $input);
        if ($prepared instanceof OperationResult) {
            return $prepared;
        }

        ['page' => $page, 'base' => $base, 'epoch' => $epoch, 'state' => $state] = $prepared;

        $sectionId = $input['section_id'] ?? null;
        if (! is_string($sectionId) || $sectionId === '') {
            return OperationResult::fail('validation', 'section_id is required.', $state, [
                'fields' => ['section_id' => ['required string']],
            ]);
        }

        $patch = $this->resolver->validateTokenOverridePatch(
            $input['tokens'] ?? null,
            allowTextureTokens: true,
            site: $ctx->site,
        );
        if (($patch['ok'] ?? false) !== true) {
            return OperationResult::fail('validation', (string) ($patch['message'] ?? 'Invalid tokens.'), $state, [
                'fields' => is_array($patch['fields'] ?? null) ? $patch['fields'] : ['tokens' => ['invalid']],
            ]);
        }

        $index = $this->structure->resolveSectionAddress(
            $this->structure->currentSections($page),
            $input,
            'stored_index',
            $state,
        );
        if ($index instanceof OperationResult) {
            return $index;
        }

        $ctx->site->loadMissing('businessProfile');
        $profile = $ctx->site->businessProfile?->profile_data ?? [];
        $draft = SiteDraft::where('site_id', $ctx->site->id)->first();
        $theme = is_array($draft?->composition['theme'] ?? null) ? $draft->composition['theme'] : [];
        $resolved = $this->resolver->resolve(
            $ctx->site,
            is_array($profile) ? $profile : [],
            $theme,
        );
        $siteTokens = $this->resolver->renderTokens($resolved);

        $currentSections = $this->structure->currentSections($page);
        $currentOverrides = is_array($currentSections[$index]['style_overrides'] ?? null)
            ? $currentSections[$index]['style_overrides']
            : [];
        $currentOverrides = array_filter(
            $currentOverrides,
            fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value),
            ARRAY_FILTER_USE_BOTH,
        );
        $merged = $this->resolver->mergeTokenOverrideMap($currentOverrides, $patch['set'], $patch['remove']);
        $proposedTokens = $this->resolver->applyEmittedTokenOverrides($siteTokens, $merged);
        $touched = array_values(array_unique([...array_keys($patch['set']), ...$patch['remove']]));

        foreach ($this->resolver->contrastWarningsForTokens($proposedTokens, $touched) as $warning) {
            $ctx->warnings->add($warning['code'], $warning['message'], path: $warning['path']);
        }

        return $this->structure->mutate(
            $ctx,
            $page,
            $base,
            $epoch,
            function (array $sections) use ($index, $merged): array {
                $section = $sections[$index] ?? null;
                if (! is_array($section)) {
                    throw ValidationException::withMessages([
                        'section_id' => 'Section is out of range.',
                    ]);
                }

                if ($merged === []) {
                    unset($sections[$index]['style_overrides']);
                } else {
                    $sections[$index]['style_overrides'] = $merged;
                }

                return array_values($sections);
            },
            $input,
        );
    }
}

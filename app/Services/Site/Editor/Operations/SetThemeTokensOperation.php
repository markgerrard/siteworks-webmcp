<?php

namespace App\Services\Site\Editor\Operations;

use App\Enums\MutationSource;
use App\Models\Site\SiteDraft;
use App\Services\Site\CompositionService;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\ThemeResolver;

final class SetThemeTokensOperation extends BaseOperation
{
    public function __construct(
        private readonly CompositionService $composition,
        private readonly EditorStateFactory $states,
        private readonly ThemeResolver $resolver,
    ) {}

    public function name(): string
    {
        return 'set_theme_tokens';
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
        return 'site';
    }

    public function sideEffects(): string
    {
        return 'Merges operator token_overrides into the draft composition theme. Staff only; does not publish.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['tokens', 'composition_revision'],
            'properties' => [
                'tokens' => [
                    'type' => 'object',
                    'description' => 'Patch map keyed by emitted CSS variable names without the -- prefix. Explicit null removes a key.',
                    'additionalProperties' => ['type' => ['string', 'null']],
                ],
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
        $patch = $this->resolver->validateTokenOverridePatch($input['tokens'] ?? null);
        if (($patch['ok'] ?? false) !== true) {
            return OperationResult::fail('validation', (string) ($patch['message'] ?? 'Invalid tokens.'), $state, [
                'fields' => is_array($patch['fields'] ?? null) ? $patch['fields'] : ['tokens' => ['invalid']],
            ]);
        }

        $draft = SiteDraft::where('site_id', $ctx->site->id)->first();
        $composition = $draft?->composition ?? [];
        $theme = is_array($composition['theme'] ?? null) ? $composition['theme'] : [];
        $existing = is_array($theme['token_overrides'] ?? null) ? $theme['token_overrides'] : [];
        $existing = array_filter(
            $existing,
            fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value),
            ARRAY_FILTER_USE_BOTH,
        );

        $merged = $this->resolver->mergeTokenOverrideMap($existing, $patch['set'], $patch['remove']);
        $theme['token_overrides'] = $merged;
        $composition['theme'] = $theme;

        $ctx->site->loadMissing('businessProfile');
        $profile = $ctx->site->businessProfile?->profile_data ?? [];
        $resolved = $this->resolver->resolve(
            $ctx->site,
            is_array($profile) ? $profile : [],
            $composition['theme'],
        );
        $proposedTokens = $this->resolver->renderTokens($resolved);
        $touched = array_values(array_unique([...array_keys($patch['set']), ...$patch['remove']]));

        foreach ($this->resolver->contrastWarningsForTokens($proposedTokens, $touched) as $warning) {
            $ctx->warnings->add($warning['code'], $warning['message'], path: $warning['path']);
        }

        $draftForWrite = SiteDraft::where('site_id', $ctx->site->id)->firstOrFail();
        $this->composition->updateThemeOverrides(
            $draftForWrite,
            ['token_overrides' => $merged === [] ? null : $merged],
            MutationSource::System,
            $ctx->actor->id,
        );

        return OperationResult::ok([
            'token_overrides' => $merged,
        ], $state);
    }
}

<?php

namespace App\Services\Site\Editor\Operations;

use App\Enums\MutationSource;
use App\Models\Site\SiteDraft;
use App\Models\ThemeTokenPreset;
use App\Services\Site\CompositionService;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\ThemeResolver;

final class ApplyThemeTokenPresetOperation extends BaseOperation
{
    public function __construct(
        private readonly CompositionService $composition,
        private readonly EditorStateFactory $states,
        private readonly ThemeResolver $resolver,
    ) {}

    public function name(): string
    {
        return 'apply_theme_token_preset';
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
        return 'Copies a named token preset onto the draft composition under existing site keys. Staff only; does not publish.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['name', 'composition_revision'],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Name of the preset whose token map is copied onto this site. Existing site keys win.',
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
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if ($name === '') {
            return OperationResult::fail('validation', 'name is required.', $state, [
                'fields' => ['name' => ['required']],
            ]);
        }

        $preset = ThemeTokenPreset::query()->where('name', $name)->first();
        if ($preset === null) {
            return OperationResult::fail('not_found', "Theme token preset [{$name}] was not found.", $state);
        }

        $presetTokens = is_array($preset->tokens) ? $preset->tokens : [];
        $presetTokens = array_filter(
            $presetTokens,
            fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value),
            ARRAY_FILTER_USE_BOTH,
        );

        $draft = SiteDraft::where('site_id', $ctx->site->id)->first();
        $composition = $draft?->composition ?? [];
        $theme = is_array($composition['theme'] ?? null) ? $composition['theme'] : [];
        $existing = is_array($theme['token_overrides'] ?? null) ? $theme['token_overrides'] : [];
        $existing = array_filter(
            $existing,
            fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value),
            ARRAY_FILTER_USE_BOTH,
        );

        $filled = [];
        $skipped = [];
        $merged = $existing;
        foreach ($presetTokens as $key => $value) {
            if (array_key_exists($key, $existing)) {
                $skipped[] = $key;

                continue;
            }

            $merged[$key] = $value;
            $filled[] = $key;
        }
        sort($filled);
        sort($skipped);

        $meta = [
            'applied_preset' => $preset->name,
            'applied_at' => now()->toIso8601String(),
        ];
        $theme['token_overrides'] = $merged;
        $theme['token_overrides_meta'] = $meta;
        $composition['theme'] = $theme;

        $ctx->site->loadMissing('businessProfile');
        $profile = $ctx->site->businessProfile?->profile_data ?? [];
        $resolved = $this->resolver->resolve(
            $ctx->site,
            is_array($profile) ? $profile : [],
            $composition['theme'],
        );
        $proposedTokens = $this->resolver->renderTokens($resolved);

        foreach ($this->resolver->contrastWarningsForTokens($proposedTokens, $filled) as $warning) {
            $ctx->warnings->add($warning['code'], $warning['message'], path: $warning['path']);
        }

        $draftForWrite = SiteDraft::where('site_id', $ctx->site->id)->firstOrFail();
        $this->composition->updateThemeOverrides(
            $draftForWrite,
            [
                'token_overrides' => $merged === [] ? null : $merged,
                'token_overrides_meta' => $meta,
            ],
            MutationSource::System,
            $ctx->actor->id,
        );

        return OperationResult::ok([
            'token_overrides' => $merged,
            'token_overrides_meta' => $meta,
            'filled' => $filled,
            'skipped' => $skipped,
        ], $state);
    }
}

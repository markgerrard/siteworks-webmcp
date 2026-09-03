<?php

namespace App\Services\Site\Editor\Operations;

use App\Enums\MutationSource;
use App\Models\Site\SiteDraft;
use App\Services\Site\CompositionService;
use App\Services\Site\DesignBrief;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\ThemeResolver;

final class UpdateBrandThemeOperation extends BaseOperation
{
    private const COLOUR_TOKEN_KEYS = [
        'primary', 'accent', 'tertiary',
        'surface', 'surface_alt', 'border',
        'text', 'text_muted',
    ];

    private const DERIVED_ON_TOKENS = [
        'text_on_alt' => 'surface_alt',
        'text_on_band' => 'band',
        'text_on_primary' => 'primary',
        'text_on_accent' => 'accent',
        'text_muted_on_contrast' => 'surface_contrast',
        'accent_text_on_contrast' => 'surface_contrast',
        'text_on_contrast' => 'surface_contrast',
    ];

    public function __construct(
        private readonly CompositionService $composition,
        private readonly EditorStateFactory $states,
        private readonly ThemeResolver $resolver,
    ) {}

    public function name(): string
    {
        return 'update_brand_theme';
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function address(): string
    {
        return 'site';
    }

    public function sideEffects(): string
    {
        return 'Writes colour/typography override tokens into the draft composition theme; contrast-gated.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['composition_revision'],
            'properties' => [
                'tokens' => [
                    'type' => 'object',
                    'properties' => array_combine(
                        self::COLOUR_TOKEN_KEYS,
                        array_fill(0, count(self::COLOUR_TOKEN_KEYS), ['type' => 'string', 'pattern' => '^#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$']),
                    ),
                ],
                'fonts' => [
                    'type' => 'object',
                    'properties' => [
                        'display' => ['type' => 'string', 'enum' => DesignBrief::DISPLAY_FONTS],
                        'body' => ['type' => 'string', 'enum' => DesignBrief::BODY_FONTS],
                    ],
                ],
                'brand_section_scheme' => ['type' => 'string', 'enum' => ['bold', 'soft']],
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
        $tokens = is_array($input['tokens'] ?? null) ? $input['tokens'] : [];
        $fonts = is_array($input['fonts'] ?? null) ? $input['fonts'] : [];

        // Validate token keys — unknown keys are validation, not a silent drop.
        $allowedTokenKeys = array_combine(self::COLOUR_TOKEN_KEYS, self::COLOUR_TOKEN_KEYS);
        foreach (array_keys($tokens) as $tokenKey) {
            if (! is_string($tokenKey) || ! array_key_exists($tokenKey, $allowedTokenKeys)) {
                return OperationResult::fail('validation', "Unknown token key [{$tokenKey}].", $state, [
                    'fields' => ['tokens' => ["unknown key [{$tokenKey}]"]],
                ]);
            }
        }

        // Validate and normalise hex colours — non-hex is validation.
        $overrides = [];
        foreach ($tokens as $key => $value) {
            if (! is_string($value)) {
                return OperationResult::fail('validation', "Non-string token value for [{$key}].", $state, [
                    'fields' => ['tokens' => ["[{$key}] must be a hex string"]],
                ]);
            }
            $normalised = $this->resolver->normaliseHex($value);
            if ($normalised === null) {
                return OperationResult::fail('validation', "Invalid hex colour for [{$key}]: {$value}.", $state, [
                    'fields' => ['tokens' => ["[{$key}] is not a valid hex colour"]],
                ]);
            }
            $overrides["{$key}_override"] = $normalised;
        }

        // Validate font keys against DesignBrief allowlists.
        if (isset($fonts['display'])) {
            if (! in_array($fonts['display'], DesignBrief::DISPLAY_FONTS, true)) {
                return OperationResult::fail('validation', "Unknown display font [{$fonts['display']}].", $state);
            }
            $overrides['display_font_override'] = $fonts['display'];
        }
        if (isset($fonts['body'])) {
            if (! in_array($fonts['body'], DesignBrief::BODY_FONTS, true)) {
                return OperationResult::fail('validation', "Unknown body font [{$fonts['body']}].", $state);
            }
            $overrides['body_font_override'] = $fonts['body'];
        }

        if (array_key_exists('brand_section_scheme', $input)) {
            $brandSectionScheme = $input['brand_section_scheme'];
            if (! is_string($brandSectionScheme) || ! in_array($brandSectionScheme, ['bold', 'soft'], true)) {
                return OperationResult::fail('validation', 'Brand section scheme must be bold or soft.', $state);
            }
            $overrides['brand_section_scheme_override'] = $brandSectionScheme === 'soft' ? 'soft' : null;
        }

        // Resolve the proposed theme tokens for the contrast gate.
        $draft = SiteDraft::where('site_id', $ctx->site->id)->first();
        $compositionBefore = $draft?->composition ?? [];
        $theme = is_array($compositionBefore['theme'] ?? null) ? $compositionBefore['theme'] : [];
        foreach ($overrides as $overrideKey => $overrideValue) {
            if ($overrideValue === null) {
                unset($theme[$overrideKey]);
            } else {
                $theme[$overrideKey] = $overrideValue;
            }
        }
        $proposedComposition = $compositionBefore;
        $proposedComposition['theme'] = $theme;

        $ctx->site->loadMissing('businessProfile');
        $profile = $ctx->site->businessProfile?->profile_data ?? [];
        $resolved = $this->resolver->resolve(
            $ctx->site,
            is_array($profile) ? $profile : [],
            $proposedComposition['theme'] ?? null,
        );
        $proposedTokens = $this->resolver->renderTokens($resolved);

        // Contrast gate: blocking pairs at 4.5.
        $blockingPairs = [
            ['text', 'surface', 'text/surface'],
            ['text_muted', 'surface', 'text_muted/surface'],
            ['text_muted', 'surface_alt', 'text_muted/surface_alt'],
        ];

        if ($tokens !== []) {
            foreach ($blockingPairs as [$fg, $bg, $label]) {
                $ratio = $this->resolver->contrastRatio(
                    (string) ($proposedTokens[$fg] ?? '#000000'),
                    (string) ($proposedTokens[$bg] ?? '#ffffff'),
                );
                if ($ratio < 4.5) {
                    return OperationResult::fail(
                        'validation',
                        "Contrast ratio for {$label} is too low ({$ratio}:1); minimum is 4.5:1.",
                        $state,
                    );
                }
            }
        }

        // contrast_below_aaa warning for text/surface between 4.5 and 7.0.
        $textSurfaceRatio = $this->resolver->contrastRatio(
            (string) ($proposedTokens['text'] ?? '#000000'),
            (string) ($proposedTokens['surface'] ?? '#ffffff'),
        );
        if ($textSurfaceRatio >= 4.5 && $textSurfaceRatio < 7.0) {
            $ctx->warnings->add(
                'contrast_below_aaa',
                "text/surface contrast ratio {$textSurfaceRatio}:1 is below AAA (7.0:1).",
                path: 'text',
            );
        }

        // Derived *_on_* token check: warn if any achieved ratio is below 4.5.
        // text_muted_on_alt is exempt — it is deliberately derived at 3.0.
        foreach (self::DERIVED_ON_TOKENS as $derivedToken => $bgToken) {
            $derivedValue = $proposedTokens[$derivedToken] ?? null;
            $bgValue = $proposedTokens[$bgToken] ?? null;
            if (! is_string($derivedValue) || ! is_string($bgValue)) {
                continue;
            }
            $ratio = $this->resolver->contrastRatio($derivedValue, $bgValue);
            if ($ratio < 4.5) {
                $ctx->warnings->add(
                    'contrast_below_aa',
                    "Derived token {$derivedToken} achieved ratio {$ratio}:1 against {$bgToken} (below 4.5:1 minimum).",
                    path: $derivedToken,
                );
            }
        }

        // Persist: reload draft under lock and write overrides.
        // The row is already locked by applyAdminChange's transaction.
        $draftForWrite = SiteDraft::where('site_id', $ctx->site->id)->firstOrFail();
        $this->composition->updateThemeOverrides(
            $draftForWrite,
            $overrides,
            MutationSource::System,
            $ctx->actor->id,
        );

        return OperationResult::ok([
            'tokens' => $proposedTokens,
            'overrides' => $overrides,
        ], $state);
    }
}

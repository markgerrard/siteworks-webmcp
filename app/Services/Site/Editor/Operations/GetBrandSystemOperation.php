<?php

namespace App\Services\Site\Editor\Operations;

use App\Models\Site;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\DesignBrief;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\ThemeResolver;

final class GetBrandSystemOperation extends BaseOperation
{
    /**
     * renderTokens() colour keys that belong in `palette`.
     *
     * @var list<string>
     */
    private const PALETTE_KEYS = [
        'primary',
        'primary_text',
        'primary_text_on_alt',
        'accent',
        'accent_text',
        'accent_text_on_alt',
        'tertiary',
        'surface',
        'surface_alt',
        'border',
        'text',
        'text_muted',
        'band',
        'surface_contrast',
        'brand_section_surface',
    ];

    /**
     * Renderer-computed contrast colours, keyed for the agent payload.
     *
     * @var array<string, string>
     */
    private const TEXT_SAFE_KEYS = [
        'on_primary' => 'text_on_primary',
        'on_accent' => 'text_on_accent',
        'on_surface' => 'text',
        'on_surface_alt' => 'text_on_alt',
        'on_band' => 'text_on_band',
        'on_contrast' => 'text_on_contrast',
        'muted_on_surface' => 'text_muted',
        'muted_on_alt' => 'text_muted_on_alt',
        'muted_on_contrast' => 'text_muted_on_contrast',
    ];

    public function __construct(
        private readonly EditorStateFactory $states,
        private readonly ThemeResolver $themes,
    ) {}

    public function name(): string
    {
        return 'get_brand_system';
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function wrapInAdminChange(): bool
    {
        return false;
    }

    public function address(): string
    {
        return 'site';
    }

    /**
     * @return list<string>
     */
    public function allowedRoles(): ?array
    {
        return ['staff', 'client'];
    }

    public function sideEffects(): string
    {
        return 'Reads the effective brand palette, text-safe contrast colours, fonts, layout tokens, and design-brief rationale — the source of truth for the site\'s colours and design system as rendered (get_brand_context returns the raw captured profile instead). Makes no draft changes.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $site = $ctx->site->loadMissing('businessProfile');
        $profile = is_array($site->businessProfile?->profile_data) ? $site->businessProfile->profile_data : [];
        $theme = $this->themes->resolve($site, $profile, $this->compositionTheme($site));
        $tokens = $this->themes->renderTokens($theme);
        $brief = DesignBrief::fromSite($site);

        $data = [
            'palette' => $this->hexMap($tokens, self::PALETTE_KEYS),
            'text_safe' => $this->textSafe($tokens),
        ];

        $fonts = $this->fonts($theme);
        if ($fonts !== []) {
            $data['fonts'] = $fonts;
        }

        foreach (['heading_scale', 'spacing_density', 'corner_style'] as $key) {
            $value = $theme[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $data[$key] = $value;
            }
        }

        $radii = $this->radii($tokens);
        if ($radii !== []) {
            $data['radii'] = $radii;
        }

        if ($brief !== null) {
            $data['mood'] = $brief->mood();
            $rationale = self::cleanRationale($brief->rationale());
            if ($rationale !== null) {
                $data['rationale'] = $rationale;
            }
        }

        $tone = $profile['tone'] ?? null;
        if (is_string($tone) && trim($tone) !== '') {
            $data['tone'] = trim($tone);
        }

        return OperationResult::ok($data, $this->states->for($site, null));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function compositionTheme(Site $site): ?array
    {
        $composition = SiteVersionCurrent::query()
            ->with('version')
            ->where('site_id', $site->id)
            ->first()
            ?->version
            ?->composition;

        $theme = is_array($composition) ? ($composition['theme'] ?? null) : null;

        return is_array($theme) ? $theme : null;
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function hexMap(array $tokens, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = $tokens[$key] ?? null;
            if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @return array<string, string>
     */
    private function textSafe(array $tokens): array
    {
        $out = [];
        foreach (self::TEXT_SAFE_KEYS as $alias => $tokenKey) {
            $value = $tokens[$tokenKey] ?? null;
            if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
                $out[$alias] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $theme
     * @return array<string, string>
     */
    private function fonts(array $theme): array
    {
        $out = [];
        foreach (['display' => 'display_font', 'body' => 'body_font'] as $alias => $themeKey) {
            $slug = $theme[$themeKey] ?? null;
            if (! is_string($slug) || $slug === '') {
                continue;
            }
            $name = ThemeResolver::FONTS[$slug]['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $out[$alias] = $name;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $tokens
     * @return array<string, string>
     */
    private function radii(array $tokens): array
    {
        $out = [];
        foreach (['card' => 'radius_card', 'button' => 'radius_button'] as $alias => $tokenKey) {
            $value = $tokens[$tokenKey] ?? null;
            if (is_string($value) && $value !== '') {
                $out[$alias] = $value;
            }
        }

        return $out;
    }

    private static function cleanRationale(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $cleaned = preg_replace('/\[(?:INTERNAL|TODO|NOTE|HIDDEN)[^\]]*\]/i', '', $raw) ?? $raw;
        $cleaned = preg_replace('/<!--.*?-->/s', '', $cleaned) ?? $cleaned;
        $cleaned = trim(preg_replace('/[ \t]+/', ' ', $cleaned) ?? $cleaned);
        $cleaned = trim(preg_replace('/\n{3,}/', "\n\n", $cleaned) ?? $cleaned);

        return $cleaned === '' ? null : $cleaned;
    }
}

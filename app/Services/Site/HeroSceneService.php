<?php

namespace App\Services\Site;

use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves a Site's home_hero_scene JSON into a concrete render payload
 * the home-hero Blade template can iterate over. Single render-time
 * source of truth — both site/sections/hero.blade.php and the preview
 * surface call resolve().
 *
 * Backward compat: when home_hero_scene is null the service derives a
 * 1-slide scene from HeroResolution when a page is supplied, or directly
 * from the legacy single-asset state for older callers. That keeps every
 * pre-scene site rendering untouched while respecting the explicit draft
 * gate on page renders.
 *
 * Resolved shape:
 * [
 *   'kind'  => 'image' | 'video',
 *   'slides' => [
 *     [
 *       'asset_url'        => string,
 *       'heading'          => string|null,
 *       'subheading'       => string|null,
 *       'cta_label'        => string|null,
 *       'cta_action'       => string|null,   // page_type slug to link to; null → contact
 *       'text_zone'        => string,        // e.g. 'middle-left'
 *       'text_color'       => string,        // 'white' | 'dark'
 *       'overlay_strength' => string,        // 'light'|'medium'|'heavy'
 *       'dwell_secs'       => int,           // image kind only
 *     ],
 *   ],
 *   'transitions'         => [['type' => string, 'duration_secs' => float], ...],
 *   'composite_video_url' => string|null,    // video kind only
 *   'is_legacy'           => bool,           // true when derived from non-scene state
 *   'height'              => string|null,    // optional CSS size override (validated)
 *   'panel_width'         => string|null,    // optional boxed-panel max-width (validated)
 *   'motion'              => string|null,    // 'ken_burns' opt-in slide motion (image kind only)
 *   'overlay_mode'        => string|null,    // 'constant' locks slide-0 copy over the whole cycle
 *   'panel_opacity'       => int|null,       // boxed-panel background opacity 0-100 (validated)
 *   'overlay_style'       => string|null,    // boxed copy treatment: 'panel' (default) | 'gradient' | 'none'
 * ]
 */
class HeroSceneService
{
    public function __construct(private readonly HeroResolution $heroResolution) {}

    /**
     * Strict CSS size for style-attribute injection: number + unit only.
     * Rejects injection payloads (semicolons, urls, etc.).
     */
    private const CSS_SIZE_PATTERN = '/^\d{1,3}(?:\.\d{1,2})?(vh|vw|rem|px|%)$/';

    /**
     * @return array{kind:string, slides:array<int,array<string,mixed>>, transitions:array<int,array<string,mixed>>, composite_video_url:?string, is_legacy:bool, height:?string, panel_width:?string, motion:?string, overlay_mode:?string, panel_opacity:?int, overlay_style:?string}|null
     *         Returns null if the site has no hero asset of any kind to render.
     */
    public function resolve(
        Site $site,
        ?array $headlineFallback = null,
        bool $useDraftAssets = false,
        ?GeneratedPage $page = null,
        ?HeroState $resolvedState = null,
    ): ?array
    {
        // When the legacy hero video is active, it wins over any configured
        // scene. The legacy hero blade has richer video chrome (overlays,
        // text-zone logic, fallback poster) than the scene partial's
        // composite path, so we route through deriveLegacyScene which
        // returns a 1-slide kind=video result — count(slides)===1 trips
        // $heroIsMultiSlide=false in the parent template and falls through
        // to the legacy single-asset video render.
        $state = $resolvedState ?? ($page === null ? null : $this->heroResolution->for($site, $page, $useDraftAssets));
        // The legacy predicate is `enabled && path`, and it must stay that for PUBLIC renders. The
        // resolver's mode==='video' is not equivalent: resolveVideo() falls back to the canonical
        // dev-previews key when home_hero_video_path is null, so a site with enabled=true, path=null
        // and a configured scene rendered the SCENE before the extraction and the VIDEO after it.
        // That is a public output change on an existing-site shape the fixture corpus does not
        // contain, which is why every byte-identity test stayed green over it. Only the drafted path
        // may use the resolver's mode, because that is the state the draft is expressing.
        $videoActive = $state !== null && $useDraftAssets
            ? $state->mode === 'video'
            : (bool) ($site->home_hero_video_enabled && $site->home_hero_video_path);

        $scene = $state === null ? $site->home_hero_scene : $state->scene;
        if (! $videoActive && is_array($scene) && ! empty($scene['slides'])) {
            return $this->hydrate($site, $scene, $headlineFallback, $state);
        }

        return $this->deriveLegacyScene($site, $headlineFallback, $state);
    }

    /**
     * Hydrate a stored scene — replace asset_id/asset_type with concrete
     * URLs, fill in any blank overlay fields from the legacy headline
     * fallback so the first slide doesn't render with empty copy.
     *
     * @param  array<string,mixed>  $scene
     * @param  array<string,mixed>|null  $headlineFallback
     * @return array<string,mixed>
     */
    private function hydrate(Site $site, array $scene, ?array $headlineFallback, ?HeroState $state = null): array
    {
        $kind = $scene['kind'] ?? 'image';
        $slides = [];
        foreach ($scene['slides'] ?? [] as $i => $slide) {
            $url = $this->resolveAssetUrl($site, $slide);
            if ($url === null) {
                continue; // skip orphans (asset deleted)
            }
            $slidePayload = [
                'asset_url' => $url,
                'heading' => $this->coalesce($slide['heading'] ?? null, $i === 0 ? ($headlineFallback['heading'] ?? null) : null),
                'subheading' => $this->coalesce($slide['subheading'] ?? null, $i === 0 ? ($headlineFallback['subheading'] ?? null) : null),
                'cta_label' => $this->coalesce($slide['cta_label'] ?? null, $i === 0 ? ($headlineFallback['cta_label'] ?? null) : null),
                'cta_action' => $slide['cta_action'] ?? null,
                'text_zone' => $slide['text_zone'] ?? 'middle-left',
                'text_color' => $slide['text_color'] ?? 'white',
                'overlay_strength' => $slide['overlay_strength'] ?? 'medium',
                'dwell_secs' => (int) ($slide['dwell_secs'] ?? 6),
            ];
            $focus = \App\Support\Site\HeroFocus::sanitize($slide['focus'] ?? null);
            if ($focus !== null) {
                $slidePayload['focus'] = $focus;
            }
            $slides[] = $slidePayload;
        }

        if (empty($slides)) {
            // Every slide was orphaned — fall through to legacy derivation
            // so the page still renders something.
            return $this->deriveLegacyScene($site, $headlineFallback, $state) ?? [
                'kind' => $kind,
                'slides' => [],
                'transitions' => [],
                'composite_video_url' => null,
                'is_legacy' => true,
                'height' => null,
                'panel_width' => null,
                'motion' => null,
                'overlay_mode' => null,
                'panel_opacity' => null,
                'overlay_style' => null,
            ];
        }

        $compositeUrl = null;
        if ($kind === 'video' && ! empty($scene['composite_video_id'])) {
            $composite = HeroVideoVersion::where('site_id', $site->id)
                ->where('id', $scene['composite_video_id'])
                ->first();
            $compositeUrl = $composite?->url();
        }

        // kind=video with no resolvable composite (deleted, FK orphan, never
        // built) used to fall through to the partial's image cycler — which
        // would then try to use mp4 URLs as CSS background-images. Detect
        // that here and fall back to the legacy single-asset scene so the
        // page still renders something coherent.
        if ($kind === 'video' && $compositeUrl === null) {
            return $this->deriveLegacyScene($site, $headlineFallback, $state) ?? [
                'kind' => 'image',
                'slides' => [],
                'transitions' => [],
                'composite_video_url' => null,
                'is_legacy' => true,
                'height' => null,
                'panel_width' => null,
                'motion' => null,
                'overlay_mode' => null,
                'panel_opacity' => null,
                'overlay_style' => null,
            ];
        }

        return [
            'kind' => $kind,
            'slides' => $slides,
            'transitions' => array_values($scene['transitions'] ?? []),
            'composite_video_url' => $compositeUrl,
            'is_legacy' => false,
            // Optional per-site CSS size overrides — validated at this boundary
            // so blades never echo raw home_hero_scene values into style attrs.
            'height' => $this->sanitizeCssSize($scene['height'] ?? null),
            'panel_width' => $this->sanitizeCssSize($scene['panel_width'] ?? null),
            'motion' => $kind === 'image' ? $this->sanitizeMotion($scene['motion'] ?? null) : null,
            'overlay_mode' => ($scene['overlay_mode'] ?? null) === 'constant' ? 'constant' : null,
            'panel_opacity' => $this->sanitizePanelOpacity($scene['panel_opacity'] ?? null),
            'overlay_style' => in_array($scene['overlay_style'] ?? null, ['gradient', 'none'], true) ? $scene['overlay_style'] : null,
        ];
    }

    /**
     * Allow only a plain CSS length/percentage for injection into style
     * attributes. Invalid or missing → null (caller keeps its default).
     */
    private function sanitizeCssSize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (preg_match(self::CSS_SIZE_PATTERN, $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * Boxed-panel background opacity: whole percent 0-100 only — anything
     * else (strings with units, floats, out-of-range) → null so the blade
     * keeps its default. Int-only means the blade can echo it into a
     * style attribute without escaping concerns.
     */
    private function sanitizePanelOpacity(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }
        $value = (int) $value;

        return ($value >= 0 && $value <= 100) ? $value : null;
    }

    /**
     * Allowlist for the opt-in slide-motion effect. Anything but a known
     * effect name → null (caller renders the plain cross-fade).
     */
    private function sanitizeMotion(mixed $value): ?string
    {
        return $value === 'ken_burns' ? 'ken_burns' : null;
    }

    /**
     * @param  array<string,mixed>  $slide
     */
    private function resolveAssetUrl(Site $site, array $slide): ?string
    {
        $type = $slide['asset_type'] ?? null;
        $id = $slide['asset_id'] ?? null;
        if (! $id) {
            return null;
        }

        if ($type === 'hero_video_version') {
            $row = HeroVideoVersion::where('site_id', $site->id)->find($id);

            return $row?->url();
        }

        // Default: image hero version
        $row = HeroVersion::where('site_id', $site->id)->find($id);

        return $row?->url;
    }

    /**
     * Build a 1-slide scene from the site's legacy single-asset state so
     * pre-scene sites render unchanged. Prefers an active video clip over
     * the static image — matches today's hero blade behaviour where a
     * video, when enabled, takes over the background.
     *
     * @param  array<string,mixed>|null  $headlineFallback
     * @return array<string,mixed>|null
     */
    private function deriveLegacyScene(Site $site, ?array $headlineFallback, ?HeroState $state = null): ?array
    {
        // Prefer an enabled hero video clip if present.
        if ($state?->video_url !== null || ($state === null && $site->home_hero_video_enabled && $site->home_hero_video_path)) {
            $url = $state?->video_url ?? Storage::disk('s3')->url($site->home_hero_video_path);

            return [
                'kind' => 'video',
                'slides' => [[
                    'asset_url' => $url,
                    'heading' => $headlineFallback['heading'] ?? null,
                    'subheading' => $headlineFallback['subheading'] ?? null,
                    'cta_label' => $headlineFallback['cta_label'] ?? null,
                    'text_zone' => $headlineFallback['text_zone'] ?? 'middle-left',
                    'text_color' => $headlineFallback['text_color'] ?? 'white',
                    'overlay_strength' => $headlineFallback['overlay_strength'] ?? 'medium',
                    'dwell_secs' => 6,
                ]],
                'transitions' => [],
                'composite_video_url' => $url,
                'is_legacy' => true,
                'height' => null,
                'panel_width' => null,
                'motion' => null,
                'overlay_mode' => null,
                'panel_opacity' => null,
                'overlay_style' => null,
            ];
        }

        // Otherwise fall back to the active home-hero image version.
        $hero = $state === null
            ? HeroVersion::where('site_id', $site->id)
                ->where('page_type', 'home')
                ->where('slot', 'hero')
                ->where('is_active', true)
                ->first()
            : null;

        $imageUrl = $state?->image_url ?? $hero?->url;
        if ($imageUrl === null) {
            return null;
        }

        $placement = $state?->placement ?? (is_array($hero?->placement) ? $hero->placement : []);
        $legacySlide = [
            'asset_url' => $imageUrl,
            'heading' => $headlineFallback['heading'] ?? null,
            'subheading' => $headlineFallback['subheading'] ?? null,
            'cta_label' => $headlineFallback['cta_label'] ?? null,
            'text_zone' => $placement['text_zone'] ?? ($headlineFallback['text_zone'] ?? 'middle-left'),
            'text_color' => $placement['text_color'] ?? ($headlineFallback['text_color'] ?? 'white'),
            'overlay_strength' => $placement['overlay_strength'] ?? ($headlineFallback['overlay_strength'] ?? 'medium'),
            'dwell_secs' => 6,
        ];
        $focus = \App\Support\Site\HeroFocus::sanitize($placement['focus'] ?? null);
        if ($focus !== null) {
            $legacySlide['focus'] = $focus;
        }

        return [
            'kind' => 'image',
            'slides' => [$legacySlide],
            'transitions' => [],
            'composite_video_url' => null,
            'is_legacy' => true,
            'height' => null,
            'panel_width' => null,
            'motion' => null,
            'overlay_mode' => null,
            'panel_opacity' => null,
            'overlay_style' => null,
        ];
    }

    /**
     * Treat empty strings the same as null when picking between a slide
     * value and its fallback — so an agent who explicitly cleared a field
     * doesn't revert to the page-level default.
     *
     * Wait, that's the OPPOSITE — if they cleared, we DO want fallback to
     * fill in. So keep empty-string-as-null.
     */
    private function coalesce(?string $primary, ?string $fallback): ?string
    {
        if ($primary !== null && trim($primary) !== '') {
            return $primary;
        }

        return $fallback;
    }
}

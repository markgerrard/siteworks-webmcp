<?php

use App\Enums\MutationSource;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Livewire\Concerns\DemoUnavailable;
use App\Models\Site;
use App\Services\Site\CompositionService;
use App\Services\Site\DesignBrief;
use App\Services\Site\ThemeResolver;
use Livewire\Attributes\Locked;
use Livewire\Component;

// WCAG contrast helpers, declared once at file load. Blade re-includes
// this template per Livewire request, so function_exists guards stop
// "Cannot redeclare" fatals on the second+ render.
if (! function_exists('designPanelContrast')) {
    function designPanelContrast(string $hex1, string $hex2): float
    {
        $relativeLuminance = function (string $hex): float {
            $hex = ltrim($hex, '#');
            if (strlen($hex) !== 6) {
                return 0.0;
            }
            $channels = [
                hexdec(substr($hex, 0, 2)) / 255,
                hexdec(substr($hex, 2, 2)) / 255,
                hexdec(substr($hex, 4, 2)) / 255,
            ];
            $linear = array_map(fn (float $c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4, $channels);

            return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
        };

        $a = $relativeLuminance($hex1);
        $b = $relativeLuminance($hex2);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }
}

if (! function_exists('designPanelContrastLabel')) {
    function designPanelContrastLabel(float $ratio): string
    {
        if ($ratio >= 7.0) {
            return 'AAA ' . number_format($ratio, 1);
        }
        if ($ratio >= 4.5) {
            return 'AA ' . number_format($ratio, 1);
        }
        if ($ratio >= 3.0) {
            return 'AA-L ' . number_format($ratio, 1);
        }

        return 'FAIL ' . number_format($ratio, 1);
    }
}

if (! function_exists('designPanelContrastClass')) {
    function designPanelContrastClass(float $ratio): string
    {
        if ($ratio >= 4.5) {
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300';
        }
        if ($ratio >= 3.0) {
            return 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300';
        }

        return 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300';
    }
}

/**
 * DesignPanel — single admin surface for every design token on a site.
 *
 * Replaces the old 2-column dropdown form and the now-deleted theme-
 * picker. Emphasises visual choices: live preview at the top, mood
 * tiles, font-sample cards, big palette swatches with WCAG badges,
 * visual layout chips.
 *
 * Per-token override semantics:
 *   - Click mood → instant save (bumps admin_revision)
 *   - Click font card → instant save
 *   - Click layout chip → instant save
 *   - Click palette swatch → colour-picker popover → Apply → save
 *   - Regenerate brief → wipes all overrides, dispatches DesignBriefJob
 *   - Reset overrides → clears *_override keys, keeps brief
 *   - Remove brief → clears design_brief + overrides
 */
new class extends Component
{
    use AuthorizesSiteAccess;
    use DemoUnavailable;

    // Poll every 2s while DesignBriefJob is in flight; 45 polls = 90s cap.
    public const REGENERATE_POLL_INTERVAL_MS = 2000;

    public const REGENERATE_MAX_POLLS = 45;

    #[Locked]
    public int $siteId;

    /** True when DesignBriefJob has been dispatched + is still in flight. */
    public bool $regenerating = false;

    /**
     * Hash of the site's design_brief at the moment regeneration started.
     * Set when dispatching DesignBriefJob; cleared when polling detects a
     * different hash (job finished) or timeout.
     */
    public ?string $regenerateSnapshot = null;

    /** Incremented by checkRegenerateProgress; triggers timeout at MAX_POLLS. */
    public int $regeneratePollCount = 0;

    /** The currently-editable palette token (opens the popover). Null = closed. */
    public ?string $editingToken = null;

    /** The hex value being edited in the popover, before Apply. */
    public string $editingHex = '';

    /** Last-used admin colour anchors for whole-palette regeneration. */
    public string $primaryColourHint = '';

    public string $accentColourHint = '';

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $site = $this->authorizedSite();
        $colourHints = is_array($site->design_brief['colour_hints'] ?? null)
            ? $site->design_brief['colour_hints']
            : [];
        $this->primaryColourHint = is_string($colourHints['primary'] ?? null) ? $colourHints['primary'] : '';
        $this->accentColourHint = is_string($colourHints['accent'] ?? null) ? $colourHints['accent'] : '';
    }

    protected function authorizedSite(): Site
    {
        $site = $this->findAuthorizedSite();
        abort_unless($site, 403);

        return $site;
    }

    // ─── Mood ────────────────────────────────────────────────────────────

    public function selectMood(string $mood): void
    {
        if ($this->regenerating) {
            return; // regenerate already in flight — don't stack dispatches
        }

        $site = $this->authorizedSite();

        if (! in_array($mood, DesignBrief::MOODS, true)) {
            return;
        }

        // Mood is part of the brief itself, not a per-token override.
        $this->startRegenerate($site, 'Regenerating design brief with the new mood…', $mood);
    }

    // ─── Fonts ───────────────────────────────────────────────────────────

    public function selectDisplayFont(string $font): void
    {
        if ($this->regenerating) {
            return;
        }
        $this->saveOverride('display_font_override', $font, DesignBrief::DISPLAY_FONTS);
    }

    public function selectBodyFont(string $font): void
    {
        if ($this->regenerating) {
            return;
        }
        $this->saveOverride('body_font_override', $font, DesignBrief::BODY_FONTS);
    }

    // ─── Layout ──────────────────────────────────────────────────────────

    public function selectHeadingScale(string $value): void
    {
        if ($this->regenerating) {
            return;
        }
        $this->saveOverride('heading_scale_override', $value, DesignBrief::HEADING_SCALES);
    }

    public function selectSpacingDensity(string $value): void
    {
        if ($this->regenerating) {
            return;
        }
        $this->saveOverride('spacing_density_override', $value, DesignBrief::SPACING_DENSITIES);
    }

    public function selectContainerWidth(string $value): void
    {
        if ($this->regenerating) {
            return;
        }
        $this->saveOverride('container_width_override', $value, ThemeResolver::CONTAINER_WIDTHS);
    }

    public function selectDisplayScale(string $value): void
    {
        if ($this->regenerating) {
            return;
        }
        $this->saveOverride('display_scale_override', $value, ThemeResolver::DISPLAY_SCALES);
    }

    public function selectCornerStyle(string $value): void
    {
        if ($this->regenerating) {
            return;
        }
        $this->saveOverride('corner_style_override', $value, DesignBrief::CORNER_STYLES);
    }

    // ─── Palette popover ─────────────────────────────────────────────────

    public function editPaletteToken(string $token): void
    {
        if ($this->regenerating) {
            return;
        }

        $tokens = ['primary', 'accent', 'tertiary', 'surface', 'surface_alt', 'border', 'text', 'text_muted'];
        if (! in_array($token, $tokens, true)) {
            return;
        }

        $this->editingToken = $token;
        $resolved = $this->resolvedTokens();
        $this->editingHex = $resolved['palette'][$token] ?? '#000000';
    }

    public function applyPaletteEdit(): void
    {
        if ($this->regenerating) {
            return;
        }
        if (! is_string($this->editingToken)) {
            return;
        }

        // Forgiving hex sanitiser: paste-typos produce 7-8 char strings
        // (e.g. "#020617a" — a stray trailing letter) that normaliseHex
        // rejects with no visible feedback because the error callout
        // renders at the review root, BEHIND the still-open modal. Strip
        // the hash, keep only hex chars, trim to the first 6 — so a
        // minor typo yields a usable colour instead of a silent Apply.
        $candidate = ltrim(trim($this->editingHex), '#');
        $candidate = preg_replace('/[^0-9a-fA-F]/', '', $candidate ?? '');
        if (strlen($candidate) > 6) {
            $candidate = substr($candidate, 0, 6);
        }

        $hex = app(ThemeResolver::class)->normaliseHex($candidate);
        if ($hex === null) {
            $this->addError('editingHex', 'That doesn’t look like a valid hex colour — expected 3 or 6 hex digits.');

            return;
        }

        $overrideKey = "{$this->editingToken}_override";
        $site = $this->authorizedSite();
        $cs = app(CompositionService::class);
        $draft = $cs->getOrCreateDraft($site);
        $cs->updateThemeOverrides($draft, [$overrideKey => $hex], MutationSource::Admin, auth()->id());

        $this->editingToken = null;
        $this->editingHex = '';
        $this->resetErrorBag();
        $this->dispatch('composition-dirty');
    }

    public function clearPaletteEdit(): void
    {
        $this->editingToken = null;
        $this->editingHex = '';
        $this->resetErrorBag();
    }

    public function clearPaletteOverride(string $token): void
    {
        if ($this->regenerating) {
            return;
        }
        $overrideKey = "{$token}_override";
        $this->saveOverride($overrideKey, null, null);
    }

    /**
     * Flip the neutral palette tokens (surface / surface_alt / text /
     * text_muted / border) between light and dark via a single
     * composition.theme override. Brand colours (primary, accent,
     * tertiary) stay put. Toggle lives in the design-panel agent UI;
     * the invert state DOES save to composition — anyone viewing the
     * preview URL afterwards sees the flipped palette. Clear all
     * overrides removes it alongside the other overrides.
     */
    public function toggleInvertMode(): void
    {
        if ($this->regenerating) {
            return;
        }
        $site = $this->authorizedSite();
        $cs = app(CompositionService::class);
        $draft = $cs->getOrCreateDraft($site);

        $current = (bool) ($draft->composition['theme']['invert_mode_override'] ?? false);
        $next = ! $current;

        // Store true for inverted; null to remove the override key.
        // updateThemeOverrides treats null/empty-string as "unset", which
        // is the right behaviour for the off state — UI reads
        // `(bool) ($overrides['invert_mode_override'] ?? false)`, so an
        // unset key and an explicit false both render as off, and an
        // earlier draft to keep the key as a literal "false" string would
        // round-trip through that bool cast as TRUTHY (PHP coerces any
        // non-empty string to true). Unsetting on toggle-off is the only
        // representation the cast handles correctly, and resetOverrides()
        // doesn't need a sentinel to drop a key that's already absent.
        $cs->updateThemeOverrides(
            $draft,
            ['invert_mode_override' => $next ? true : null],
            MutationSource::Admin,
            auth()->id(),
        );

        $this->dispatch('composition-dirty');
    }

    public function selectBrandSectionScheme(string $scheme): void
    {
        if ($this->regenerating || ! in_array($scheme, ['bold', 'soft'], true)) {
            return;
        }

        $this->saveOverride(
            'brand_section_scheme_override',
            $scheme === 'soft' ? 'soft' : null,
            ['soft'],
        );
    }

    // ─── Action bar ──────────────────────────────────────────────────────

    public function regenerateBrief(): void
    {
        $this->demoUnavailable('design brief');
    }

    protected function demoNoticeChannel(): string
    {
        return 'design-msg';
    }

    public function resetOverrides(): void
    {
        if ($this->regenerating) {
            return;
        }

        $site = $this->authorizedSite();

        app(CompositionService::class)->clearAllThemeOverrides($site, MutationSource::Admin, auth()->id());
        $this->dispatch('composition-dirty');
        session()->flash('design-msg', 'Token overrides cleared. Design reflects the AI-picked brief.');
    }

    public function removeBrief(): void
    {
        if ($this->regenerating) {
            return;
        }

        $site = $this->authorizedSite();

        app(CompositionService::class)->applyAdminChange(
            $site,
            fn () => $site->update(['design_brief' => null]),
            userId: auth()->id(),
        );
        app(CompositionService::class)->clearAllThemeOverrides($site, MutationSource::Admin, auth()->id());
        $this->dispatch('composition-dirty');
        session()->flash('design-msg', 'Design brief removed. Site falls back to the extraction chain.');
    }

    // ─── Regenerate lifecycle ────────────────────────────────────────────

    /**
     * Shared entry point for any action that regenerates the brief. Snapshots
     * the current brief's hash, wipes overrides, dispatches the job, and
     * flips `regenerating = true`. The blade template's `wire:poll` then
     * calls `checkRegenerateProgress` until the hash changes or we time out.
     *
     * $moodHint is forwarded to DesignBriefJob for a user-clicked mood
     * tile; null means pick from the site profile.
     */
    protected function startRegenerate(Site $site, string $flashMessage, ?string $moodHint = null): void
    {
        $colourHints = $this->validatedColourHints();
        if ($colourHints === null) {
            return;
        }

        $this->demoUnavailable('design brief');
    }

    /**
     * @return array{primary?: string, accent?: string}|null
     */
    protected function validatedColourHints(): ?array
    {
        $this->resetErrorBag(['primaryColourHint', 'accentColourHint']);
        $hints = [];

        foreach (['primaryColourHint' => 'primary', 'accentColourHint' => 'accent'] as $property => $key) {
            $value = trim($this->{$property});
            if ($value === '') {
                continue;
            }

            $candidate = ltrim($value, '#');
            $candidate = preg_replace('/[^0-9a-fA-F]/', '', $candidate ?? '');
            if (strlen($candidate) > 6) {
                $candidate = substr($candidate, 0, 6);
            }

            $hex = app(ThemeResolver::class)->normaliseHex($candidate);
            if ($hex === null) {
                $this->addError($property, 'Enter a 3 or 6 digit hex colour.');

                return null;
            }

            $this->{$property} = $hex;
            $hints[$key] = $hex;
        }

        return $hints;
    }

    /**
     * Invoked every REGENERATE_POLL_INTERVAL_MS by wire:poll while the
     * regenerate is in flight. If the brief's hash differs from the
     * snapshot, the job has completed — clear the flag and flash success.
     * If MAX_POLLS elapse without a change, treat as a timeout.
     */
    public function checkRegenerateProgress(): void
    {
        if (! $this->regenerating) {
            return;
        }

        $site = $this->authorizedSite();
        $currentHash = $this->briefHash($site);

        if ($currentHash !== null && $currentHash !== $this->regenerateSnapshot) {
            $this->finishRegenerate(success: true);

            return;
        }

        $this->regeneratePollCount++;
        if ($this->regeneratePollCount >= self::REGENERATE_MAX_POLLS) {
            $this->finishRegenerate(success: false);
        }
    }

    protected function finishRegenerate(bool $success): void
    {
        $this->regenerating = false;
        $this->regenerateSnapshot = null;
        $this->regeneratePollCount = 0;

        if ($success) {
            session()->flash('design-msg', 'Design brief regenerated. Fresh palette, fonts, and layout loaded.');
            $this->dispatch('composition-dirty');
        } else {
            session()->flash('design-error', 'Regenerate timed out. The AI service may be busy — try again in a moment.');
        }
    }

    protected function briefHash(Site $site): ?string
    {
        $brief = $site->fresh()->design_brief;

        return is_array($brief) ? md5((string) json_encode($brief)) : null;
    }

    // ─── Shared helpers ──────────────────────────────────────────────────

    /**
     * @param  array<int, string>|null  $allowlist  if provided, reject values outside it
     */
    protected function saveOverride(string $overrideKey, ?string $value, ?array $allowlist): void
    {
        $site = $this->authorizedSite();

        // Null/empty = clear this override.
        $normalised = is_string($value) ? trim($value) : null;
        if ($normalised === '' || $normalised === null) {
            $normalised = null;
        } elseif ($allowlist !== null && ! in_array($normalised, $allowlist, true)) {
            return;
        }

        $cs = app(CompositionService::class);
        $draft = $cs->getOrCreateDraft($site);
        $cs->updateThemeOverrides(
            $draft,
            [$overrideKey => $normalised],
            MutationSource::Admin,
            auth()->id(),
        );

        $this->dispatch('composition-dirty');
    }

    protected function wipeOverrides(Site $site): void
    {
        app(CompositionService::class)->clearAllThemeOverrides($site, MutationSource::Admin, auth()->id());
    }

    // ─── Data read for render ────────────────────────────────────────────

    /**
     * Current brief (may be null if admin removed it).
     *
     * @return array<string, mixed>|null
     */
    public function brief(): ?array
    {
        return $this->authorizedSite()->fresh()->design_brief;
    }

    /**
     * Current per-token overrides (the subset of composition.theme keys
     * that end in _override). Empty array if no draft or no overrides.
     *
     * @return array<string, string>
     */
    public function overrides(): array
    {
        $site = $this->authorizedSite();
        $theme = $site->fresh()->siteDraft?->composition['theme'] ?? [];
        if (! is_array($theme)) {
            return [];
        }

        $out = [];
        foreach ($theme as $key => $value) {
            if (! is_string($key) || ! str_ends_with($key, '_override')) {
                continue;
            }
            // Accept non-empty strings (colour / font / enum overrides)
            // AND booleans (invert_mode_override). null / empty-string
            // still counts as "unset" so the UI can show the toggle off.
            if (is_string($value) && $value !== '') {
                $out[$key] = $value;
            } elseif (is_bool($value) && $value) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Fully-resolved token set: brief + overrides + defaults.
     * Drives the live preview card.
     *
     * @return array{
     *   palette: array<string,string>,
     *   display_font: string,
     *   body_font: string,
     *   heading_scale: string,
     *   spacing_density: string,
     *   container_width_tier: string,
     *   display_scale: string,
     *   corner_style: string,
     *   font_link_href: string,
     *   display_font_stack: string,
     *   body_font_stack: string,
     *   radius_card: string,
     *   radius_button: string,
     *   section_spacing: string,
     *   container_width: string,
     *   heading_letter_spacing: string,
     *   rationale: ?string,
     * }
     */
    public function resolvedTokens(): array
    {
        $site = $this->authorizedSite()->fresh();
        $profile = $site->businessProfile?->profile_data ?? [];
        $draft = $site->siteDraft;
        $composition = $draft?->composition['theme'] ?? null;

        $themeResolver = app(ThemeResolver::class);
        $theme = $themeResolver->resolve($site, $profile, $composition);
        $render = $themeResolver->renderTokens($theme);

        // Text-safe variants derived by ThemeResolver when the raw brand
        // colour fails WCAG AA against surface. The panel surfaces these so
        // admins see which tokens got shifted and can pick a better primary
        // if they prefer the exact brand hex everywhere.
        $adjustments = [];
        foreach (['primary', 'accent'] as $key) {
            $raw = $render[$key];
            $safe = $render["{$key}_text"];
            if ($raw !== $safe) {
                $adjustments[$key] = [
                    'raw' => $raw,
                    'adjusted' => $safe,
                    'raw_ratio' => round($themeResolver->contrastRatio($raw, $render['surface']), 2),
                    'adjusted_ratio' => round($themeResolver->contrastRatio($safe, $render['surface']), 2),
                ];
            }
        }

        return [
            'palette' => [
                'primary' => $render['primary'],
                'accent' => $render['accent'],
                'tertiary' => $render['tertiary'],
                'surface' => $render['surface'],
                'surface_alt' => $render['surface_alt'],
                'border' => $render['border'],
                'text' => $render['text'],
                'text_muted' => $render['text_muted'],
            ],
            'adjustments' => $adjustments,
            'display_font' => $theme['display_font'] ?? 'inter',
            'body_font' => $theme['body_font'] ?? 'inter',
            'heading_scale' => $theme['heading_scale'] ?? 'balanced',
            'spacing_density' => $theme['spacing_density'] ?? 'balanced',
            'container_width_tier' => $theme['container_width'] ?? 'auto',
            'display_scale' => $theme['display_scale'] ?? 'standard',
            'corner_style' => $theme['corner_style'] ?? 'soft',
            'font_link_href' => $render['font_link_href'],
            'display_font_stack' => $render['display_font_stack'],
            'body_font_stack' => $render['body_font_stack'],
            'radius_card' => $render['radius_card'],
            'radius_button' => $render['radius_button'],
            'section_spacing' => $render['section_spacing'],
            'container_width' => $render['container_width'],
            'heading_letter_spacing' => $render['heading_letter_spacing'],
            'rationale' => is_array($site->design_brief)
                ? (is_string($site->design_brief['rationale'] ?? null) ? $site->design_brief['rationale'] : null)
                : null,
        ];
    }

    /**
     * bunny.net URL loading ALL 11 allowlisted fonts — used to render the
     * font-sample cards in their own face. The public site only loads the
     * two fonts the brief selected; the admin panel needs every option
     * rendered for pick-by-sight.
     */
    public function allFontsLink(): string
    {
        $families = [];
        foreach (DesignBrief::DISPLAY_FONTS as $font) {
            $families[] = "{$font}:400,600,700";
        }
        foreach (DesignBrief::BODY_FONTS as $font) {
            $families[] = "{$font}:400,500,600";
        }

        return 'https://fonts.bunny.net/css?family=' . implode('|', array_unique($families)) . '&display=swap';
    }

    // ─── View data ───────────────────────────────────────────────────────

    public function with(): array
    {
        $brief = $this->brief();
        $overrides = $this->overrides();

        return [
            'brief' => $brief,
            'overrides' => $overrides,
            'resolved' => $this->resolvedTokens(),
            'allFontsLink' => $this->allFontsLink(),
            'moodCopy' => [
                'warm-traditional' => 'Trusted · heritage · family-run',
                'bold-modern' => 'Confident · clean · contemporary',
                'refined-minimal' => 'Calm · editorial · considered',
                'robust-industrial' => 'Solid · workmanlike · tough',
                'friendly-local' => 'Approachable · neighbourhood · warm',
            ],
        ];
    }
};
?>

<div class="space-y-6" x-data
     @if ($regenerating) wire:poll.2000ms="checkRegenerateProgress" @endif>
    {{-- Load all 11 allowlisted fonts so the font-sample cards below can
         render in their own face. Emitted on every render; browser caches. --}}
    <link rel="stylesheet" href="{{ $allFontsLink }}">

    {{-- Pulsing "generating" banner — shown only while a DesignBriefJob is
         in flight. The wrapper above polls every 2s; this banner stays up
         until the brief's hash changes (success) or we time out (error). --}}
    @if ($regenerating)
        <div class="flex items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 shadow-sm dark:border-amber-700 dark:bg-amber-900/20"
             role="status" aria-live="polite">
            <span class="relative flex h-3 w-3 shrink-0">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75"></span>
                <span class="relative inline-flex h-3 w-3 rounded-full bg-amber-500"></span>
            </span>
            <div class="flex-1">
                <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">Generating design…</p>
                <p class="text-xs text-amber-800 dark:text-amber-300">
                    A new palette, fonts, and layout are being prepared. This usually takes 10–20 seconds. Controls are locked until it finishes.
                </p>
            </div>
        </div>
    @endif

    @if (session('design-msg'))
        <flux:callout variant="success" icon="check-circle" class="mb-2">
            {{ session('design-msg') }}
        </flux:callout>
    @endif

    @if (session('design-error'))
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-2">
            {{ session('design-error') }}
        </flux:callout>
    @endif

    @error('editingHex')
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-2">{{ $message }}</flux:callout>
    @enderror

    {{-- ═══════════════════════════════════════════════════════════════
         LIVE PREVIEW — solid surface + primary accent stripe.
         Mirrors the actual hero composition (surface bg, primary accent
         stripe, accent CTA) rather than a decorative gradient. Gradients
         looked fine for bold palettes but washed out for grey/neutral
         primaries — this version reads the same at any contrast.
         ═══════════════════════════════════════════════════════════════ --}}
    <div class="overflow-hidden rounded-2xl border shadow-sm"
         style="background-color: {{ $resolved['palette']['surface'] }};
                border-color: {{ $resolved['palette']['border'] }};">
        <div class="h-1.5 w-full" style="background-color: {{ $resolved['palette']['primary'] }};"></div>
        <div class="grid gap-4 p-8 md:grid-cols-[1fr_auto] md:items-center">
            <div>
                <p class="mb-3 text-xs font-semibold uppercase tracking-widest"
                   style="font-family: {{ $resolved['body_font_stack'] }};
                          color: {{ $resolved['palette']['text_muted'] }};">
                    Live preview
                </p>
                <h3 class="mb-3 text-3xl font-extrabold leading-tight md:text-4xl"
                    style="font-family: {{ $resolved['display_font_stack'] }};
                           letter-spacing: {{ $resolved['heading_letter_spacing'] }};
                           color: {{ $resolved['palette']['text'] }};">
                    Your Trusted <span style="color: {{ $resolved['palette']['accent'] }};">Brand</span> Partner
                </h3>
                <p class="mb-2 max-w-lg text-base leading-relaxed md:text-lg"
                   style="font-family: {{ $resolved['body_font_stack'] }};
                          color: {{ $resolved['palette']['text'] }};">
                    This is how a hero paragraph reads in your chosen body font and size.
                </p>
                <p class="mb-5 max-w-lg text-sm"
                   style="font-family: {{ $resolved['body_font_stack'] }};
                          color: {{ $resolved['palette']['text_muted'] }};">
                    Muted text — captions and secondary prose sit here.
                </p>
                <button type="button"
                        class="inline-flex items-center gap-2 px-6 py-3 text-sm font-bold text-white shadow-lg transition-all hover:brightness-110"
                        style="background-color: {{ $resolved['palette']['accent'] }};
                               border-radius: {{ $resolved['radius_button'] }};
                               font-family: {{ $resolved['body_font_stack'] }};">
                    Sample CTA
                </button>
            </div>

            {{-- Palette strip, compact, always visible in the preview --}}
            <div class="hidden flex-col gap-2 md:flex">
                @foreach ($resolved['palette'] as $token => $hex)
                    <div class="flex items-center gap-2">
                        <div class="h-6 w-6 rounded border"
                             style="background-color: {{ $hex }};
                                    border-color: {{ $resolved['palette']['border'] }};"></div>
                        <span class="text-[10px] font-mono" style="color: {{ $resolved['palette']['text_muted'] }};">{{ $hex }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Rationale callout (if present in the brief) --}}
    @if (! empty($resolved['rationale']))
        <flux:callout variant="secondary" icon="chat-bubble-left-ellipsis">
            <flux:callout.heading>Why these choices</flux:callout.heading>
            <flux:callout.text>{{ $resolved['rationale'] }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($brief === null)
        <div class="rounded-lg border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">
            <p class="mb-3">This demo ships with a fixed design brief.</p>
        </div>
    @else

    {{-- Wrapper: visually locks every interactive control while a
         regenerate is in flight. pointer-events-none + opacity is the
         UX signal; server-side guards on every mutation method are the
         actual defence against double-fires / race conditions. --}}
    <div @class([
        'space-y-6 transition-opacity',
        'pointer-events-none select-none opacity-60' => $regenerating,
    ]) aria-disabled="{{ $regenerating ? 'true' : 'false' }}">

    {{-- ═══════════════════════════════════════════════════════════════
         MOOD — 5 tiles with micro-copy
         ═══════════════════════════════════════════════════════════════ --}}
    <section>
        <h4 class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Mood</h4>
        <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
            Changing mood triggers a regenerate.
        </p>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            @foreach (App\Services\Site\DesignBrief::MOODS as $m)
                @php $isSelected = ($brief['mood'] ?? null) === $m; @endphp
                <x-confirm-button
                    name="select-mood-{{ $m }}"
                    title="Change mood to {{ $m }}?"
                    description="Selecting a new mood regenerates the design brief and clears all per-token overrides."
                    confirmLabel="Change mood"
                    confirmVariant="danger"
                    wire:click="selectMood('{{ $m }}')">
                    <x-slot:trigger>
                        <button type="button"
                                @class([
                                    'rounded-xl border-2 p-4 text-left transition-all hover:border-zinc-400',
                                    'border-amber-500 bg-amber-50 ring-2 ring-amber-300 dark:border-amber-400 dark:bg-amber-900/20 dark:ring-amber-600' => $isSelected,
                                    'border-zinc-200 dark:border-neutral-700' => ! $isSelected,
                                ])>
                            <div class="mb-1 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ ucfirst(str_replace('-', ' ', $m)) }}
                            </div>
                            <div class="text-[11px] leading-tight text-zinc-500 dark:text-zinc-400">
                                {{ $moodCopy[$m] ?? '' }}
                            </div>
                        </button>
                    </x-slot:trigger>
                </x-confirm-button>
            @endforeach
        </div>
    </section>

    {{-- ═══════════════════════════════════════════════════════════════
         DISPLAY FONT — cards rendered in their own face
         ═══════════════════════════════════════════════════════════════ --}}
    <section>
        <div class="mb-3 flex items-center justify-between">
            <h4 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Display font</h4>
            @if (isset($overrides['display_font_override']))
                <flux:button size="xs" variant="ghost" wire:click="selectDisplayFont('')">Reset override</flux:button>
            @endif
        </div>
        <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach (App\Services\Site\DesignBrief::DISPLAY_FONTS as $f)
                @php
                    $isResolved = $resolved['display_font'] === $f;
                    $isOverride = ($overrides['display_font_override'] ?? null) === $f;
                    $faceName = ucwords(str_replace('-', ' ', $f));
                @endphp
                <button type="button"
                        wire:click="selectDisplayFont('{{ $f }}')"
                        @class([
                            'rounded-xl border-2 p-4 text-center transition-all hover:border-zinc-400',
                            'border-amber-500 bg-amber-50 ring-2 ring-amber-300 dark:border-amber-400 dark:bg-amber-900/20 dark:ring-amber-600' => $isResolved,
                            'border-zinc-200 dark:border-neutral-700' => ! $isResolved,
                        ])>
                    <div class="text-4xl font-bold leading-none"
                         style="font-family: '{{ $faceName }}', Georgia, serif;">Aa</div>
                    <div class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $faceName }}</div>
                    @if ($isOverride)
                        <div class="mt-1 text-[9px] uppercase tracking-wide text-amber-600 dark:text-amber-400">Override</div>
                    @endif
                </button>
            @endforeach
        </div>
    </section>

    {{-- ═══════════════════════════════════════════════════════════════
         BODY FONT — same card pattern
         ═══════════════════════════════════════════════════════════════ --}}
    <section>
        <div class="mb-3 flex items-center justify-between">
            <h4 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Body font</h4>
            @if (isset($overrides['body_font_override']))
                <flux:button size="xs" variant="ghost" wire:click="selectBodyFont('')">Reset override</flux:button>
            @endif
        </div>
        <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
            @foreach (App\Services\Site\DesignBrief::BODY_FONTS as $f)
                @php
                    $isResolved = $resolved['body_font'] === $f;
                    $isOverride = ($overrides['body_font_override'] ?? null) === $f;
                    $faceName = ucwords(str_replace('-', ' ', $f));
                @endphp
                <button type="button"
                        wire:click="selectBodyFont('{{ $f }}')"
                        @class([
                            'rounded-xl border-2 p-3 text-center transition-all hover:border-zinc-400',
                            'border-amber-500 bg-amber-50 ring-2 ring-amber-300 dark:border-amber-400 dark:bg-amber-900/20 dark:ring-amber-600' => $isResolved,
                            'border-zinc-200 dark:border-neutral-700' => ! $isResolved,
                        ])>
                    <div class="text-xl font-medium"
                         style="font-family: '{{ $faceName }}', system-ui, sans-serif;">Aa</div>
                    <div class="mt-1 text-[11px] leading-tight text-zinc-500 dark:text-zinc-400"
                         style="font-family: '{{ $faceName }}', system-ui, sans-serif;">
                        The quick brown fox
                    </div>
                    <div class="mt-1 text-[10px] text-zinc-400">{{ $faceName }}</div>
                    @if ($isOverride)
                        <div class="mt-1 text-[9px] uppercase tracking-wide text-amber-600 dark:text-amber-400">Override</div>
                    @endif
                </button>
            @endforeach
        </div>
    </section>

    {{-- ═══════════════════════════════════════════════════════════════
         PALETTE — big swatches with WCAG contrast badges
         ═══════════════════════════════════════════════════════════════ --}}
    <section>
        @php $isInverted = (bool) ($overrides['invert_mode_override'] ?? false); @endphp
        {{-- Light ⇆ Dark invert. Flips surface / surface_alt / text /
             text_muted / border via an HSL-lightness swap; brand colours
             stay put. Saves to composition.theme so the public preview
             URL also serves the flipped palette after the toggle. --}}
        <div class="mb-4 flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
            <div>
                <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Light ⇆ Dark</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    Flip surface + text tokens without changing brand colours. Saves to the site.
                </p>
            </div>
            <button type="button" wire:click="toggleInvertMode"
                    @class([
                        'relative inline-flex h-6 w-11 items-center rounded-full transition-colors cursor-pointer',
                        'bg-zinc-900 dark:bg-white' => $isInverted,
                        'bg-zinc-300 dark:bg-neutral-600' => ! $isInverted,
                    ])
                    role="switch"
                    aria-checked="{{ $isInverted ? 'true' : 'false' }}"
                    aria-label="{{ $isInverted ? 'Disable inverted (dark) surface mode' : 'Enable inverted (dark) surface mode' }}"
                    title="{{ $isInverted ? 'Currently inverted — click to return to light' : 'Currently light — click to invert' }}">
                <span @class([
                    'inline-block h-4 w-4 transform rounded-full bg-white dark:bg-zinc-900 shadow transition-transform',
                    'translate-x-6' => $isInverted,
                    'translate-x-1' => ! $isInverted,
                ])></span>
            </button>
        </div>

        @php $brandSectionScheme = ($overrides['brand_section_scheme_override'] ?? null) === 'soft' ? 'soft' : 'bold'; @endphp
        <div class="mb-4 flex items-center justify-between gap-4 rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-neutral-700 dark:bg-neutral-800">
            <div>
                <p class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Brand sections</p>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Choose saturated brand bands or light-tinted feature surfaces.</p>
            </div>
            <div class="inline-flex rounded-lg border border-zinc-300 bg-white p-0.5 dark:border-neutral-600 dark:bg-neutral-900" role="group" aria-label="Brand sections">
                @foreach (['bold' => 'Bold', 'soft' => 'Soft'] as $scheme => $label)
                    <button type="button" wire:click="selectBrandSectionScheme('{{ $scheme }}')"
                            @class([
                                'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                                'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $brandSectionScheme === $scheme,
                                'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white' => $brandSectionScheme !== $scheme,
                            ])
                            aria-pressed="{{ $brandSectionScheme === $scheme ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>

        @if (! empty($resolved['adjustments']))
            <div class="mb-3 rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-900/20">
                <div class="flex items-start gap-2">
                    <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-700 dark:text-amber-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M4.93 19.07h14.14c1.54 0 2.5-1.67 1.73-3L13.73 3.93a2 2 0 00-3.46 0L3.2 16.07c-.77 1.33.19 3 1.73 3z"/>
                    </svg>
                    <div class="flex-1 text-xs">
                        <p class="font-semibold text-amber-900 dark:text-amber-200">Some brand colours adjusted for text contrast</p>
                        <p class="mt-1 text-amber-800 dark:text-amber-300">
                            Fills, buttons, and borders still use your picked colour — only text contexts (logo wordmark, rich-text links, big stat numbers) use a darker/lighter variant that passes WCAG AA 4.5:1 against surface.
                        </p>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1.5">
                            @foreach ($resolved['adjustments'] as $token => $a)
                                <span class="inline-flex items-center gap-1.5 text-[11px] font-mono text-amber-900 dark:text-amber-200">
                                    <span class="font-sans font-semibold capitalize">{{ $token }}:</span>
                                    <span class="inline-block h-3 w-3 rounded border border-amber-700" style="background-color: {{ $a['raw'] }};" title="Picked"></span>
                                    <span>{{ $a['raw'] }}</span>
                                    <span class="text-[10px] text-amber-700 dark:text-amber-400">({{ $a['raw_ratio'] }}:1)</span>
                                    <span class="text-amber-700 dark:text-amber-400">→</span>
                                    <span class="inline-block h-3 w-3 rounded border border-amber-700" style="background-color: {{ $a['adjusted'] }};" title="Text-safe"></span>
                                    <span>{{ $a['adjusted'] }}</span>
                                    <span class="text-[10px] text-amber-700 dark:text-amber-400">({{ $a['adjusted_ratio'] }}:1)</span>
                                </span>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endif
        <div class="mb-3 flex items-center justify-between">
            <h4 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Palette</h4>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Click a swatch to edit. Contrast pairs each token with its natural counterpart (Surface vs Text, Text vs Surface, etc).</p>
        </div>
        <div class="grid gap-3 sm:grid-cols-4 lg:grid-cols-8">
            @foreach ($resolved['palette'] as $token => $hex)
                @php
                    $label = ucwords(str_replace('_', ' ', $token));
                    $overrideKey = "{$token}_override";
                    $isOverride = isset($overrides[$overrideKey]);
                    // Pair each token with the colour it's actually going to
                    // sit against in the rendered site. Surface tokens check
                    // "can text be read on me?"; all other tokens check "am I
                    // readable on the page surface?". Avoids the nonsense
                    // 1.0 FAIL badge when a token is compared against itself.
                    $surface = $resolved['palette']['surface'];
                    $text = $resolved['palette']['text'];
                    $pairHex = in_array($token, ['surface', 'surface_alt'], true)
                        ? $text
                        : $surface;
                    $pairLabel = in_array($token, ['surface', 'surface_alt'], true)
                        ? 'Text'
                        : 'Surface';
                    $contrastRatio = designPanelContrast($hex, $pairHex);
                    $contrastLabel = designPanelContrastLabel($contrastRatio);
                    $contrastClass = designPanelContrastClass($contrastRatio);
                @endphp
                <div class="relative flex flex-col">
                    <button type="button" wire:click="editPaletteToken('{{ $token }}')"
                            class="group relative h-20 w-full overflow-hidden rounded-lg border-2 border-zinc-200 transition-all hover:scale-105 hover:border-zinc-400 dark:border-neutral-700"
                            style="background-color: {{ $hex }};"
                            title="Edit {{ $label }}">
                        @if ($isOverride)
                            <span class="absolute right-1 top-1 rounded bg-amber-500/90 px-1.5 py-0.5 text-[9px] font-bold uppercase text-white shadow">
                                Override
                            </span>
                        @endif
                    </button>
                    <div class="mt-1 flex items-center justify-between">
                        <span class="text-[11px] font-medium text-zinc-700 dark:text-zinc-200">{{ $label }}</span>
                        <span class="rounded px-1.5 py-0.5 text-[9px] font-bold {{ $contrastClass }}"
                              title="Contrast vs {{ $pairLabel }}">{{ $contrastLabel }}</span>
                    </div>
                    <div class="text-[10px] font-mono text-zinc-500 dark:text-zinc-400">{{ $hex }}</div>
                    @if ($isOverride)
                        <button type="button" wire:click="clearPaletteOverride('{{ $token }}')"
                                class="mt-1 text-[9px] text-zinc-500 underline hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                            Reset to brief
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    {{-- Palette edit popover --}}
    @if ($editingToken !== null)
        @php $tokenLabel = ucwords(str_replace('_', ' ', $editingToken)); @endphp
        {{-- @click.self means the backdrop close only fires when the
             click lands DIRECTLY on the backdrop div — not when it
             bubbles up from a child. No race with Apply's wire:click
             regardless of how Flux renders the button wrappers. Earlier
             @click.stop-on-inner pattern was unreliable because Flux
             buttons render extra nested elements whose click events
             fired BEFORE Alpine's stop modifier registered. --}}
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" x-data @click.self="$wire.clearPaletteEdit()">
            <div class="w-full max-w-sm rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                <h3 class="mb-3 text-lg font-semibold text-zinc-900 dark:text-zinc-100">Edit {{ $tokenLabel }}</h3>
                <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">
                    Sets an override that wins over the design brief. Any other site rendering continues to use the brief value.
                </p>
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <input type="color" wire:model.live="editingHex"
                               class="h-12 w-16 cursor-pointer rounded border border-zinc-200 bg-transparent p-0 dark:border-neutral-700">
                        <input type="text" wire:model.live="editingHex"
                               class="flex-1 rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm font-mono dark:border-neutral-700 dark:bg-neutral-800"
                               placeholder="#aabbcc">
                    </div>
                    <div class="flex h-12 items-center justify-center rounded-md border border-zinc-200 text-xs font-mono dark:border-neutral-700"
                         style="background-color: {{ $editingHex }}; color: {{ designPanelContrast($editingHex, '#ffffff') > 4.5 ? '#ffffff' : '#000000' }};">
                        {{ $editingHex }}
                    </div>
                    {{-- In-modal error surface. The page-root @error
                         callout is hidden behind the modal backdrop, so
                         Apply-failures were invisible to the user. --}}
                    @error('editingHex')
                        <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
                <div class="mt-6 flex items-center justify-end gap-2">
                    <flux:button size="sm" variant="ghost" wire:click="clearPaletteEdit">Cancel</flux:button>
                    <flux:button size="sm" variant="primary" wire:click="applyPaletteEdit">Apply</flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════════
         LAYOUT — visual chips (heading / spacing / corners)
         ═══════════════════════════════════════════════════════════════ --}}
    <section>
        <h4 class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Layout</h4>

        {{-- Heading scale --}}
        <div class="mb-4">
            <div class="mb-2 flex items-center justify-between">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Heading scale — how tight the H1→H6 ramp feels</p>
                @if (isset($overrides['heading_scale_override']))
                    <flux:button size="xs" variant="ghost" wire:click="selectHeadingScale('')">Reset override</flux:button>
                @endif
            </div>
            <div class="grid gap-2 sm:grid-cols-3">
                @foreach (App\Services\Site\DesignBrief::HEADING_SCALES as $s)
                    @php $isActive = $resolved['heading_scale'] === $s; @endphp
                    <button type="button" wire:click="selectHeadingScale('{{ $s }}')"
                            @class([
                                'rounded-lg border-2 p-3 text-left transition-all hover:border-zinc-400',
                                'border-amber-500 bg-amber-50 dark:border-amber-400 dark:bg-amber-900/20' => $isActive,
                                'border-zinc-200 dark:border-neutral-700' => ! $isActive,
                            ])>
                        <div class="mb-1 text-xs font-semibold capitalize text-zinc-900 dark:text-zinc-100">{{ $s }}</div>
                        <div style="letter-spacing: {{ ['tight' => '-0.02em', 'balanced' => '-0.01em', 'relaxed' => '0'][$s] }};">
                            <div class="text-lg font-bold text-zinc-900 dark:text-zinc-100">Heading</div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">Subhead</div>
                        </div>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Display scale --}}
        <div class="mb-4">
            <div class="mb-2 flex items-center justify-between">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Display scale: Standard / Grand</p>
                @if (isset($overrides['display_scale_override']))
                    <flux:button size="xs" variant="ghost" wire:click="selectDisplayScale('')">Reset override</flux:button>
                @endif
            </div>
            <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400">Grand widens the shell and scales headings and spacing together. Individual settings still override.</p>
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach (App\Services\Site\ThemeResolver::DISPLAY_SCALES as $s)
                    @php $isActive = $resolved['display_scale'] === $s; @endphp
                    <button type="button" wire:click="selectDisplayScale('{{ $s }}')"
                            @class([
                                'rounded-lg border-2 p-3 text-left transition-all hover:border-zinc-400',
                                'border-amber-500 bg-amber-50 dark:border-amber-400 dark:bg-amber-900/20' => $isActive,
                                'border-zinc-200 dark:border-neutral-700' => ! $isActive,
                            ])>
                        <div class="text-xs font-semibold capitalize text-zinc-900 dark:text-zinc-100">{{ $s }}</div>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Spacing density --}}
        <div class="mb-4">
            <div class="mb-2 flex items-center justify-between">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Spacing density — room between sections</p>
                @if (isset($overrides['spacing_density_override']))
                    <flux:button size="xs" variant="ghost" wire:click="selectSpacingDensity('')">Reset override</flux:button>
                @endif
            </div>
            <div class="grid gap-2 sm:grid-cols-3">
                @foreach (App\Services\Site\DesignBrief::SPACING_DENSITIES as $s)
                    @php $isActive = $resolved['spacing_density'] === $s; @endphp
                    <button type="button" wire:click="selectSpacingDensity('{{ $s }}')"
                            @class([
                                'rounded-lg border-2 p-3 transition-all hover:border-zinc-400',
                                'border-amber-500 bg-amber-50 dark:border-amber-400 dark:bg-amber-900/20' => $isActive,
                                'border-zinc-200 dark:border-neutral-700' => ! $isActive,
                            ])>
                        <div class="mb-2 text-xs font-semibold capitalize text-zinc-900 dark:text-zinc-100">{{ $s }}</div>
                        <div class="flex flex-col" style="gap: {{ ['compact' => '0.25rem', 'balanced' => '0.5rem', 'generous' => '0.75rem'][$s] }};">
                            <div class="h-2 w-full rounded bg-zinc-300 dark:bg-neutral-600"></div>
                            <div class="h-2 w-full rounded bg-zinc-300 dark:bg-neutral-600"></div>
                            <div class="h-2 w-full rounded bg-zinc-300 dark:bg-neutral-600"></div>
                        </div>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Container width --}}
        <div class="mb-4">
            <div class="mb-2 flex items-center justify-between">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Container width — independent of spacing density</p>
                @if (isset($overrides['container_width_override']))
                    <flux:button size="xs" variant="ghost" wire:click="selectContainerWidth('')">Reset override</flux:button>
                @endif
            </div>
            <div class="grid gap-2 sm:grid-cols-4">
                @foreach (App\Services\Site\ThemeResolver::CONTAINER_WIDTHS as $s)
                    @php $isActive = $resolved['container_width_tier'] === $s; @endphp
                    <button type="button" wire:click="selectContainerWidth('{{ $s }}')"
                            @class([
                                'rounded-lg border-2 p-3 text-left transition-all hover:border-zinc-400',
                                'border-amber-500 bg-amber-50 dark:border-amber-400 dark:bg-amber-900/20' => $isActive,
                                'border-zinc-200 dark:border-neutral-700' => ! $isActive,
                            ])>
                        <div class="text-xs font-semibold capitalize text-zinc-900 dark:text-zinc-100">{{ $s }}</div>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Corner style --}}
        <div>
            <div class="mb-2 flex items-center justify-between">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Corners — card + button radius</p>
                @if (isset($overrides['corner_style_override']))
                    <flux:button size="xs" variant="ghost" wire:click="selectCornerStyle('')">Reset override</flux:button>
                @endif
            </div>
            <div class="grid gap-2 sm:grid-cols-3">
                @foreach (App\Services\Site\DesignBrief::CORNER_STYLES as $s)
                    @php $isActive = $resolved['corner_style'] === $s; @endphp
                    <button type="button" wire:click="selectCornerStyle('{{ $s }}')"
                            @class([
                                'rounded-lg border-2 p-3 transition-all hover:border-zinc-400',
                                'border-amber-500 bg-amber-50 dark:border-amber-400 dark:bg-amber-900/20' => $isActive,
                                'border-zinc-200 dark:border-neutral-700' => ! $isActive,
                            ])>
                        <div class="mb-2 text-xs font-semibold capitalize text-zinc-900 dark:text-zinc-100">{{ $s }}</div>
                        <div class="h-8 w-full bg-zinc-300 dark:bg-neutral-600"
                             style="border-radius: {{ ['sharp' => '2px', 'soft' => '10px', 'rounded' => '9999px'][$s] }};"></div>
                    </button>
                @endforeach
            </div>
        </div>
    </section>

    <section class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-neutral-700 dark:bg-neutral-900">
        <div class="mb-3">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Brand colour seeds</h3>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Optional anchors for the next brief. Mood changes and re-rolls keep these colours recognisably present.</p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ([
                'primaryColourHint' => ['label' => 'Primary', 'fallback' => '#000000'],
                'accentColourHint' => ['label' => 'Secondary / Accent', 'fallback' => '#000000'],
            ] as $property => $input)
                <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-200">
                    {{ $input['label'] }}
                    <div class="mt-1 flex items-center gap-2">
                        <input type="color" wire:model.live="{{ $property }}"
                               value="{{ ${$property} ?: $input['fallback'] }}"
                               class="h-10 w-12 cursor-pointer rounded border border-zinc-200 bg-transparent p-0 dark:border-neutral-700">
                        <input type="text" wire:model.live="{{ $property }}" placeholder="Optional #aabbcc"
                               class="min-w-0 flex-1 rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm font-mono dark:border-neutral-700 dark:bg-neutral-800">
                    </div>
                    @error($property) <span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                </label>
            @endforeach
        </div>
    </section>

    {{-- ═══════════════════════════════════════════════════════════════
         ACTION BAR
         ═══════════════════════════════════════════════════════════════ --}}
    <section class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-neutral-700 dark:bg-neutral-900">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                Every token change saves instantly.<br>Overrides are per-token.
            </div>
            <div class="flex flex-wrap gap-2">
                @unless ($demo)
                <x-confirm-button
                    name="regenerate-brief-panel"
                    icon="sparkles"
                    size="sm"
                    triggerVariant="primary"
                    triggerLabel="Regenerate brief"
                    title="Regenerate design brief?"
                    description="A new brief will be generated and all per-token overrides will be cleared."
                    confirmLabel="Regenerate"
                    confirmVariant="danger"
                    wire:click="regenerateBrief"
                />
                @endunless
                <x-confirm-button
                    name="reset-overrides"
                    size="sm"
                    triggerVariant="ghost"
                    triggerLabel="Reset overrides"
                    title="Clear all token overrides?"
                    description="All per-token customisations will be removed and the AI-picked brief values will be restored."
                    confirmLabel="Clear overrides"
                    confirmVariant="danger"
                    wire:click="resetOverrides"
                />
                @unless ($demo)
                <x-confirm-button
                    name="remove-brief"
                    size="sm"
                    triggerVariant="danger"
                    triggerLabel="Remove design brief"
                    title="Remove design brief?"
                    description="The brief will be deleted and the site will fall back to the extraction chain with preset colours."
                    confirmLabel="Remove"
                    confirmVariant="danger"
                    wire:click="removeBrief"
                />
                @endunless
            </div>
        </div>
    </section>

    </div>{{-- end interactive wrapper --}}

    @endif
</div>

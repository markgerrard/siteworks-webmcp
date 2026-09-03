@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';

        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    // Section-level toggle: agent can hide the hero image entirely. When
    // false, render only the coloured band with title + subtitle.
    // Default true (preserves earlier behaviour).
    $heroEnabled = $section['hero_enabled'] ?? true;

    // $heroImageUrl arrives as an array from page-manager:
    //   { url, watermark_url, prompt, model, text_zone,
    //     bg_position_y, overlay_direction, overlay_strength, text_color, ... }
    // Pull placement fields from it so the agent's CP edits flow through.
    $heroData = is_array($heroImageUrl ?? null) ? $heroImageUrl : [];

    $watermarkOn = (bool) ($profile['watermark_enabled'] ?? true);
    $heroImage = null;
    if ($heroEnabled && ! empty($heroData)) {
        $heroImage = ($watermarkOn && ! empty($heroData['watermark_url']))
            ? $heroData['watermark_url']
            : ($heroData['url'] ?? null);
    } elseif ($heroEnabled && is_string($heroImageUrl ?? null)) {
        $heroImage = $heroImageUrl;
    }

    // Placement: only read bg_position_y (the functional crop control).
    // Design opinions from the vision analyser (text_color, overlay_*)
    // are deliberately ignored — same stance as site/sections/hero.blade.php.
    // Visual consistency across pages beats per-image optimisation.
    $placement = $heroData['placement'] ?? $heroData;
    $cropY = (int) ($placement['bg_position_y'] ?? $heroData['bg_position_y'] ?? 50);

    // Canonical hero treatment — matches hero.blade.php exactly:
    // hardcoded white text, dark drop-shadow, plain black left-to-right
    // gradient. No theme-token coupling, no per-image variability. This
    // file went through two earlier scrim attempts (—color-primary, then
    // —color-surface) trying to be brand-aware; both failed under the
    // long tail of palette combinations (saturated-primary haze on the
    // first; light-surface white-wash on the second). The lesson:
    // mirror the service-page hero, don't invent.
    $title = $section['title'] ?? 'Our Projects';
    $subtitle = $section['subtitle'] ?? '';
@endphp

{{-- Compact projects-page hero. Honours all per-page hero placement
     fields (text_zone, crop_y, overlay_direction/strength, text_color)
     so the agent CP's hero controls flow through end-to-end. When the
     section's hero_enabled flag is false, renders the coloured band
     only — no image, no scrim. --}}
@php
    // Match the site's inner-page hero height so Our Work sits at the same
    // height as the service pages. Falls back to the original 30vh when a
    // site has no hero_sizes configured, so nothing moves on sites that
    // never set one.
    // Default mirrors hero.blade.php's inner default (35vh) — a 30vh
    // fallback left projects heroes visibly shorter than service heroes
    // on sites with no hero_sizes profile.
    $projectsHeroH = $profile['hero_sizes']['inner'] ?? '35vh';
    $projectsHeroH = is_string($projectsHeroH) && preg_match('/^\d{1,3}vh$/', $projectsHeroH)
        ? $projectsHeroH
        : '35vh';
    // Height lives on the copy container (mirrors hero.blade.php) so the
    // accent bar — a flow sibling after the copy — sits flush below the
    // band instead of being clipped by overflow-hidden on a fixed-height
    // wrapper. Overlay: min-height (header offset); non-overlay: height.
    $projectsHeroCopyStyle = ($effectiveOverlay ?? false)
        ? 'min-height: calc('.$projectsHeroH.' + var(--overlay-header-h, 0px));'
        : 'height: '.$projectsHeroH.'; min-height: 260px;';
@endphp
<div class="relative overflow-hidden w-full"
     style="background-color: var(--color-surface);">

    {{-- Designed empty-state for the window between page publish and
         RegenerateHeroImageJob completion. Without this the hero
         rendered as an empty dark slab with floating text, which read
         as broken to anyone who clicked the preview URL within ~30s
         of generation. The diagonal accent gradient gives the page a
         deliberate-feeling moment instead. --}}
    @if (! $heroImage)
        <div class="absolute inset-0"
             style="background: linear-gradient(
                135deg,
                color-mix(in oklab, var(--color-accent) 18%, var(--color-surface)) 0%,
                var(--color-surface) 70%
             );">
        </div>
    @endif

    @if ($heroImage)
        <img class="absolute inset-0 w-full h-full object-cover"
             style="object-position: 50% {{ $cropY }}%;"
             src="{{ $heroImage }}"
             alt="">
        {{-- Canonical scrim — same Tailwind class hero.blade.php uses.
             Plain black at 70/40/0% L-to-R. No tokens, no per-image
             variability. White text + drop-shadow on top is then
             reliably legible across every palette. --}}
        <div class="absolute inset-0 bg-gradient-to-r from-black/70 via-black/40 to-transparent"></div>
    @endif

    <div class="relative site-shell-container px-4 sm:px-6 lg:px-8 flex flex-col justify-center{{ ($effectiveOverlay ?? false) ? ' overlay-hero-copy-inner' : '' }}"
         style="{{ $projectsHeroCopyStyle }}">
        <div class="max-w-2xl text-left">
            @php
                // Drop-shadow only when text rides a real photo — matches
                // hero.blade.php exactly.
                $textShadowClass = $heroImage ? 'drop-shadow-[0_2px_8px_rgba(0,0,0,0.7)]' : '';
            @endphp
            <h1 class="text-3xl md:text-4xl lg:text-5xl font-extrabold leading-tight text-pretty mb-3 text-white {{ $textShadowClass }}"
                style="font-family: var(--font-display);"
                {!! $editor('title', 'plain') !!}>
                {!! app(\App\Services\Site\AccentWordRenderer::class)->wrap($title, $section['accent_word'] ?? null, isset($site) && \App\Support\ChromeKnobs::accentStyle($site) === 'italic' ? 'italic' : null, $section['accent_ranges'] ?? null) !!}
            </h1>

            @if ($subtitle)
                <p class="text-base md:text-lg max-w-2xl text-white/90 {{ $textShadowClass }}"
                   style="font-family: var(--font-body);"
                   {!! $editor('subtitle', 'plain') !!}>
                    {{ $subtitle }}
                </p>
            @endif
        </div>
    </div>
    {{-- Bottom accent bar — relative z-[3] keeps it above the absolute
         image/scrim layers; it flows naturally below the inner div. --}}<div class="relative z-[3] h-1.5" style="background-color: var(--brand-accent);"></div>
</div>

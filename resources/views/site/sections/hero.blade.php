@php
    // Multi-slide hero scenes — when sites.home_hero_scene is populated
    // and has 2+ slides, render via the dedicated _hero_scene partial.
    // Single-slide scenes (and null scene) fall through to the legacy
    // single-asset rendering below so existing sites are untouched.
    // Only the home hero participates; inner pages stay single-asset.
    $heroSceneResolved = null;
    if (($pageType ?? 'home') === 'home' && isset($site)) {
        $heroSceneResolved = app(\App\Services\Site\HeroSceneService::class)->resolve($site, [
            'heading' => $section['title'] ?? null,
            'subheading' => $section['subtitle'] ?? null,
            'cta_label' => $section['cta_label'] ?? null,
        ], $useDraftAssets ?? false, $page ?? null, $heroState ?? null);
    }
    // Use the scene partial whenever we have a non-legacy resolved scene
    // (i.e. the agent has explicitly configured one), regardless of slide
    // count. The earlier `count > 1` gate dropped 1-slide scenes back into
    // the legacy renderer — invisible-edit bug, since the studio's default
    // initial state is exactly one slide. is_legacy distinguishes
    // resolver-derived single-slide states (which should keep falling
    // through) from agent-configured ones.
    $heroUsesSceneRenderer = $heroSceneResolved
        && ! ($heroSceneResolved['is_legacy'] ?? false)
        && ! empty($heroSceneResolved['slides']);
    if ($heroUsesSceneRenderer) {
        $heroSceneSizes = $profile['hero_sizes'] ?? [];
        $heroSceneH = $heroSceneSizes['home'] ?? '55vh';
        $heroSceneMinH = '280px';
        // Showcase boxed-left: taller floor so the left panel has breathing
        // room (~139px @ 1440x900). Browser-approved 68vh; partial still
        // emits min-height only (no fixed height) so content can grow.
        $heroCopyVariant = \App\Support\ChromeKnobs::heroCopyVariant($site, $section['variant'] ?? null);
        if (in_array($heroCopyVariant, ['boxed-left', 'panel-left'], true)) {
            $heroSceneH = '68vh';
        }
        // Optional per-site height from home_hero_scene (validated in
        // HeroSceneService — never raw JSON). Overrides all scene modes.
        if (! empty($heroSceneResolved['height'])) {
            $heroSceneH = $heroSceneResolved['height'];
        }
    }
@endphp

@if ($heroUsesSceneRenderer)
    @include('site.sections._hero_scene', [
        'scene' => $heroSceneResolved,
        'heroH' => $heroSceneH,
        'heroMinH' => $heroSceneMinH,
        // Pass the legacy hero section's user-edited eyebrow + accent word
        // through so the scene partial can prefer the override over the
        // auto "Trusted in {area}" fallback AND keep the brand accent
        // wrapping on slide headings (otherwise the public site loses the
        // styled accent word the moment scene mode flips on).
        'sceneEyebrowOverride' => $section['eyebrow'] ?? null,
        'sceneAccentWord' => $section['accent_word'] ?? null,
        'effectiveOverlay' => $effectiveOverlay ?? false,
    ])
    @php return; @endphp
@endif

@php
    // Editor helper. Schema declares: title, subtitle, cta_label, cta_url, background_image.
    $editor = function ($field, $type, $valueDoc = null) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';
        $attrs = ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
        if ($type === 'rich' && $valueDoc !== null) {
            $attrs .= ' data-editable-doc="'.e(json_encode($valueDoc)).'"';
        }
        return $attrs;
    };

    // Placeholder hero (e.g. article pages before a hero image is generated) — skip image lookup.
    $isPlaceholder = ! empty($section['placeholder']);

    // Use heroImageUrl passed from page.blade.php (resolved per page_type).
    // Array shape: ['url' => ..., 'watermark_url' => ...]. Choose the
    // watermarked URL when the preview watermark flag is on so admins
    // can toggle the PREVIEW text on/off via the page-manager control.
    $bgImage = $isPlaceholder ? null : ($heroImageUrl ?? $section['background_image'] ?? null);
    if (is_array($bgImage)) {
        $watermarkOn = (bool) ($profile['watermark_enabled'] ?? true);
        $bgImage = ($watermarkOn && ! empty($bgImage['watermark_url']))
            ? $bgImage['watermark_url']
            : ($bgImage['url'] ?? null);
    }

    $isHomePage = ($pageType ?? 'home') === 'home';

    // Read placement data so the agent CP's text-zone picker + crop slider
    // actually move the rendered text + crop. Vision-analyser writes these
    // initial values; agent CP overrides via mutateActiveHeroPlacement.
    // Available where heroImageUrl arrived as an array (always true on
    // versioned-renderer paths; legacy snapshot paths can flatten too).
    $heroPlacement = is_array($heroImageUrl ?? null)
        ? ($heroImageUrl['placement'] ?? $heroImageUrl)
        : [];
    $textZone = $heroPlacement['text_zone'] ?? 'middle-left';
    [$textRow, $textCol] = array_pad(explode('-', $textZone), 2, 'left');
    $cropY = (int) ($heroPlacement['bg_position_y'] ?? 50);

    // White text + dark drop-shadow + dark gradient is the canonical
    // hero treatment across the site. The vision analyser's text_color
    // recommendation is intentionally NOT honoured here — visual
    // consistency between pages matters more than per-image colour
    // optimisation. (If we ever want per-page text colour, we'd also
    // need to flip the gradient + drop-shadow to match — see git history
    // on this file for the dark-text scrim variant.)
    $textColor = 'white';
    $textColorClass = 'text-white';
    $subTextClass = 'text-white/80';
    $textShadow = 'drop-shadow-[0_2px_8px_rgba(0,0,0,0.7)]';
    $gradientClass = 'bg-gradient-to-r from-black/70 via-black/40 to-transparent';

    // text_zone → flex / text-align mapping (matches projects_hero pattern).
    $verticalClass = match ($textRow) {
        'top' => 'justify-start',
        'bottom' => 'justify-end',
        default => 'justify-center',
    };
    $horizontalClass = match ($textCol) {
        'right' => 'text-right ml-auto',
        'center' => 'text-center mx-auto',
        default => 'text-left',
    };
    $flexJustifyClass = match ($textCol) {
        'right' => 'sm:justify-end',
        'center' => 'sm:justify-center',
        default => '',
    };

    $paddingClass = 'py-28 md:py-36 lg:py-40';
    // Crop Y from agent CP — applies as background-position.
    $bgPosition = "center {$cropY}%";
    $heroSizes = $profile['hero_sizes'] ?? [];
    $heroH = $isHomePage
        ? ($heroSizes['home'] ?? '55vh')
        : ($heroSizes['inner'] ?? '35vh');
    $heroMinH = $isHomePage ? '280px' : '180px';

    // Copy treatment on the non-scene path (scene early-returns above).
    // Knob boxed = compact painted box hugging the copy (color-mix, ~36rem,
    // chrome-recipe radius) — not a centred boxed title.
    // Knob panel = full-height left panel band.
    // Preset boxed-left / panel-left keep the historical shared 44rem left
    // panel so recipe output stays byte-identical.
    $heroVariant = isset($site)
        ? \App\Support\ChromeKnobs::heroCopyVariant($site, $section['variant'] ?? null)
        : ($section['variant'] ?? null);
    $heroCopyKnob = isset($site) ? \App\Support\ChromeKnobs::heroCopyStyle($site) : 'preset';
    $isBoxedLeft = $heroVariant === 'boxed-left';
    $isPanelLeft = $heroVariant === 'panel-left';
    $heroTitleLines = (! empty($section['title_lines']) && is_array($section['title_lines']))
        ? array_values(array_filter($section['title_lines'], fn ($s) => is_string($s) && trim($s) !== ''))
        : [];
    $heroHasCopy = $heroTitleLines !== []
        || trim((string) ($section['title'] ?? '')) !== ''
        || trim((string) ($section['subtitle'] ?? '')) !== ''
        || trim((string) ($section['cta_label'] ?? '')) !== ''
        || trim((string) ($section['eyebrow'] ?? '')) !== '';
    $usesLeftPanel = ($isBoxedLeft || $isPanelLeft) && ($heroCopyKnob === 'preset' || $heroHasCopy);
    $heroAlignAttr = '';
    if ($usesLeftPanel) {
        // Honour text_zone's column. Left (and unknown) stays the historical
        // panel: text-left mr-auto items-start + sm:justify-start. Center/right
        // place the review on that axis; copy inside a right panel stays
        // left-aligned (mirror of today). flexJustifyClass is main-axis only —
        // never items-* (cross-axis), or it collides with hardcoded
        // items-center on eyebrow/trust/CTA rows.
        $horizontalClass = match ($textCol) {
            'center' => 'text-center mx-auto items-center',
            'right' => 'text-left ml-auto items-start',
            default => 'text-left mr-auto items-start',
        };
        $flexJustifyClass = match ($textCol) {
            'center' => 'sm:justify-center',
            'right' => 'sm:justify-end',
            default => 'sm:justify-start',
        };
        if (in_array($textCol, ['center', 'right'], true)) {
            $heroAlignAttr = ' data-hero-align="'.$textCol.'"';
        }
        // Tighter vertical padding: panel max-width 44rem (2-line H1) + py-12
        // keeps min-height growth near the 55vh floor (~543px @ 1440x900).
        $paddingClass = 'py-12';
    }
    $heroPanelOpacity = is_int($heroSceneResolved['panel_opacity'] ?? null) ? $heroSceneResolved['panel_opacity'] : 78;
    $heroCopyBoxClass = '';
    $heroCopySurfaceStyle = 'background-color: color-mix(in srgb, var(--brand-primary) '.$heroPanelOpacity.'%, transparent); border-radius: var(--radius-card); padding: 1.5rem 2rem; max-width: 44rem;';
    if ($usesLeftPanel && $heroCopyKnob === 'boxed') {
        $copyRadius = isset($site) && \App\Support\ChromeKnobs::heroCorners($site) === 'square'
            ? '0'
            : 'var(--radius-card)';
        $heroCopyBoxClass = ' hero-copy-box';
        $heroCopySurfaceStyle = 'background-color: color-mix(in srgb, var(--brand-primary) '.$heroPanelOpacity.'%, transparent); border-radius: '.$copyRadius.'; padding: 1.5rem 2rem; max-width: 36rem;';
    } elseif ($usesLeftPanel && $heroCopyKnob === 'panel') {
        $heroCopySurfaceStyle = 'background-color: color-mix(in srgb, var(--brand-primary) '.$heroPanelOpacity.'%, transparent); padding: 1.5rem 2rem; max-width: 44rem; min-height: 100%;';
    }
    $heroCompact = ! empty($heroSceneResolved['height'])
        && \App\Support\Site\HeroSizing::compactFor($heroSceneResolved['height']);
    if ($heroCompact && ! $usesLeftPanel) {
        $paddingClass = 'py-8 md:py-10';
    }
    if ($effectiveOverlay ?? false) {
        $heroCopyStyle = $isHomePage
            ? 'min-height: 100vh; min-height: 100dvh'
            : 'min-height: calc('.$heroH.' + var(--overlay-header-h, 0px))';
    } elseif ($usesLeftPanel || $heroCompact) {
        $heroCopyStyle = 'min-height: '.$heroH;
    } else {
        $heroCopyStyle = ' height: '.$heroH.'; min-height: '.$heroMinH;
    }
@endphp

@php
    // HeroResolution applies the explicit draft gate and verifies the
    // selected S3 object. Public renders therefore stay on live state,
    // while opted-in editor renders may use a drafted home video.
    $heroVideoUrl = $isHomePage ? ($heroState?->video_url ?? null) : null;

    // Per-site playback options. Both resolve to the pre-column defaults
    // when unset, so a site that has never been configured renders the
    // exact markup it did before these existed: `loop`, and no poster.
    $heroVideoLoop = \App\Enums\HeroVideoLoop::resolve(
        $site->home_hero_video_loop?->value ?? null
    );
    $heroVideoRepeats = $heroVideoLoop->repeats();

    // Optional poster. Only sites that set one pay for it — no poster
    // key means no poster attribute, exactly as before.
    $heroVideoPoster = null;
    if ($heroVideoUrl && ! empty($site->home_hero_video_poster_path)) {
        $posterDisk = \Illuminate\Support\Facades\Storage::disk('s3');
        if ($posterDisk->exists($site->home_hero_video_poster_path)) {
            $heroVideoPoster = $posterDisk->url($site->home_hero_video_poster_path);
        }
    }
@endphp
@php $heroFrame = isset($site) ? \App\Support\ChromeKnobs::heroFrame($site) : 'full'; @endphp
@if ($heroFrame === 'boxed')@php $heroBackdrop = \App\Support\ChromeKnobs::heroBackdropCss($site); @endphp<div data-hero-backdrop="{{ \App\Support\ChromeKnobs::heroBackdrop($site) }}"@if ($heroBackdrop) style="background-color: {{ $heroBackdrop }};"@endif><div data-hero-frame="boxed" data-hero-corners="{{ \App\Support\ChromeKnobs::heroCorners($site) }}" class="site-shell-container px-4 sm:px-6 lg:px-8"><div style="border-radius: {{ \App\Support\ChromeKnobs::heroCorners($site) === 'square' ? '0' : 'var(--radius-card)' }}; overflow: hidden;">@endif
<div class="relative overflow-hidden"
     @if ($heroVideoUrl)
         style="background-color: #000;"
     @elseif (!empty($bgImage))
         style="background-image: url('{{ $bgImage }}'); background-size: cover; background-position: {{ $bgPosition }};"
     @else
         style="background-color: var(--brand-primary);"
     @endif
     @if ($emitMarkers) data-editable-image-target="page.{{ $pageId }}.section.{{ $sectionIndex }}.background_image" @endif>
    @if ($heroVideoUrl)
        {{-- loop attribute only for the continuous mode; a finite repeat
             count is not expressible in HTML5, so Count1/2/3 count `ended`
             events and pause on the final frame once the budget is spent. --}}
        <video
            class="absolute inset-0 w-full h-full object-cover z-0 pointer-events-none"
            autoplay muted playsinline preload="auto"
            @if ($heroVideoLoop->isNative()) loop @endif
            @if ($heroVideoPoster) poster="{{ $heroVideoPoster }}" @endif
            @if ($heroVideoRepeats !== null && $heroVideoRepeats > 0)
                data-hero-video-repeats="{{ $heroVideoRepeats }}"
                onended="(function(v){v.dataset.heroVideoPlayed=(+(v.dataset.heroVideoPlayed||0))+1;if(+v.dataset.heroVideoPlayed <= +v.dataset.heroVideoRepeats){v.currentTime=0;v.play();}})(this)"
            @endif
        >
            <source src="{{ $heroVideoUrl }}" type="video/mp4">
        </video>
        <div class="absolute inset-0 z-[1] {{ $gradientClass }}"></div>
    @elseif (!empty($bgImage))
        <div class="absolute inset-0 {{ $gradientClass }}"></div>
    @else
        {!! \App\Support\Textures\TextureLayer::html($siteTexture ?? null, $section ?? null, true, $site ?? null) !!}
        <div class="absolute inset-0 bg-gradient-to-br from-black/40 via-black/20 to-black/50"></div>
    @endif

    <div class="relative z-[2] site-shell-container px-4 sm:px-6 lg:px-8 {{ $isHomePage ? $paddingClass : 'py-8 md:py-12' }} flex flex-col {{ $isHomePage ? $verticalClass : 'justify-center' }} overflow-hidden{{ ($effectiveOverlay ?? false) ? ($isHomePage ? ' overlay-hero-copy' : ' overlay-hero-copy-inner') : '' }}"
         style="{{ $heroCopyStyle }}">
        <div class="max-w-3xl {{ $horizontalClass }}{{ $heroCopyBoxClass }}"
             @if ($usesLeftPanel)
                 data-hero-variant="{{ $isPanelLeft ? 'panel-left' : 'boxed-left' }}"{!! $heroAlignAttr !!}
                 style="{{ $heroCopySurfaceStyle }}"
             @endif>
            @if ($isHomePage)
                @php
                    $heroArea = $profile['geo']['service_area'] ?? null;
                    if ($heroArea) {
                        $heroArea = trim(preg_replace('/\s*\(.*$/', '', $heroArea));
                        $heroArea = \Illuminate\Support\Str::limit($heroArea, 50, '…');
                    }
                @endphp
                @php
                    // Eyebrow precedence: explicit user-edited value > auto
                    // "Trusted in {area}" string > nothing. Stored on the
                    // section, so editing emits page.X.section.Y.eyebrow
                    // through the existing field-update path. Mirrors
                    // hero_compact's eyebrow semantics.
                    $eyebrow = $section['eyebrow'] ?? ($heroArea ? "Trusted in {$heroArea}" : null);

                    $heroReviewsCache = $site->reviews_cache ?? null;
                    $heroReviewsTotal = (int) ($heroReviewsCache['user_ratings_total'] ?? 0);
                    $heroBadgeMode = $site->reviews_hero_badge_mode ?? \App\Enums\ReviewsHeroBadgeMode::On;
                    $heroShowTrust = $heroBadgeMode->allowsPage('home') && ! empty($heroReviewsCache) && $heroReviewsTotal > 0;
                    // Per-site toggle — when off, the pill drops the "X Google
                    // Reviews" suffix and just shows rating + stars + Google G.
                    $heroShowCount = (bool) ($site->reviews_show_count_in_hero ?? true);
                @endphp
                @include('site.partials.hero-trust-pill')
                @if ($eyebrow)
                    <p class="hidden sm:flex text-sm font-semibold tracking-widest uppercase mb-4 items-center gap-2 {{ $textColorClass }} {{ $textShadow }} {{ $flexJustifyClass }}">
                        <span class="w-8 h-px inline-block bg-current"></span>
                        <span {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                    </p>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif
                {{-- Home-hero h1 scale: fluid clamp between 30px (mobile
                     floor) and 60px (1920+ cap), 3.5vw in between. Gives a
                     smooth transition through the 1024-1535 "mid-size
                     gap" that Tailwind's stepped breakpoints miss, and
                     scales the glued accent phrase down on narrow
                     viewports so a long phrase can't overflow the shell.
                     text-wrap:balance keeps line widths even across
                     every viewport. --}}
                @php
                    // Optional explicit line-break: when `title_lines` is a
                    // non-empty array of strings, render each on its own line.
                    // Falls back to the single-string `title` otherwise so
                    // every existing site is untouched.
                    $titleLines = (! empty($section['title_lines']) && is_array($section['title_lines']))
                        ? array_values(array_filter($section['title_lines'], fn ($s) => is_string($s) && $s !== ''))
                        : null;
                @endphp
                <h1 class="text-[clamp(1.875rem,3.5vw,{{ is_array($renderTokens ?? null) ? ($renderTokens['hero_home_clamp_cap'] ?? '3.75rem') : '3.75rem' }})] font-extrabold {{ $textColorClass }} {{ $textShadow }} mb-6 leading-tight [text-wrap:balance]"
                    {!! $editor('title', 'plain') !!}>@if ($titleLines)@foreach ($titleLines as $i => $line){!! app(App\Services\Site\AccentWordRenderer::class)->wrap($line, $section['accent_word'] ?? null, isset($site) && \App\Support\ChromeKnobs::accentStyle($site) === 'italic' ? 'italic' : null) !!}@if ($i < count($titleLines) - 1)<br>@endif @endforeach @else{!! app(App\Services\Site\AccentWordRenderer::class)->wrap($section['title'] ?? '', $section['accent_word'] ?? null, isset($site) && \App\Support\ChromeKnobs::accentStyle($site) === 'italic' ? 'italic' : null, $section['accent_ranges'] ?? null) !!}@endif</h1>
                @if (! empty($section['subtitle']))
                    {{-- $horizontalClass mirrors the wrapper so the narrower
                         max-w-2xl block follows the same centre/right/left
                         alignment as the title — without it, a centred
                         text_zone shows the title centred but the subtitle
                         box pinned to the left edge of the wider wrapper. --}}
                    {{-- Dual text-shadow so body copy stays self-legible on
                         any hero image (mirrors the scene partial). --}}
                    <p class="text-lg md:text-xl mb-10 max-w-2xl leading-relaxed {{ $horizontalClass }}"
                       style="color: rgba(255,255,255,0.95); text-shadow: 0 1px 3px rgba(0,0,0,0.65), 0 2px 12px rgba(0,0,0,0.5);"
                       {!! $editor('subtitle', 'plain') !!}>{{ $section['subtitle'] }}</p>
                @else
                    {{-- Emit subtitle marker even when empty so editor can add one --}}
                    @if ($emitMarkers)
                        <span class="hidden"{!! $editor('subtitle', 'plain') !!}></span>
                    @endif
                @endif
            @else
                @php
                    // Inner pages opt into the trust pill via an injected
                    // reviews_badge section (see ArchetypeComposer) — the
                    // badge strip is the mobile rendering, this pill is the
                    // desktop one. Same site-level cache feeds both.
                    $heroReviewsCache = $site->reviews_cache ?? null;
                    $heroReviewsTotal = (int) ($heroReviewsCache['user_ratings_total'] ?? 0);
                    $pageHasReviewsBadge = collect($sections ?? [])
                        ->contains(fn ($s) => ($s['type'] ?? null) === 'reviews_badge');
                    $heroBadgeMode = $site->reviews_hero_badge_mode ?? \App\Enums\ReviewsHeroBadgeMode::On;
                    $heroShowTrust = $heroBadgeMode->allowsPage($pageType ?? 'home') && $pageHasReviewsBadge && ! empty($heroReviewsCache) && $heroReviewsTotal > 0;
                    $heroShowCount = (bool) ($site->reviews_show_count_in_hero ?? true);
                @endphp
                @include('site.partials.hero-trust-pill')
                {{-- Inner-page h1 mirrors the home pattern but with a
                     smaller 48px cap (inner hero is 35vh vs home's 55vh,
                     so the cap sits at text-5xl not text-6xl). Fluid
                     clamp + text-balance + nbsp-glued accent phrases
                     via AccentWordRenderer share the same scaling logic
                     as home so behaviour stays consistent across pages. --}}
                <h1 class="text-[clamp(1.875rem,3vw,{{ is_array($renderTokens ?? null) ? ($renderTokens['hero_inner_clamp_cap'] ?? '3rem') : '3rem' }})] font-extrabold {{ $textColorClass }} {{ $textShadow }} mb-3 leading-tight [text-wrap:balance]"
                    {!! $editor('title', 'plain') !!}>{!! app(App\Services\Site\AccentWordRenderer::class)->wrap($section['title'] ?? '', $section['accent_word'] ?? null, isset($site) && \App\Support\ChromeKnobs::accentStyle($site) === 'italic' ? 'italic' : null, $section['accent_ranges'] ?? null) !!}</h1>
                @if (! empty($section['subtitle']))
                    {{-- See note on home-hero subtitle: $horizontalClass keeps
                         the max-w-xl block aligned with the title's
                         centre/right/left zone. --}}
                    <p class="text-base md:text-lg {{ $subTextClass }} {{ $textShadow }} max-w-xl leading-relaxed {{ $horizontalClass }}"
                       {!! $editor('subtitle', 'plain') !!}>{{ $section['subtitle'] }}</p>
                @else
                    @if ($emitMarkers)
                        <span class="hidden"{!! $editor('subtitle', 'plain') !!}></span>
                    @endif
                @endif
            @endif

            @if ($isHomePage && !empty($section['trust_signals']) && is_array($section['trust_signals']))
                {{-- Trust-signal pill row: short inline social-proof items
                     (e.g. "No deposit · Go live same day · No agency price").
                     Renders only when the array is non-empty so existing sites
                     are completely unaffected. Each item is a pill with a
                     subtle separator dot between adjacent pills. --}}
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mb-6 {{ $flexJustifyClass }}">
                    @foreach ($section['trust_signals'] as $trustSignal)
                        @if (is_string($trustSignal) && $trustSignal !== '')
                            <span class="inline-flex items-center gap-1.5 text-sm font-medium {{ $textColorClass }} {{ $textShadow }} opacity-90">
                                <svg class="w-3.5 h-3.5 flex-shrink-0 opacity-70" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/>
                                </svg>
                                {{ $trustSignal }}
                            </span>
                        @endif
                    @endforeach
                </div>
            @endif
            @if ($isHomePage)
                <div class="flex flex-col sm:flex-row gap-4 {{ $flexJustifyClass }} mt-2">
                    @if (! empty($section['cta_label']))
                        @php
                            $rawHeroCta = $section['cta_url'] ?? null;
                            $resolvedHeroCta = ($rawHeroCta === null || $rawHeroCta === '#contact')
                                ? (($heroFormTarget ?? false) ? '#enquire' : ($pagesBySlug['contact'] ?? '#contact'))
                                : $rawHeroCta;
                            $heroEnquireClick = $resolvedHeroCta === '#enquire'
                                ? ' x-data @click.prevent="document.getElementById(\'enquire\')?.scrollIntoView({ behavior: window.matchMedia(\'(prefers-reduced-motion: reduce)\').matches ? \'auto\' : \'smooth\' })"'
                                : '';
                        @endphp
                        <a href="{{ $resolvedHeroCta }}"{!! $heroEnquireClick !!}
                           class="inline-flex items-center justify-center gap-2 font-bold px-8 py-4 rounded-md shadow-lg transition-all hover:shadow-xl hover:brightness-110 text-lg"
                           style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff); border-radius: var(--radius-button);">
                            <span{!! $editor('cta_label', 'plain') !!}>{{ $section['cta_label'] }}</span>
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                        </a>
                        @if ($emitMarkers)
                            <button type="button" class="hidden"{!! $editor('cta_url', 'url') !!}></button>
                        @endif
                    @else
                        @if ($emitMarkers)
                            <span class="hidden"{!! $editor('cta_label', 'plain') !!}></span>
                            <span class="hidden"{!! $editor('cta_url', 'url') !!}></span>
                        @endif
                    @endif
                    {{-- Hero inline phone CTA — mobile-only. On md+ the
                         topbar + nav pill already expose the phone in two
                         places, so a third inline CTA here just adds noise.
                         Keep for mobile where the topbar hides anyway. --}}
                    @if ($phone = ($profile['contact']['phones'][0] ?? null))
                        <a href="tel:{{ preg_replace('/\s+/', '', $phone) }}"
                           aria-label="Call {{ $phone }}"
                           class="md:hidden inline-flex items-center justify-center gap-2 font-medium px-6 py-3.5 {{ $textColorClass }} text-base opacity-90 hover:opacity-100 transition-opacity"
                           style="border-radius: var(--radius-button);">
                            <svg class="w-4 h-4 opacity-75" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            <span>or call <span class="font-bold underline underline-offset-4 decoration-white/40 hover:decoration-white/80">{{ $phone }}</span></span>
                        </a>
                    @endif
                </div>
            @else
                {{-- Inner page: emit markers only --}}
                @if ($emitMarkers)
                    <span class="hidden"{!! $editor('cta_label', 'plain') !!}></span>
                    <span class="hidden"{!! $editor('cta_url', 'url') !!}></span>
                @endif
            @endif
        </div>
    </div>
    {{-- Scroll-down indicator: only on home + when the hero is tall enough that
         visitors might not realise content sits below. Threshold is 70vh —
         under that the next section is already in the visual frame on most
         laptops, so the chevron is just decoration. Targets the hero-owned
         #after-hero sentinel below so it works on any page composition (the
         old #home-content target only existed on pages with a services
         section, which left SaaS / non-trades layouts with a dead arrow). --}}
    @php
        $heroVhValue = is_string($heroH) && preg_match('/^(\d+)vh$/', $heroH, $m) ? (int) $m[1] : null;
        $showScrollHint = $isHomePage && (($heroVhValue !== null && $heroVhValue >= 70) || ($effectiveOverlay ?? false));
    @endphp
    @if ($showScrollHint)
        <a href="#after-hero" aria-label="Scroll to content"
           x-data="{ hidden: window.scrollY > 40 }"
           @scroll.window.passive="hidden = hidden || window.scrollY > 40"
           :style="hidden ? 'opacity: 0; pointer-events: none;' : ''"
           @click.prevent="document.getElementById('after-hero')?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })"
           class="absolute bottom-6 left-1/2 -translate-x-1/2 z-[3] {{ $textColorClass }} opacity-70 hover:opacity-100 transition-opacity duration-300">
            <svg class="w-8 h-8 animate-bounce motion-reduce:animate-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
            </svg>
        </a>
    @endif
    {{-- Bottom accent bar — needs relative+z-index above the absolute
         <video> element on video heroes (which spans inset-0 of the parent
         and would otherwise cover the bar). On image heroes the bar already
         flows naturally below the inner div. --}}
    <div class="relative z-[3] h-1.5" style="background-color: var(--brand-accent);"></div>
</div>
@if ($heroFrame === 'boxed')</div></div></div>@endif
{{-- Scroll-down chevron target. Sits immediately below the hero so the
     arrow lands at the boundary regardless of which section follows.
     scroll-mt gives a little breathing room under the sticky nav. --}}
<div id="after-hero" class="scroll-mt-24 md:scroll-mt-28" aria-hidden="true"></div>

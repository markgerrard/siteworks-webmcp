@php
    /**
     * Multi-slide hero scene partial — receives a resolved scene from
     * App\Services\Site\HeroSceneService and renders an image cycler
     * (kind='image') with per-slide overlay copy, or plays the
     * pre-rendered composite mp4 (kind='video') with the first slide's
     * overlay sitting constant across the whole timeline (per-slide
     * timed overlays are a v1.1 enhancement).
     *
     * Required vars in scope:
     *   $scene         — array from HeroSceneService::resolve()
     *   $site          — App\Models\Site
     *   $profile       — array (business profile data)
     *   $pagesBySlug   — array<string,string>
     *   $heroH         — string (e.g. '70vh')
     *   $heroMinH      — string
     *
     * The eyebrow / trust pill / scroll hint stay in the parent template
     * so the slider sits inside the same chrome as the single-asset
     * hero. The accent bar is rendered here so its z-index sits above
     * the absolute asset stack.
     */
    $slides = $scene['slides'] ?? [];
    $kind = $scene['kind'] ?? 'image';
    $transitions = $scene['transitions'] ?? [];
    $compositeUrl = $scene['composite_video_url'] ?? null;
    $slideCount = count($slides);
    $isVideo = $kind === 'video';

    // Layout-preset / explicit content_data variant. $section is in scope via
    // Blade include inheritance from site/sections/hero.blade.php (preview
    // surface may omit it — treat as no variant). When Showcase stamps
    // boxed-left, the overlay copy block gets the same left-panel treatment
    // as the static single-asset hero path.
    $sceneHeroVariant = is_array($section ?? null) ? ($section['variant'] ?? null) : null;
    if (isset($site)) {
        $sceneHeroVariant = \App\Support\ChromeKnobs::heroCopyVariant($site, $sceneHeroVariant);
    }
    $scenePanelLeft = $sceneHeroVariant === 'panel-left';
    $sceneBoxedLeft = $sceneHeroVariant === 'boxed-left' || $scenePanelLeft;
    $sceneHeroCompact = \App\Support\Site\HeroSizing::compactFor($scene['height'] ?? null);
    if ($effectiveOverlay ?? false) {
        $sceneCopyStyle = 'min-height: 100vh; min-height: 100dvh';
    } elseif ($sceneBoxedLeft || $sceneHeroCompact) {
        $sceneCopyStyle = 'min-height: '.$heroH;
    } else {
        $sceneCopyStyle = ' height: '.$heroH.'; min-height: '.$heroMinH.';';
    }
    $scenePaddingClass = $sceneBoxedLeft
        ? 'py-12'
        : ($sceneHeroCompact ? 'py-8 md:py-10' : 'py-28 md:py-36 lg:py-40');

    // Optional per-site panel max-width from resolved scene (HeroSceneService
    // already validated CSS size). Default 44rem when absent/invalid.
    $scenePanelMaxWidth = ! empty($scene['panel_width']) ? $scene['panel_width'] : '44rem';

    // Boxed-panel background opacity (validated int 0-100 in the service).
    $scenePanelOpacity = is_int($scene['panel_opacity'] ?? null) ? $scene['panel_opacity'] : 78;

    // Boxed copy treatment (validated in the service): 'panel' paints the
    // color-mix box behind the copy; 'gradient' drops the box and paints a
    // brand-primary scrim across the left of the image instead (panel_opacity
    // doubles as the scrim's start intensity); 'none' is copy straight on
    // the image. Applies to the boxed-left variant only — non-boxed slides
    // keep their per-slide legibility gradient. Explicit boxed/panel knobs
    // win over the scene overlay; preset keeps the scene's choice.
    $sceneOverlayStyle = isset($site)
        ? \App\Support\ChromeKnobs::heroSceneOverlayStyle($site, $scene['overlay_style'] ?? null)
        : (in_array($scene['overlay_style'] ?? null, ['gradient', 'none'], true) ? $scene['overlay_style'] : 'panel');
    if ($scenePanelLeft) {
        $sceneOverlayStyle = 'panel';
    }
    $sceneCopyBoxClass = $sceneOverlayStyle === 'boxed' ? ' hero-copy-box' : '';
    $sceneCopyBoxRadius = isset($site) && \App\Support\ChromeKnobs::heroCorners($site) === 'square'
        ? '0'
        : 'var(--radius-card)';
    $sceneScrimMid = (int) round($scenePanelOpacity * 0.45);

    // Default transition durations (ms) for x-data init. Per-segment
    // overrides can come from $transitions[i].duration_secs.
    $dwellMs = max(3000, (int) (($slides[0]['dwell_secs'] ?? 6) * 1000));
    $fadeMs = (int) (($transitions[0]['duration_secs'] ?? 1.0) * 1000);

    // Opt-in Ken Burns motion (HeroSceneService validated the value; image
    // kind only). Animation must outlive dwell + both cross-fades so the
    // drift never stalls while a slide is still visible.
    // Single-slide scenes are allowed to move too. Multi-slide keyframes
    // run once per activation (fill-mode forwards) because the next slide
    // re-triggers them; with one slide there is no re-trigger, so it would
    // drift once and freeze. Single-slide uses a slow infinite alternate
    // instead. Opt-in either way — nothing moves unless motion is set.
    $sceneKenBurns = ($scene['motion'] ?? null) === 'ken_burns' && ! $isVideo && $slideCount >= 1;
    $sceneKbSingle = $sceneKenBurns && $slideCount === 1;
    $sceneKbMs = $dwellMs + ($fadeMs * 2);

    // overlay_mode=constant: the hero is NOT a slider — slide 0's copy sits
    // fixed over the whole image cycle (same treatment the video composite
    // gets) and the pager dots disappear.
    $sceneConstantOverlay = ($scene['overlay_mode'] ?? null) === 'constant' && ! $isVideo && $slideCount > 1;

    // Eyebrow (user-edited override → auto "Trusted in {area}" fallback) +
    // Google reviews trust pill. Same data sources the legacy single-asset
    // hero uses, surfaced here so scenes don't lose this trust chrome.
    // Rendered once per slide overlay below so alignment follows each
    // slide's text_zone.
    //
    // $sceneEyebrowOverride is passed in from the parent template (the
    // legacy section's `eyebrow` field if the user has edited it). Prefer
    // it over the auto fallback so an explicit override is never silently
    // dropped once scene mode is active.
    $sceneArea = ($profile ?? [])['geo']['service_area'] ?? null;
    if ($sceneArea) {
        $sceneArea = trim(preg_replace('/\s*\(.*$/', '', $sceneArea));
        $sceneArea = \Illuminate\Support\Str::limit($sceneArea, 50, '…');
    }
    $sceneEyebrowAuto = $sceneArea ? "Trusted in {$sceneArea}" : null;
    $sceneEyebrowResolved = isset($sceneEyebrowOverride) && trim((string) $sceneEyebrowOverride) !== ''
        ? trim((string) $sceneEyebrowOverride)
        : $sceneEyebrowAuto;
    $sceneEyebrow = $sceneEyebrowResolved;

    $sceneReviewsCache = $site->reviews_cache ?? null;
    $sceneReviewsTotal = (int) ($sceneReviewsCache['user_ratings_total'] ?? 0);
    $sceneShowTrust = ! empty($sceneReviewsCache) && $sceneReviewsTotal > 0;
    $sceneShowCount = (bool) ($site->reviews_show_count_in_hero ?? true);
    $scenePillProvider = ($sceneReviewsCache ?? [])['provider'] ?? 'google';
    $scenePillScale = (int) (($sceneReviewsCache ?? [])['rating_scale'] ?? 5);
    if ($scenePillScale < 1) {
        $scenePillScale = 5;
    }
    $sceneIsCheckatrade = $scenePillProvider === 'checkatrade';
    $sceneRatingRaw = $sceneShowTrust ? number_format((float) $sceneReviewsCache['rating'], 1) : null;
    // Native cache scale: "9.9/10" when scale is 10; bare rating for /5.
    $sceneRating = $sceneRatingRaw === null
        ? null
        : ($scenePillScale === 10 ? $sceneRatingRaw.'/10' : $sceneRatingRaw);
    $scenePillStars = $sceneShowTrust
        ? (int) round(((float) $sceneReviewsCache['rating'] / $scenePillScale) * 5)
        : 0;
    $sceneCountLabel = $sceneShowTrust
        ? ($sceneReviewsTotal >= 90 ? floor($sceneReviewsTotal / 10) * 10 . '+' : $sceneReviewsTotal)
        : null;
    $sceneCountText = $sceneIsCheckatrade
        ? $sceneCountLabel.' Checkatrade reviews'
        : $sceneCountLabel.' Google Reviews';
    $sceneOnText = $sceneIsCheckatrade ? 'on Checkatrade' : 'on Google';
@endphp

<div class="relative overflow-hidden"
     style="background-color: #000;"
     x-data="{
         active: 0,
         count: {{ $slideCount }},
         isVideo: {{ $isVideo ? 'true' : 'false' }},
         start() {
             if (this.isVideo || this.count <= 1) return;
             setInterval(() => { this.active = (this.active + 1) % this.count; }, {{ $dwellMs }});
         }
     }"
     x-init="start()">

    @if ($isVideo && $compositeUrl)
        {{-- Composite mp4: a single video tag plays the pre-rendered xfade
             chain. No JS swap needed — ffmpeg already baked the timeline. --}}
        <video class="absolute inset-0 w-full h-full object-cover z-0 pointer-events-none"
               autoplay muted loop playsinline preload="auto">
            <source src="{{ $compositeUrl }}" type="video/mp4">
        </video>
        @unless ($sceneBoxedLeft)
            <div class="absolute inset-0 z-[1] bg-gradient-to-r from-black/70 via-black/40 to-transparent"></div>
        @endunless
    @else
        {{-- Image cycler: every slide is absolutely positioned inset-0;
             only the active one has opacity-100. Cross-fade is a CSS
             opacity transition. --}}
        @if ($sceneKenBurns)
            {{-- Keyframes restart when Alpine re-adds .scene-kb-run on
                 activation. Four directions cycle by slide index; the drift
                 pre-scales so translate never exposes the frame edge. --}}
            <style>
                .scene-kb { will-change: transform; }
                .scene-kb-run { animation-timing-function: ease-out; animation-fill-mode: forwards; }
                .scene-kb-0.scene-kb-run { animation-name: scene-kb-push-right; }
                .scene-kb-1.scene-kb-run { animation-name: scene-kb-drift; }
                .scene-kb-2.scene-kb-run { animation-name: scene-kb-push-centre; }
                .scene-kb-3.scene-kb-run { animation-name: scene-kb-push-low; }
                @keyframes scene-kb-push-right  { from { transform: scale(1); transform-origin: 70% 40%; } to { transform: scale(1.08); transform-origin: 70% 40%; } }
                @keyframes scene-kb-drift       { from { transform: scale(1.08) translateX(1.25%); } to { transform: scale(1.08) translateX(-1.25%); } }
                @keyframes scene-kb-push-centre { from { transform: scale(1); transform-origin: 45% 55%; } to { transform: scale(1.08); transform-origin: 45% 55%; } }
                @keyframes scene-kb-push-low    { from { transform: scale(1); transform-origin: 60% 60%; } to { transform: scale(1.09); transform-origin: 60% 60%; } }
                @if ($sceneKbSingle)
                    /* One slide: slow, continuous, reversing drift so it never
                       stalls on a held end-state. Deliberately gentle. */
                    .scene-kb-0.scene-kb-run {
                        animation-name: scene-kb-slow-drift;
                        animation-iteration-count: infinite;
                        animation-direction: alternate;
                        animation-timing-function: ease-in-out;
                        animation-fill-mode: none;
                    }
                    @keyframes scene-kb-slow-drift {
                        from { transform: scale(1.045) translate(0.6%, -0.4%); }
                        to   { transform: scale(1.085) translate(-0.6%, 0.4%); }
                    }
                @endif
                @media (prefers-reduced-motion: reduce) {
                    .scene-kb-run { animation-name: none !important; }
                }
            </style>
        @endif
        @foreach ($slides as $i => $slide)
            <div class="absolute inset-0 transition-opacity"
                 style="transition-duration: {{ $fadeMs }}ms;"
                 :class="active === {{ $i }} ? 'opacity-100 z-[1]' : 'opacity-0 z-0'">
                <div class="absolute inset-0 w-full h-full bg-cover bg-center{{ $sceneKenBurns ? ' scene-kb scene-kb-'.($i % 4) : '' }}"
                     style="background-image: url('{{ $slide['asset_url'] }}');{{ $sceneKenBurns ? ' animation-duration: '.($sceneKbSingle ? 22000 : $sceneKbMs).'ms;' : '' }}"
                     @if ($sceneKenBurns) :class="active === {{ $i }} ? 'scene-kb-run' : ''" @endif></div>
                @unless ($sceneBoxedLeft)
                    {{-- Legibility gradient for text sitting directly on the
                         image. boxed-left copy has its own panel — the
                         gradient would just mute the photography. --}}
                    <div class="absolute inset-0 bg-gradient-to-r from-black/70 via-black/40 to-transparent"></div>
                @endunless
            </div>
        @endforeach
    @endif

    @if ($sceneBoxedLeft && $sceneOverlayStyle === 'gradient')
        {{-- Gradient scrim: dark brand-primary on the copy side fading out
             by mid-frame, so the photography carries the right of the hero
             untouched. Sits above every slide (later sibling at z-[1]),
             below the copy layer at z-[2]. --}}
        <div class="absolute inset-0 z-[1] pointer-events-none" data-hero-overlay-style="gradient"
             style="background: linear-gradient(to right, color-mix(in srgb, var(--brand-primary) {{ $scenePanelOpacity }}%, transparent) 0%, color-mix(in srgb, var(--brand-primary) {{ $sceneScrimMid }}%, transparent) 32%, transparent 58%);"></div>
    @endif

    {{-- Overlay layer — per-slide heading/sub/cta cycle in lockstep with
         the asset stack via the shared `active` index. For video kind we
         lock to slide 0 (constant overlay). boxed-left: min-height floor only
         (no fixed height) + tighter py-12 so the color-mix panel is not clipped
         — mirrors site/sections/hero.blade.php static path. --}}
    <div class="relative z-[2] site-shell-container px-4 sm:px-6 lg:px-8 {{ $scenePaddingClass }} flex flex-col justify-center{{ ($effectiveOverlay ?? false) ? ' overlay-hero-copy' : '' }}"
         style="{{ $sceneCopyStyle }}">
        @foreach ($slides as $i => $slide)
            {{-- Constant overlay: slide 0's copy is the only overlay in the
                 DOM — later slides' copy must not fade in as `active` cycles
                 the background images. --}}
            @continue($sceneConstantOverlay && $i > 0)
            @php
                [$slideRow, $slideCol] = array_pad(explode('-', $slide['text_zone'] ?? 'middle-left'), 2, 'left');
                $slideHorizontalClass = match ($slideCol) {
                    'right' => 'text-right ml-auto',
                    'center' => 'text-center mx-auto',
                    default => 'text-left',
                };
                $slideFlexJustify = match ($slideCol) {
                    'right' => 'sm:justify-end',
                    'center' => 'sm:justify-center',
                    default => '',
                };
                // Showcase boxed/panel: honour text_zone's column. Left (and
                // unknown) stays the historical panel. Center/right place the
                // panel on that axis; copy inside a right panel stays
                // left-aligned. flexJustify is main-axis only — never items-*
                // (collides with hardcoded items-center on eyebrow/trust/CTA).
                $slideHeroAlignAttr = '';
                if ($sceneBoxedLeft) {
                    $slideHorizontalClass = match ($slideCol) {
                        'center' => 'text-center mx-auto items-center',
                        'right' => 'text-left ml-auto items-start',
                        default => 'text-left mr-auto items-start',
                    };
                    $slideFlexJustify = match ($slideCol) {
                        'center' => 'sm:justify-center',
                        'right' => 'sm:justify-end',
                        default => 'sm:justify-start',
                    };
                    if (in_array($slideCol, ['center', 'right'], true)) {
                        $slideHeroAlignAttr = ' data-hero-align="'.$slideCol.'"';
                    }
                }
                // Apply the site's brand accent-word wrapping to slide
                // headings — without this, enabling scene mode strips
                // the accent underline that the legacy hero applies via
                // AccentWordRenderer and looks like a brand regression.
                // The accent word is global per-section in legacy land;
                // we pass the legacy section's value through from the
                // parent template so every slide gets the same accent.
                $rawHeadingHtml = ! empty($slide['heading'])
                    ? app(\App\Services\Site\AccentWordRenderer::class)->wrap(
                        $slide['heading'],
                        $sceneAccentWord ?? null,
                        isset($site) && \App\Support\ChromeKnobs::accentStyle($site) === 'italic' ? 'italic' : null,
                    )
                    : '';
                // Per-slide CTA target. cta_action is a page_type slug
                // ('contact', 'about', service slugs, etc); null falls back
                // to the site's contact page so legacy scenes keep working.
                // Smart-target (home form → #enquire) fires only on null.
                // An authored 'contact' slug resolves via $pagesBySlug like
                // any other page_type — never rewritten to #enquire.
                $slideCtaAction = $slide['cta_action'] ?? null;
                $resolvedSlideCtaHref = ($slideCtaAction === null)
                    ? (($heroFormTarget ?? false) ? '#enquire' : ($pagesBySlug['contact'] ?? '#contact'))
                    : ($pagesBySlug[$slideCtaAction] ?? $pagesBySlug['contact'] ?? '#contact');
                $heroEnquireClick = $resolvedSlideCtaHref === '#enquire'
                    ? ' x-data @click.prevent="document.getElementById(\'enquire\')?.scrollIntoView({ behavior: window.matchMedia(\'(prefers-reduced-motion: reduce)\').matches ? \'auto\' : \'smooth\' })"'
                    : '';
                // For video kind we keep slide 0 visible throughout — the
                // composite mp4 isn't synced to per-slide copy yet. Constant
                // overlay mode gets the same lock for image cyclers.
                $alwaysVisibleClass = ($isVideo || $sceneConstantOverlay) && $i === 0 ? 'opacity-100' : null;
                // boxed-left: in-flow overlay so panel height grows the
                // min-height shell (absolute top/bottom contributes 0 height
                // and clips under root overflow-hidden). Multi-slide inactive
                // still absolute so slides don't stack document height.
                $boxedAlwaysInFlow = $sceneBoxedLeft && ($alwaysVisibleClass || $slideCount <= 1);
            @endphp
            {{-- boxed-left: relative in-flow (data-hero-overlay-flow) so the
                 panel participates in shell height; non-boxed keeps absolute
                 top/bottom stretch for vertical centering over the asset. --}}
            <div @if ($sceneBoxedLeft)
                     data-hero-overlay-flow
                     class="w-full transition-opacity{{ $boxedAlwaysInFlow ? ' relative' : '' }}{{ $alwaysVisibleClass ? ' '.$alwaysVisibleClass : '' }}"
                     style="transition-duration: {{ $fadeMs }}ms;"
                     @if (! $boxedAlwaysInFlow)
                         :class="active === {{ $i }} ? 'relative opacity-100 z-[1]' : 'absolute inset-0 opacity-0 z-0 pointer-events-none'"
                     @endif
                 @else
                     class="absolute inset-x-4 sm:inset-x-6 lg:inset-x-8 transition-opacity"
                     style="top: 0; bottom: 0; transition-duration: {{ $fadeMs }}ms;"
                     @if ($alwaysVisibleClass)
                         class="{{ $alwaysVisibleClass }}"
                     @else
                         :class="active === {{ $i }} ? 'opacity-100 z-[1]' : 'opacity-0 z-0 pointer-events-none'"
                     @endif
                 @endif>
                @if (! $sceneBoxedLeft)
                    <div class="site-shell-container h-full flex flex-col justify-center">
                @endif
                    <div class="max-w-3xl {{ $slideHorizontalClass }}{{ $sceneCopyBoxClass }}"
                         @if ($sceneBoxedLeft)
                             data-hero-variant="{{ $scenePanelLeft ? 'panel-left' : 'boxed-left' }}"{!! $slideHeroAlignAttr !!}
                             @if ($sceneOverlayStyle === 'panel')
                                 style="background-color: color-mix(in srgb, var(--brand-primary) {{ $scenePanelOpacity }}%, transparent); border-radius: var(--radius-card); padding: 1.5rem 2rem; max-width: {{ $scenePanelMaxWidth }};"
                             @elseif ($sceneOverlayStyle === 'boxed')
                                 {{-- Compact painted box (non-scene treatment): ~36rem, recipe radius, no full-height band. --}}
                                 style="background-color: color-mix(in srgb, var(--brand-primary) {{ $scenePanelOpacity }}%, transparent); border-radius: {{ $sceneCopyBoxRadius }}; padding: 1.5rem 2rem; max-width: 36rem;"
                             @else
                                 {{-- gradient/none: no box — scrim (or nothing) carries legibility; keep the copy column's footprint. --}}
                                 style="padding: 1.5rem 0; max-width: {{ $scenePanelMaxWidth }};"
                             @endif
                         @endif>
                        @if ($sceneShowTrust)
                            {{-- Desktop-only reviews trust pill above the
                                 eyebrow. Provider-aware from reviews_cache
                                 (Google G or Checkatrade tick-in-shield).
                                 Mobile falls back to the reviews_badge strip
                                 below the hero so we don't double-stack. --}}
                            {{-- bg-white/15 + drop-shadow keeps the pill legible
                                 even on bright/busy slides where /10 was getting
                                 swallowed. --}}
                            <div class="hidden md:inline-flex items-center gap-2 mb-3 px-3 py-1.5 rounded-full text-xs font-medium bg-white/15 border border-white/25 backdrop-blur-sm text-white shadow-[0_2px_8px_rgba(0,0,0,0.35)]">
                                @include('site.partials._provider-mark', [
                                    'provider' => $scenePillProvider,
                                    'markClass' => 'w-3.5 h-3.5 flex-shrink-0',
                                ])
                                <span class="inline-flex gap-px" aria-hidden="true">
                                    @for ($s = 1; $s <= 5; $s++)
                                        <svg class="w-2.5 h-2.5" viewBox="0 0 20 20" fill="{{ $s <= $scenePillStars ? '#fbbf24' : '#d1d5db' }}">
                                            <path d="M10 1l3 6 6 1-4.5 4 1 6-5.5-3-5.5 3 1-6L1 8l6-1z"/>
                                        </svg>
                                    @endfor
                                </span>
                                <span class="font-bold">{{ $sceneRating }}</span>
                                @if ($sceneIsCheckatrade)
                                    {{-- Checkatrade: the wordmark IS the message — no cached
                                         review count (it undersells the live profile) and no
                                         "reviews" filler. Sized up so the mark reads. --}}
                                    <span aria-hidden="true" class="opacity-50">·</span>
                                    @include('site.partials._checkatrade-logo', ['reversed' => true, 'height' => '1.5em'])
                                @elseif ($sceneShowCount)
                                    <span aria-hidden="true" class="opacity-50">·</span>
                                    <span class="opacity-90">{{ $sceneCountText }}</span>
                                @else
                                    <span class="opacity-90">{{ $sceneOnText }}</span>
                                @endif
                            </div>
                        @endif
                        @if ($sceneEyebrow)
                            <p class="hidden sm:flex text-sm font-semibold tracking-widest uppercase mb-4 items-center gap-2 text-white drop-shadow-[0_2px_8px_rgba(0,0,0,0.7)] {{ $slideFlexJustify }}">
                                <span class="w-8 h-px inline-block bg-current"></span>
                                <span>{{ $sceneEyebrow }}</span>
                            </p>
                        @endif
                        @if (! empty($slide['heading']))
                            <h1 class="text-[clamp(1.875rem,3.5vw,{{ is_array($renderTokens ?? null) ? ($renderTokens['hero_home_clamp_cap'] ?? '3.75rem') : '3.75rem' }})] font-extrabold text-white drop-shadow-[0_2px_8px_rgba(0,0,0,0.7)] mb-6 leading-tight [text-wrap:balance]">
                                {!! $rawHeadingHtml !!}
                            </h1>
                        @endif
                        @if (! empty($slide['subheading']))
                            {{-- Near-solid white + dual text-shadow (tight edge
                                 + soft halo): body copy must stay self-legible
                                 on ANY slide as photos rotate — tuned against
                                 the worst case (pale render/cream frames), and
                                 invisible where contrast is already good. --}}
                            {{-- Colour inline, not a Tailwind class: new utility
                                 classes silently no-op until the next asset
                                 build, and this copy must never inherit a dark
                                 theme colour. --}}
                            <p class="text-lg md:text-xl mb-10 max-w-2xl leading-relaxed {{ $slideHorizontalClass }}"
                               style="color: rgba(255,255,255,0.95); text-shadow: 0 1px 3px rgba(0,0,0,0.65), 0 2px 12px rgba(0,0,0,0.5);">
                                {{ $slide['subheading'] }}
                            </p>
                        @endif
                        @if (! empty($slide['cta_label']))
                            <div class="flex flex-col sm:flex-row gap-4 {{ $slideFlexJustify }} mt-2">
                                <a href="{{ $resolvedSlideCtaHref }}"{!! $heroEnquireClick !!}
                                   class="inline-flex items-center justify-center gap-2 font-bold px-8 py-4 rounded-md shadow-lg transition-all hover:shadow-xl hover:brightness-110 text-lg"
                                   style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff); border-radius: var(--radius-button);">
                                    <span>{{ $slide['cta_label'] }}</span>
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                                </a>
                            </div>
                        @endif
                    </div>
                @if (! $sceneBoxedLeft)
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Slide pager dots (image kind only, 2+ slides). Tap to jump.
         Constant overlay mode is not a slider — no pager chrome. --}}
    @if (! $isVideo && $slideCount > 1 && ! $sceneConstantOverlay)
        <div class="absolute bottom-8 left-1/2 -translate-x-1/2 z-[3] flex gap-2">
            @foreach ($slides as $i => $slide)
                <button type="button"
                        x-on:click="active = {{ $i }}"
                        :class="active === {{ $i }} ? 'bg-white' : 'bg-white/40 hover:bg-white/70'"
                        class="w-2.5 h-2.5 rounded-full transition-colors"
                        aria-label="Go to slide {{ $i + 1 }}"></button>
            @endforeach
        </div>
    @endif

    {{-- Scroll-down indicator. The docblock above says the scroll hint lives
         in the parent template, but hero.blade.php returns immediately after
         including this partial, so the parent's copy was never reached in
         scene mode and tall showcase heroes lost the cue the standard hero
         has. Same 70vh threshold, same #after-hero sentinel, so the two hero
         modes behave identically. Must live INSIDE this container: it is
         absolutely positioned and the parent has no positioned ancestor at
         that point. --}}
    @php
        $sceneVh = is_string($heroH) && preg_match('/^(\d+)vh$/', $heroH, $mVh) ? (int) $mVh[1] : null;
        // 68vh, not the single-asset hero's 70vh: the showcase boxed-left
        // preset IS 68vh (set in hero.blade.php), so a scene hero at the
        // standard preset height could never qualify under a 70 gate. The
        // 70 threshold's rationale — "below this the next section is already
        // in frame" — does not meaningfully separate 68 from 70.
        $sceneShowScrollHint = ($pageType ?? 'home') === 'home' && (($sceneVh !== null && $sceneVh >= 68) || ($effectiveOverlay ?? false));
    @endphp
    @if ($sceneShowScrollHint)
        <a href="#after-hero" aria-label="Scroll to content"
           x-data="{ hidden: window.scrollY > 40 }"
           @scroll.window.passive="hidden = hidden || window.scrollY > 40"
           :style="hidden ? 'opacity: 0; pointer-events: none;' : ''"
           @click.prevent="document.getElementById('after-hero')?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })"
           class="absolute bottom-6 left-1/2 -translate-x-1/2 z-[3] text-white opacity-70 hover:opacity-100 transition-opacity duration-300"
           {{-- Inline filter, not a drop-shadow-[...] utility: arbitrary
                Tailwind values only exist if they were present at build time,
                and this one is not in the site-public bundle. Same reason
                z-[3] is used here rather than z-[4]. --}}
           style="filter: drop-shadow(0 2px 6px rgba(0,0,0,0.75));">
            <svg class="w-8 h-8 animate-bounce motion-reduce:animate-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
            </svg>
        </a>
    @endif

    {{-- Bottom accent bar — z-[3] so it sits above the absolute asset stack. --}}
    <div class="relative z-[3] h-1.5" style="background-color: var(--brand-accent);"></div>
</div>
{{-- Scroll target, mirroring the single-asset hero's sentinel. --}}
<div id="after-hero" class="scroll-mt-24 md:scroll-mt-28" aria-hidden="true"></div>

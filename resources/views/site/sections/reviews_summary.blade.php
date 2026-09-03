@php
    $editor = function ($field, $type) use ($pageId, $sectionIndex, $emitMarkers, $section) {
        if (! $emitMarkers) {
            return '';
        }
        $path = "page.{$pageId}.section.{$sectionIndex}.{$field}";
        $sectionType = $section['type'] ?? '';

        return ' data-editable="'.e($path).'" data-editable-type="'.e($type).'" data-editable-section-type="'.e($sectionType).'" data-editable-field="'.e($field).'"';
    };

    $cache = $site->reviews_cache ?? null;
    // Cache is authoritative (matches hero trust pill); section.provider is
    // a fallback for composer-stamped google defaults / missing cache key.
    $reviewsProvider = $cache['provider'] ?? $section['provider'] ?? 'google';
    $isCheckatrade = $reviewsProvider === 'checkatrade';
    $ratingScale = (int) ($cache['rating_scale'] ?? 5);
    if ($ratingScale < 1) {
        $ratingScale = 5;
    }
    $providerLabel = $isCheckatrade ? 'Checkatrade' : 'Google';
    $eyebrow = $section['eyebrow'] ?? 'WHAT CLIENTS SAY';
    $heading = $section['heading'] ?? 'What Our Happy Customers Say';
    $items = array_values(array_filter(
        array_slice($cache['reviews'] ?? [], 0, $section['max_items'] ?? 20),
        fn ($r) => trim($r['text'] ?? '') !== ''
    ));
    // Prefer explicit section.variant; fall back to display_style so
    // ArchetypeComposer-injected styles land without a separate pass.
    $reviewsStyle = ($section['variant'] ?? null) ?: ($section['display_style'] ?? 'carousel');
    $isGrid = $reviewsStyle === 'grid';
    // Layout collapses when there are fewer than 3 usable reviews so the
    // grid never trails empty slots on desktop. 1 card → centred at 2/3,
    // 2 cards → centred at 1/2 each, 3+ → standard 3-up carousel.
    $itemCount = count($items);
    $cardWidthMd = $itemCount >= 3 ? 'md:w-1/3' : ($itemCount === 2 ? 'md:w-1/2' : 'md:w-2/3');
    $trackJustifyMd = $itemCount < 3 ? 'md:justify-center' : '';
    $rating = isset($cache['rating']) ? number_format((float) $cache['rating'], 1) : null;
    $ratingDisplay = $rating === null
        ? null
        : ($ratingScale === 10 ? $rating.'/10' : $rating);
    $summaryStars = isset($cache['rating'])
        ? (int) round(((float) $cache['rating'] / $ratingScale) * 5)
        : 0;
    $total = (int) ($cache['user_ratings_total'] ?? 0);
    $countLabel = $total >= 90 ? floor($total / 10) * 10 . '+' : $total;
    // Google path keeps "Google Reviews"; Checkatrade uses lowercase "reviews".
    $countText = $isCheckatrade
        ? $countLabel.' '.$providerLabel.' reviews'
        : $countLabel.' '.$providerLabel.' Reviews';
    $intervalMs = (int) ($section['rotate_interval_ms'] ?? 6000);
    $carouselId = "reviews-carousel-{$pageId}-{$sectionIndex}";

    // Decorative parallax background — pulled from the home hero so the
    // section visually echoes the page's brand image without re-fetching.
    // Section-level override (section.background_image) wins if set later.
    $bgRaw = $section['background_image'] ?? ($homeHeroImageUrl ?? null);
    $bgImageUrl = is_array($bgRaw) ? ($bgRaw['url'] ?? null) : $bgRaw;
    // Overlay opacity — section-overridable. Lower lets the image dominate;
    // higher pushes brand colour for readability. Default lifted from 0.6 to
    // 0.75 (review feedback): at 0.6 the parallax photo still showed enough
    // detail that thin/serif strokes got "eaten" by background texture.
    $overlayOpacity = (float) ($section['overlay_opacity'] ?? 0.75);

    // Parallax variant uses pure white for all foreground text. WCAG-derived
    // on-primary (dark navy on a cyan wash) technically passes contrast but
    // gets eaten by the busy parallax photo (excavator shadows, dirt
    // texture). White + a soft dark drop shadow gives the whole section a
    // consistent "floats above the photo" feel. Plain-surface mode keeps
    // the original light-theme tokens.
    $hasParallax = (bool) $bgImageUrl;
    $accentTextStyle = $hasParallax ? '#ffffff' : 'var(--brand-primary)';
    $bodyTextStyle = $hasParallax ? '#ffffff' : 'var(--color-text)';
    $mutedTextStyle = $hasParallax ? '#ffffff' : 'var(--color-text-muted-on-alt, var(--color-text))';
    $parallaxTextShadow = $hasParallax ? '0 2px 8px rgba(0,0,0,0.5), 0 1px 3px rgba(0,0,0,0.4)' : 'none';
@endphp

@if (! empty($cache) && ! empty($items))
<div class="site-section-spacing relative overflow-hidden" style="background-color: var(--color-surface);"@if ($isCheckatrade) data-reviews-provider="checkatrade"@endif>
    @if ($bgImageUrl)
        {{-- Parallax background image. Desktop uses fixed attachment for the
             scroll-shift effect; mobile/Safari falls back to a static cover
             (background-attachment: fixed is broken on iOS and janky on
             low-end Android). --}}
        <div aria-hidden="true"
             class="absolute inset-0 bg-cover bg-center bg-no-repeat md:[background-attachment:fixed]"
             style="background-image: url('{{ $bgImageUrl }}');"></div>
        {{-- Brand-primary wash above the image. Sits below content (z-10). --}}
        <div aria-hidden="true"
             class="absolute inset-0"
             style="background-color: var(--brand-primary); opacity: {{ $overlayOpacity }};"></div>
    @endif

    {{-- Decorative oversized quote glyph — subtle background flourish --}}
    <div aria-hidden="true"
         class="pointer-events-none absolute -top-12 left-1/2 -translate-x-1/2 select-none hidden md:block z-[1]"
         style="font-family: Georgia, 'Times New Roman', serif; font-size: 24rem; line-height: 1; color: var(--brand-primary); opacity: 0.04;">
        &ldquo;
    </div>

    <div class="site-shell-container mx-auto px-4 sm:px-6 lg:px-8 relative z-10" style="max-width: var(--container-width);">
        {{-- Header — when parallax is active, the section wash is brand-primary
             so the eyebrow + heading must use the on-primary token, otherwise
             a brand-accent eyebrow on a brand-primary wash reads as
             cyan-on-cyan (invisible). --}}
        @php
            // Parallax variant — white text + soft dark drop shadow for "lift" off
            // the busy photo. Earlier we tried WCAG-derived dark navy here, but
            // even with a light halo the thin strokes were swallowed by the
            // parallax photo (excavator shadows, dirt texture). White flips the
            // contrast model and a low-opacity black shadow gives the letterforms
            // a subtle 3D pop without adding visual noise.
            $headerEyebrowStyle = $hasParallax ? '#ffffff' : 'var(--brand-accent-text)';
            $headerHeadingStyle = $hasParallax ? '#ffffff' : 'var(--color-text)';
            $headerRuleStyle = $hasParallax ? '#ffffff' : 'var(--brand-accent)';
            $headerTextShadow = $hasParallax ? '0 2px 8px rgba(0,0,0,0.5), 0 1px 3px rgba(0,0,0,0.4)' : 'none';
        @endphp
        <div class="text-center mb-12">
            <span class="text-sm font-bold tracking-widest uppercase mb-3 block"
                  style="color: {{ $headerEyebrowStyle }}; text-shadow: {{ $headerTextShadow }};"
                  {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
            <h2 class="text-3xl md:text-4xl font-extrabold leading-tight text-balance"
                style="color: {{ $headerHeadingStyle }}; text-shadow: {{ $headerTextShadow }};"
                {!! $editor('heading', 'plain') !!}>{{ $heading }}</h2>
            <div class="w-16 h-1 rounded-full mx-auto mt-5" style="background-color: {{ $headerRuleStyle }};"></div>
        </div>

        @if ($isGrid)
            {{-- Static grid — caps at 6 so a large cache does not become a wall.
                 Card markup mirrors the carousel track (stars, quote, author, time)
                 without snap/width classes the carousel needs. --}}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" data-reviews-variant="grid">
                @foreach (array_slice($items, 0, 6) as $review)
                    @php
                        // Scale 5 keeps the original int cast; other scales normalise onto 5 stars.
                        $stars = $ratingScale === 5
                            ? max(0, min(5, (int) ($review['rating'] ?? 5)))
                            : max(0, min(5, (int) round(((float) ($review['rating'] ?? $ratingScale) / $ratingScale) * 5)));
                        $author = trim($review['author_name'] ?? '');
                        $firstName = $author === '' ? 'Anonymous' : explode(' ', $author)[0];
                        $body = trim($review['text'] ?? '');
                    @endphp
                    <figure class="h-full flex flex-col rounded-xl p-6 md:p-7 relative"
                            style="background-color: var(--color-surface-alt); border: 1px solid var(--color-border); box-shadow: 0 4px 16px -8px rgba(0,0,0,0.08); border-radius: var(--radius-card, 0.75rem);">
                        {{-- Decorative inline quote mark, top-left of card.
                             Uses the card's own text token so contrast is guaranteed
                             regardless of theme — brand-primary tinted dark on a near-
                             black card surface read as ghost. --}}
                        <span aria-hidden="true"
                              class="absolute -top-3 left-3 select-none leading-none"
                              style="font-family: Georgia, 'Times New Roman', serif; font-size: 4.5rem; color: var(--color-text-on-alt); opacity: 0.3;">&ldquo;</span>

                        <div class="flex gap-0.5 mb-4 relative" aria-hidden="true">
                            @for ($s = 1; $s <= 5; $s++)
                                <svg class="w-4 h-4" viewBox="0 0 20 20"
                                     fill="{{ $s <= $stars ? '#fbbf24' : '#e5e7eb' }}">
                                    <path d="M10 1l3 6 6 1-4.5 4 1 6-5.5-3-5.5 3 1-6L1 8l6-1z"/>
                                </svg>
                            @endfor
                            <span class="sr-only">{{ $stars }} out of 5 stars</span>
                        </div>

                        <blockquote class="flex-1 text-base leading-relaxed"
                                    style="color: var(--color-text-on-alt);">
                            {{ \Illuminate\Support\Str::limit($body, 220) }}
                        </blockquote>

                        {{-- Reviewer name; the relative date is opt-in via
                             reviews_show_date_in_summary because old reviews
                             ("2 years ago") can make a business read as
                             dormant. Editors flip on when their review
                             cadence is fresh. --}}
                        <figcaption class="mt-5 pt-4 text-sm border-t"
                                    style="border-color: var(--color-border);">
                            <span class="font-semibold" style="color: var(--color-text-on-alt);">{{ $firstName }}</span>
                            @if ((bool) ($site->reviews_show_date_in_summary ?? false))
                                @php $relative = trim($review['relative_time_description'] ?? ''); @endphp
                                @if ($relative !== '')
                                    <span class="ml-2 text-xs" style="color: var(--color-text-on-alt); opacity: 0.6;">· {{ $relative }}</span>
                                @endif
                            @endif
                        </figcaption>
                    </figure>
                @endforeach
            </div>
        @else
            {{-- Carousel —
                 Mobile: 1 card visible, advance 100% per tick.
                 Desktop: 3 cards visible, advance 33.333% per tick (one card at a time).
                 Track translation is a single CSS calc reading --current; the multiplier
                 swaps via media query so the same Alpine state drives both layouts. --}}
            <style>
                #{{ $carouselId }} .reviews-track {
                    transform: translateX(calc(var(--current, 0) * -100%));
                    transition: transform 600ms cubic-bezier(0.4, 0, 0.2, 1);
                }
                @media (min-width: 768px) {
                    #{{ $carouselId }} .reviews-track {
                        transform: translateX(calc(var(--current, 0) * -33.3333%));
                    }
                }
            </style>

            <div id="{{ $carouselId }}"
                 class="relative"
                 role="region"
                 aria-roledescription="carousel"
                 aria-label="Customer reviews"
                 x-data="{
                     current: 0,
                     total: {{ count($items) }},
                     visible: 1,
                     paused: false,
                     timer: null,
                     syncVisible() { this.visible = window.matchMedia('(min-width: 768px)').matches ? 3 : 1; },
                     maxStart() { return Math.max(0, this.total - this.visible); },
                     start() {
                         if (this.total <= this.visible || this.timer) return;
                         this.timer = setInterval(() => { if (!this.paused) this.next(); }, {{ $intervalMs }});
                     },
                     stop() { if (this.timer) { clearInterval(this.timer); this.timer = null; } },
                     next() {
                         // Advance one card; wrap at the last *fully visible* start position
                         // so the rightmost cards never trail empty space on desktop.
                         this.current = this.current >= this.maxStart() ? 0 : this.current + 1;
                     },
                     prev() {
                         this.current = this.current <= 0 ? this.maxStart() : this.current - 1;
                     },
                 }"
                 x-init="
                     syncVisible();
                     start();
                     const onResize = () => { syncVisible(); if (current > maxStart()) current = maxStart(); };
                     window.addEventListener('resize', onResize);
                     $cleanup = () => { stop(); window.removeEventListener('resize', onResize); };
                 "
                 @mouseenter="paused = true"
                 @mouseleave="paused = false"
                 @focusin="paused = true"
                 @focusout="paused = false">

                <div class="overflow-hidden -mx-2 md:-mx-3">
                    <div class="reviews-track flex {{ $trackJustifyMd }}" :style="`--current: ${current}`">
                        @foreach ($items as $idx => $review)
                            @php
                                $stars = $ratingScale === 5
                                    ? max(0, min(5, (int) ($review['rating'] ?? 5)))
                                    : max(0, min(5, (int) round(((float) ($review['rating'] ?? $ratingScale) / $ratingScale) * 5)));
                                $author = trim($review['author_name'] ?? '');
                                $firstName = $author === '' ? 'Anonymous' : explode(' ', $author)[0];
                                $body = trim($review['text'] ?? '');
                            @endphp
                            <div class="flex-shrink-0 w-full {{ $cardWidthMd }} px-2 md:px-3"
                                 aria-roledescription="slide"
                                 aria-label="Review {{ $idx + 1 }} of {{ count($items) }}"
                                 x-bind:aria-hidden="!(current <= {{ $idx }} && {{ $idx }} < current + visible)">
                                <figure class="h-full flex flex-col rounded-xl p-6 md:p-7 relative"
                                        style="background-color: var(--color-surface-alt); border: 1px solid var(--color-border); box-shadow: 0 4px 16px -8px rgba(0,0,0,0.08); border-radius: var(--radius-card, 0.75rem);">
                                    {{-- Decorative inline quote mark, top-left of card.
                                         Uses the card's own text token so contrast is guaranteed
                                         regardless of theme — brand-primary tinted dark on a near-
                                         black card surface read as ghost. --}}
                                    <span aria-hidden="true"
                                          class="absolute -top-3 left-3 select-none leading-none"
                                          style="font-family: Georgia, 'Times New Roman', serif; font-size: 4.5rem; color: var(--color-text-on-alt); opacity: 0.3;">&ldquo;</span>

                                    <div class="flex gap-0.5 mb-4 relative" aria-hidden="true">
                                        @for ($s = 1; $s <= 5; $s++)
                                            <svg class="w-4 h-4" viewBox="0 0 20 20"
                                                 fill="{{ $s <= $stars ? '#fbbf24' : '#e5e7eb' }}">
                                                <path d="M10 1l3 6 6 1-4.5 4 1 6-5.5-3-5.5 3 1-6L1 8l6-1z"/>
                                            </svg>
                                        @endfor
                                        <span class="sr-only">{{ $stars }} out of 5 stars</span>
                                    </div>

                                    <blockquote class="flex-1 text-base leading-relaxed"
                                                style="color: var(--color-text-on-alt);">
                                        {{ \Illuminate\Support\Str::limit($body, 220) }}
                                    </blockquote>

                                    {{-- Reviewer name; the relative date is opt-in via
                                         reviews_show_date_in_summary because old reviews
                                         ("2 years ago") can make a business read as
                                         dormant. Editors flip on when their review
                                         cadence is fresh. --}}
                                    <figcaption class="mt-5 pt-4 text-sm border-t"
                                                style="border-color: var(--color-border);">
                                        <span class="font-semibold" style="color: var(--color-text-on-alt);">{{ $firstName }}</span>
                                        @if ((bool) ($site->reviews_show_date_in_summary ?? false))
                                            @php $relative = trim($review['relative_time_description'] ?? ''); @endphp
                                            @if ($relative !== '')
                                                <span class="ml-2 text-xs" style="color: var(--color-text-on-alt); opacity: 0.6;">· {{ $relative }}</span>
                                            @endif
                                        @endif
                                    </figcaption>
                                </figure>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Prev / Next chevrons — only render when there's more to scroll --}}
                @if (count($items) > 1)
                    <button type="button"
                            x-show="total > visible"
                            @click="prev()"
                            aria-label="Previous review"
                            class="absolute top-1/2 -translate-y-1/2 -left-2 md:-left-5 w-8 h-8 md:w-11 md:h-11 rounded-full flex items-center justify-center transition hover:scale-110 z-10"
                            style="background-color: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border); box-shadow: 0 2px 12px -4px rgba(0,0,0,0.15);">
                        <svg class="w-4 h-4 md:w-5 md:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </button>
                    <button type="button"
                            x-show="total > visible"
                            @click="next()"
                            aria-label="Next review"
                            class="absolute top-1/2 -translate-y-1/2 -right-2 md:-right-5 w-8 h-8 md:w-11 md:h-11 rounded-full flex items-center justify-center transition hover:scale-110 z-10"
                            style="background-color: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border); box-shadow: 0 2px 12px -4px rgba(0,0,0,0.15);">
                        <svg class="w-4 h-4 md:w-5 md:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </button>

                    {{-- Position counter — clearer than 20 dots once the count is high.
                         Uses 1-based indexing for human readability. --}}
                    <div class="flex items-center justify-center gap-2 mt-8 text-xs tracking-wide"
                         x-show="total > visible"
                         style="color: {{ $mutedTextStyle }}; text-shadow: {{ $parallaxTextShadow }};">
                        <span x-text="(current + 1)" class="font-bold" style="color: {{ $accentTextStyle }};"></span>
                        <span aria-hidden="true" class="opacity-40">/</span>
                        <span x-text="Math.min(current + visible, total)"></span>
                        <span aria-hidden="true" class="opacity-40">·</span>
                        <span x-text="total" class="opacity-70"></span>
                        <span class="opacity-70 ml-0.5">reviews</span>
                    </div>
                @endif
            </div>
        @endif

        {{-- Summary footer — reinforces source credibility right under the carousel.
             colour + text-shadow follow the same $hasParallax fork as body/accent text
             so monochrome currentColor marks (Checkatrade shield) and divider dots stay
             visible on the dark wash; light path keeps token body colour. --}}
        @if ($rating !== null && $total > 0)
            <div class="mt-10 flex flex-wrap items-center justify-center gap-x-3 gap-y-2 text-sm"
                 style="color: {{ $bodyTextStyle }}; text-shadow: {{ $parallaxTextShadow }};">
                @if ($isCheckatrade)
                {{-- Tick-in-shield mark for Checkatrade attribution (inherits row colour) --}}
                <svg class="w-5 h-5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path d="M12 3l7 3v5.5c0 4.5-3 8.5-7 9.5-4-1-7-5-7-9.5V6l7-3z"/>
                    <path d="M9 12l2 2 4-4"/>
                </svg>
                @else
                {{-- Google "G" multicolour mark (hardcoded fills stay brand-correct on both surfaces) --}}
                <svg class="w-5 h-5 flex-shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
                @endif
                <span class="font-extrabold text-2xl leading-none" style="color: {{ $accentTextStyle }};">{{ $ratingDisplay }}</span>
                <span class="flex gap-0.5" aria-hidden="true">
                    @for ($s = 1; $s <= 5; $s++)
                        <svg class="w-4 h-4" viewBox="0 0 20 20"
                             fill="{{ $s <= $summaryStars ? '#fbbf24' : '#e5e7eb' }}">
                            <path d="M10 1l3 6 6 1-4.5 4 1 6-5.5-3-5.5 3 1-6L1 8l6-1z"/>
                        </svg>
                    @endfor
                </span>
                @if ($isCheckatrade)
                    {{-- Wordmark only — no cached count, no "reviews" filler
                         (matches the hero pill treatment). Provider attribution,
                         NOT a count — so it renders regardless of the
                         show-count toggle (gating it made the
                         toggle silently drop the brand mark). Reversed over
                         the parallax image (white text mode). --}}
                    <span aria-hidden="true" class="opacity-30">·</span>
                    @include('site.partials._checkatrade-logo', ['reversed' => $hasParallax, 'height' => '1.5em'])
                @elseif ((bool) ($site->reviews_show_count_in_summary ?? true))
                    <span aria-hidden="true" class="opacity-30">·</span>
                    <span class="opacity-80" style="color: {{ $bodyTextStyle }};">{{ $countText }}</span>
                @endif
                {{-- "View All" only renders once there are enough reviews to be
                     worth advertising. Below 5 the link reads as "we have very
                     few reviews" — better to drop it than expose the gap. --}}
                @if (! empty($cache['url']) && $total >= 5)
                    <span aria-hidden="true" class="opacity-30">·</span>
                    <a href="{{ $cache['url'] }}" target="_blank" rel="noopener"
                       class="font-semibold hover:underline inline-flex items-center gap-1"
                       style="color: {{ $accentTextStyle }};">
                        View All
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                        </svg>
                    </a>
                @endif
            </div>
        @endif
    </div>
</div>
@endif

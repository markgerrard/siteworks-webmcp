@php
    // When __surface is contrast the wrapper is a different background
    // from its neighbours, so full site-section-spacing applies (the
    // background change absorbs the seam).
    $isContrast = ($section['__surface'] ?? null) === 'contrast';
    $wrapperBg = $isContrast ? 'var(--color-surface-contrast)' : 'var(--color-surface-alt)';
    $textOnWrapper = $isContrast ? 'var(--color-text-on-contrast)' : 'var(--color-text-on-alt)';
    $mutedOnWrapper = $isContrast ? 'var(--color-text-muted-on-contrast)' : 'var(--color-text-muted-on-alt)';
    $accentOnWrapper = $isContrast ? 'var(--brand-accent-text-on-contrast)' : 'var(--brand-accent-text-on-alt)';
@endphp
<div id="home-content" class="site-section-spacing scroll-mt-24 md:scroll-mt-28" style="background-color: {{ $wrapperBg }};">
    <div class="site-shell-container px-4 sm:px-6 lg:px-8">
        @if (!empty($section['title']))
            <div class="text-center mb-14">
                @if (empty($section['__suppress_eyebrow']))
                    <span class="text-sm font-bold tracking-widest uppercase mb-3 block" style="color: {{ $accentOnWrapper }};" {!! $editor('eyebrow', 'plain') !!}>{{ $eyebrow }}</span>
                @elseif ($emitMarkers)
                    <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                @endif
                <h2 class="text-3xl md:text-4xl font-extrabold mb-4" style="color: {{ $textOnWrapper }};"
                    {!! $editor('title', 'plain') !!}>{!! app(App\Services\Site\AccentWordRenderer::class)->wrap($section['title'], $section['accent_word'] ?? null, isset($site) && \App\Support\ChromeKnobs::accentStyle($site) === 'italic' ? 'italic' : null, $section['accent_ranges'] ?? null) !!}</h2>
                @if (!empty($section['intro']))
                    <div class="text-lg max-w-2xl mx-auto prose" style="color: {{ $mutedOnWrapper }};"
                         {!! $editor('intro', 'rich', is_array($section['intro']) ? $section['intro'] : null) !!}>{!! $richHtml($section['intro']) !!}</div>
                @else
                    @if ($emitMarkers)
                        <span class="hidden"{!! $editor('intro', 'rich') !!}></span>
                    @endif
                @endif
            </div>
        @else
            @if ($emitMarkers)
                <span class="hidden"{!! $editor('eyebrow', 'plain') !!}></span>
                <span class="hidden"{!! $editor('title', 'plain') !!}></span>
                <span class="hidden"{!! $editor('intro', 'rich') !!}></span>
            @endif
        @endif

        @if (!empty($section['items']))
            @php
                // Preferred: explicit linkage via item['source_service'] (the
                // exact profile product name copied into the card).
                // Running it through ServicePageSlugger here reproduces the
                // slug the pipeline used when creating the page — a
                // guaranteed match when the content gen is post-phase-10.
                $location = $site?->location ?? '';
                $scope = is_string($profile['geo']['scope'] ?? null) ? $profile['geo']['scope'] : 'local';
                $slugger = app(\App\Services\Site\ServicePageSlugger::class);

                $resolveBySource = function (?string $sourceService) use ($slugger, $location, $scope, $pagesBySlug) {
                    if (! is_string($sourceService) || trim($sourceService) === '') {
                        return null;
                    }
                    $slug = $slugger->makeSlug($sourceService, $location, $scope);

                    return $pagesBySlug[$slug] ?? null;
                };

                // photo-cards variant (Showcase home layout): resolve each card's
                // service-page hero image from the $heroImages map (page.blade.php
                // passes it to the parent scope; page_type IS the service slug). A card
                // only keeps its photo when the URL is unique across the section —
                // hero_source='shared' services all resolve to the same shared hero,
                // and three identical photos read as broken. Duplicates fall back to
                // the icon card.
                $usePhotoCards = ($section['variant'] ?? null) === 'photo-cards';
                $cardPhotoUrls = [];
                if ($usePhotoCards && ! empty($section['items'])) {
                    // Mirror hero.blade.php: watermark_url when watermark_enabled
                    // (default true), else clean url — sales previews must not
                    // serve liftable clean images on photo cards.
                    $watermarkOn = (bool) (($profile ?? [])['watermark_enabled'] ?? true);
                    foreach ($section['items'] as $i => $item) {
                        $src = $item['source_service'] ?? null;
                        $slug = (is_string($src) && trim($src) !== '') ? $slugger->makeSlug($src, $location, $scope) : null;
                        // Null-safe: PageRenderer may store null for a page_type when
                        // no dedicated/shared hero resolved (avoid offset-on-null).
                        $heroData = ($slug !== null) ? ($heroImages[$slug] ?? []) : [];
                        if (! is_array($heroData) || $heroData === []) {
                            $cardPhotoUrls[$i] = null;
                        } else {
                            $cardPhotoUrls[$i] = ($watermarkOn && ! empty($heroData['watermark_url']))
                                ? $heroData['watermark_url']
                                : (($heroData['url'] ?? null) ?: null);
                        }
                    }
                    $urlCounts = array_count_values(array_filter($cardPhotoUrls));
                    foreach ($cardPhotoUrls as $i => $url) {
                        if ($url !== null && ($urlCounts[$url] ?? 0) > 1) {
                            $cardPhotoUrls[$i] = null;
                        }
                    }
                }

                // Fallback chain for sites generated BEFORE source_service
                // landed in the home prompt. Match each service card to a
                // real service page by slugifying the card title and
                // looking it up in the pagesBySlug map. Four candidates in
                // order:
                //   1. exact slug match
                //   2. prefix match (card slug is the start of the page slug)
                //   3. word-subset match (every word in the card slug
                //      appears in the page slug, in any order, filler
                //      words ignored)
                //   4. null (no match → card renders without Read-more)
                // Single-word card slugs are rejected in step 3 to avoid
                // "Plumbing" matching every plumbing page.
                $resolveServiceHref = function ($title) use ($pagesBySlug) {
                    $base = \Illuminate\Support\Str::slug($title);
                    if (! $base) {
                        return null;
                    }
                    if (isset($pagesBySlug[$base])) {
                        return $pagesBySlug[$base];
                    }
                    foreach ($pagesBySlug as $slug => $href) {
                        if (str_starts_with($slug, $base.'-')) {
                            return $href;
                        }
                    }

                    // Word-subset fallback. Strip filler words (and, of, the,
                    // for, in, to, with, etc) from both sides before comparing
                    // so "irrigation-drainage" matches "irrigation-and-drainage-…".
                    $filler = ['and', 'of', 'the', 'for', 'in', 'to', 'with', 'a', 'an'];
                    $cardWords = array_values(array_diff(explode('-', $base), $filler));
                    if (count($cardWords) < 2) {
                        return null;
                    }
                    foreach ($pagesBySlug as $slug => $href) {
                        $pageWords = array_values(array_diff(explode('-', $slug), $filler));
                        if (array_diff($cardWords, $pageWords) === []) {
                            return $href;
                        }
                    }

                    return null;
                };
            @endphp
            @php
                // Auto-balance rows per card count. flex-basis maths: inner
                // row gap total is (cols - 1) × 2rem, divided across cols.
                $itemCount = count($section['items']);
                $cardBasis = match (true) {
                    $itemCount === 8 => 'calc(25% - 1.5rem)',   // 4×2
                    $itemCount === 4 => 'calc(50% - 1rem)',     // 2×2
                    default => 'calc(33.333% - 1.34rem)',       // 3×n
                };
            @endphp
            <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 2rem;">
                @foreach ($section['items'] as $i => $item)
                    @php
                        $serviceHref = $resolveBySource($item['source_service'] ?? null)
                            ?? $resolveServiceHref($item['title'] ?? '');
                        // Inverted-colour CTA card — one tile becomes a
                        // primary-bg "contact us" prompt that points home to
                        // the lead_form anchor or /contact.
                        $isContactCta = ($item['contact_cta'] ?? false) === true;
                        // Featured tier: darker treatment to draw the eye,
                        // matching the design system's "Most popular" centre-card
                        // pattern. Only active when no contact_cta override is set.
                        $isFeatured = ! $isContactCta && ($item['featured'] ?? false) === true;
                        $featuredLabel = $isFeatured
                            ? (isset($item['featured_label']) && $item['featured_label'] !== '' ? $item['featured_label'] : 'Most popular')
                            : null;
                        $cardBg = $isContactCta ? 'var(--brand-primary)' : ($isFeatured ? 'var(--color-text)' : 'var(--color-surface)');
                        $cardBorder = ($isContactCta || $isFeatured) ? 'transparent' : 'var(--color-border)';
                        $titleColor = ($isContactCta || $isFeatured) ? 'var(--color-text-on-primary, #ffffff)' : 'var(--color-text)';
                        $bodyColor = ($isContactCta || $isFeatured) ? 'var(--color-text-on-primary, #ffffff)' : 'var(--color-text-muted)';
                        // Regular cards: primary icon box with WCAG-safe text-on-primary icon.
                        // Contact-CTA cards: invert — dark slate box with white icon, high contrast
                        // against the card's primary-coloured background.
                        // Featured cards: accent-coloured icon box to maintain brand presence on dark bg.
                        $iconBg = $isContactCta ? 'var(--color-text-on-primary, #0f172a)' : 'var(--brand-primary)';
                        $iconColor = $isContactCta ? '#ffffff' : 'var(--color-text-on-primary, #ffffff)';
                        $ctaHref = $isContactCta ? ($pagesBySlug['contact'] ?? '#contact') : $serviceHref;
                        $ctaLabel = $isContactCta ? ($item['cta_label'] ?? 'Get in touch') : 'Read more';
                    @endphp
                    <div class="p-8 shadow-md border-t-4 transition-all duration-300 hover:shadow-xl hover:-translate-y-1{{ $isFeatured ? ' scale-[1.02]' : '' }}"
                         style="background-color: {{ $cardBg }};
                                border: 1px solid {{ $cardBorder }};
                                border-top-width: 4px;
                                border-top-color: var(--brand-primary);
                                border-radius: var(--radius-card);
                                flex: 0 1 {{ $cardBasis }};
                                min-width: 280px;">
                        @php $cardPhoto = $cardPhotoUrls[$i] ?? null; @endphp
                        @if ($cardPhoto)
                            {{-- Card uses p-8; negative margins bleed photo to card edges --}}
                            <div class="aspect-[4/3] overflow-hidden -mt-8 -mx-8 mb-4" data-service-card-photo
                                 style="border-radius: var(--radius-card) var(--radius-card) 0 0;">
                                <img src="{{ $cardPhoto }}" alt="{{ $item['title'] ?? '' }}" loading="lazy" class="w-full h-full object-cover">
                            </div>
                        @endif
                        @if ($isFeatured)
                            {{-- Featured-tier label pill above icon/number --}}
                            <div class="mb-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold"
                                      style="background-color: var(--brand-primary); color: var(--color-text-on-primary, #ffffff);">
                                    {{ $featuredLabel }}
                                </span>
                            </div>
                        @endif
                        @if (! $cardPhoto)
                            <div class="w-12 h-12 mb-5 flex items-center justify-center font-bold text-lg"
                                 style="background-color: {{ $iconBg }}; color: {{ $iconColor }}; border-radius: var(--radius-button);">
                                @php $iconHtml = $renderIcon($item['icon'] ?? null); @endphp
                                @if (!empty($iconHtml))
                                    {!! $iconHtml !!}
                                @else
                                    {{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}
                                @endif
                            </div>
                        @endif
                        @if ($emitMarkers)
                            <button type="button" class="hidden"{!! $editor("items.{$i}.icon", 'image') !!}></button>
                        @endif
                        <h3 class="text-xl font-bold mb-3" style="color: {{ $titleColor }};"
                            {!! $editor("items.{$i}.title", 'plain') !!}>{{ $item['title'] ?? '' }}</h3>
                        <div class="leading-relaxed mb-4 prose prose-sm" style="color: {{ $bodyColor }};"
                             {!! $editor("items.{$i}.body", 'rich', is_array($item['body'] ?? null) ? $item['body'] : null) !!}>{!! $richHtml($item['body'] ?? '') !!}</div>
                        @if ($ctaHref)
                            <a href="{{ $ctaHref }}"
                               class="group inline-flex items-center gap-2 text-xs font-bold uppercase tracking-[0.12em] pb-1 border-b-2 transition-all hover:gap-3 hover:brightness-110"
                               style="color: {{ $isContactCta ? '#ffffff' : 'var(--brand-accent)' }}; border-color: {{ $isContactCta ? 'rgba(255,255,255,0.4)' : 'var(--brand-accent)' }};">
                                {{ $ctaLabel }}
                                <svg class="w-3.5 h-3.5 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

@php
    $snapshot = $snapshot ?? [];
    $sharedHero = is_array($snapshot['shared_category_hero'] ?? null)
        ? $snapshot['shared_category_hero']
        : [];
    $heroMode = $category['hero_mode'] ?? null;
    if ($heroMode === null) {
        $legacyEnabled = \App\Support\Shop\ShopHeroBand::isEnabled($category['hero_enabled'] ?? true);
        $heroMode = ($legacyEnabled && ! empty($category['hero_image_url'])) ? 'custom' : 'none';
    }
    $sharedImage = is_string($sharedHero['image_url'] ?? null) ? trim((string) $sharedHero['image_url']) : '';
    $sharedHeroEnabled = (bool) ($sharedHero['enabled'] ?? true);
    if ($heroMode === 'shared' && ($sharedImage === '' || ! $sharedHeroEnabled)) {
        $heroMode = 'none';
    }
    if ($heroMode === 'shared') {
        $heroImageUrl = $sharedHero['image_url'] ?? '';
        $heroHeight = $sharedHero['height'] ?? 'medium';
        $bgPositionY = $sharedHero['bg_position_y'] ?? 50;
        $textZone = $sharedHero['text_zone'] ?? 'middle-left';
        $heroWidth = $sharedHero['width'] ?? 'boxed';
    } else {
        $heroImageUrl = $category['hero_image_url'] ?? '';
        $heroHeight = $category['hero_height'] ?? 'medium';
        $bgPositionY = $category['bg_position_y'] ?? 50;
        $textZone = $category['text_zone'] ?? 'middle-left';
        $heroWidth = $category['hero_width'] ?? 'boxed';
    }
    $hasIntroBand = filter_var($category['intro_band'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $hasHero = ! $hasIntroBand && in_array($heroMode, ['custom', 'shared'], true) && ! empty($heroImageUrl);
    $introImageUrl = is_string($category['hero_image_url'] ?? null) ? trim((string) $category['hero_image_url']) : '';
    if ($introImageUrl === '') {
        $introImageUrl = $sharedImage;
    }
    $showBandHeader = $hasHero || $hasIntroBand;
    $categoryTextStyle = is_string($category['hero_text_style'] ?? null) ? $category['hero_text_style'] : '';
    if ($categoryTextStyle !== '') {
        $heroTextStyle = $categoryTextStyle;
    } elseif ($heroMode === 'shared') {
        $heroTextStyle = is_string($sharedHero['text_style'] ?? null) ? $sharedHero['text_style'] : '';
    } else {
        $heroTextStyle = '';
    }
    $heroCopySurfaceStyle = \App\Support\Shop\ShopHeroBand::copySurfaceStyle($heroTextStyle);
    $heroPaddingClass = \App\Support\Shop\ShopHeroBand::paddingClass($heroHeight, 'category');
    $heroWidthClass = \App\Support\Shop\ShopHeroBand::widthClass($heroWidth);
    $heroOverlayClass = \App\Support\Shop\ShopHeroBand::overlayClass($textZone);
    $heroOverlayGradient = \App\Support\Shop\ShopHeroBand::overlayGradient($textZone);
    $heroVerticalClass = \App\Support\Shop\ShopHeroBand::verticalClass($textZone);
    $heroHorizontalClass = \App\Support\Shop\ShopHeroBand::horizontalClass($textZone);
    $shopLayoutCtx = app(\App\Services\Site\PageRenderer::class)->layoutContext($site);
    $heroTitleClass = \App\Support\Shop\ShopHeroBand::titleClass($shopLayoutCtx['renderTokens']['hero_inner_clamp_cap'] ?? '3rem');
    $heroSubtitleClass = \App\Support\Shop\ShopHeroBand::subtitleClass();
    $bgPositionStyle = "center {$bgPositionY}%";
    $homeHref = $shopLayoutCtx['homeHref'];
    $productCount = (int) (($listing['total'] ?? null) ?? count($products));
    $shopNoun = \App\Support\Shop\ShopCopy::pair($site);
    $children = $children ?? [];
    $categoryPath = $category['path'] ?? $category['slug'];
    $categoryContent = $categoryContent ?? [];
    $longCopy = $categoryContent['description_long'] ?? null;
    $faqs = is_array($categoryContent['faqs'] ?? null) ? array_values($categoryContent['faqs']) : [];
    $pageTitle = ($categoryContent['meta_title'] ?? null) ?: ($category['meta_title'] ?? null) ?: ($category['name'].' — '.$site->business_name);
    $fallbackDescription = mb_substr(trim(strip_tags((string) ($category['description'] ?? ''))), 0, 160);
    $pageDescription = ($categoryContent['meta_description'] ?? null) ?: ($category['meta_description'] ?? null) ?: ($fallbackDescription !== '' ? $fallbackDescription : null);
    $canonicalPath = \App\Support\Shop\ShopUrls::collectionAbsolute($categoryPath);
    $listingState = is_array($listing['state'] ?? null) ? $listing['state'] : [];
    $listingPage = (int) ($listing['page'] ?? 1);
    $listingLast = (int) ($listing['lastPage'] ?? 1);
    $listingFiltered = (int) ($listing['activeFilterCount'] ?? 0) > 0
        || (bool) ($listingState['sortExplicit'] ?? false);
    $canonical = $canonicalPath;
    if (! $listingFiltered && $listingPage > 1) {
        $canonical = \App\Support\Shop\ShopListingQuery::url($canonicalPath, $listingState, ['page' => $listingPage]);
    }
    $relPrev = $listingPage > 1
        ? \App\Support\Shop\ShopListingQuery::url($canonicalPath, $listingState, ['page' => $listingPage - 1])
        : null;
    $relNext = $listingPage < $listingLast
        ? \App\Support\Shop\ShopListingQuery::url($canonicalPath, $listingState, ['page' => $listingPage + 1])
        : null;
    $robots = (($category['visibility'] ?? 'visible') === 'hidden') ? 'noindex' : null;
    $crumbs = $category['breadcrumb'] ?? [['name' => $category['name'], 'path' => $categoryPath]];
    $trail = [
        ['label' => 'Home', 'href' => $homeHref],
        ['label' => 'Shop', 'href' => url('/shop')],
    ];
    foreach ($crumbs as $i => $crumb) {
        $isLast = $i === array_key_last($crumbs);
        $trail[] = $isLast
            ? ['label' => $crumb['name']]
            : ['label' => $crumb['name'], 'href' => \App\Support\Shop\ShopUrls::collectionAbsolute($crumb['path'])];
    }
    $ogImage = \App\Support\Shop\ShopOpenGraph::categoryImage($category, $products, $site);
    $ogDimensions = \App\Support\Shop\ShopOpenGraph::categoryDimensions($category, $products, $site);
@endphp
<x-shop.layout :site="$site" :nav-hero-below="$hasHero" :title="$pageTitle" :meta-description="$pageDescription" :canonical="$canonical" :robots="$robots" :rel-prev="$relPrev" :rel-next="$relNext" :og-image="$ogImage" :og-image-width="$ogDimensions['width']" :og-image-height="$ogDimensions['height']" :og-url="$canonical">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        {{-- With a hero the breadcrumb sits UNDER the band;
             without one it stays on top as the page's first line. --}}
        @if (! $showBandHeader)
            <x-shop.breadcrumbs :trail="$trail" />
        @endif

        @if ($hasIntroBand)
            <section data-category-intro-band class="flex items-center gap-6 py-7 md:py-8 {{ \App\Support\Shop\ShopHeroBand::gutterClass() }} max-w-full"
                     style="background-color: color-mix(in srgb, var(--brand-primary) {{ \App\Support\Shop\ShopHeroBand::DEFAULT_PANEL_OPACITY }}%, transparent); border-radius: var(--radius-card);">
                <div class="min-w-0 flex-1">
                    <h1 class="text-2xl md:text-3xl font-extrabold leading-tight [text-wrap:balance]">{!! \App\Support\Shop\ShopHeroBand::wrapTitle($category['name'] ?? '', $category['hero_accent_word'] ?? null, $site) !!}</h1>
                    @if (! empty($category['description']))
                        <p class="mt-2 text-base md:text-lg max-w-2xl" style="color: var(--color-text-muted);">{{ $category['description'] }}</p>
                    @endif
                </div>
                @if ($introImageUrl !== '')
                    <img src="{{ $introImageUrl }}" alt="{{ $category['hero_alt'] ?? ($category['name'] ?? '') }}" class="h-24 w-24 md:h-28 md:w-28 flex-shrink-0 object-contain">
                @endif
            </section>
            <div class="mt-4">
                <x-shop.breadcrumbs :trail="$trail" />
            </div>
        @elseif ($hasHero)
            <section class="relative {{ $heroPaddingClass }} overflow-hidden flex flex-col {{ $heroVerticalClass }} max-w-full {{ $heroWidthClass }}"
                     style="background-image: url('{{ $heroImageUrl }}'); background-size: cover; background-position: {{ $bgPositionStyle }};{{ \App\Support\Shop\ShopHeroBand::widthStyle($heroWidth, flush: true) }}">
                <div class="absolute inset-0 {{ $heroOverlayClass }}" style="background: {{ $heroOverlayGradient }};"></div>
                <div class="relative z-10 w-full max-w-full {{ \App\Support\Shop\ShopHeroBand::innerClass($heroWidth) }}">
                    <div class="max-w-3xl {{ $heroHorizontalClass }}"@if ($heroCopySurfaceStyle !== '') style="{{ $heroCopySurfaceStyle }}"@endif>
                        <h1 class="{{ $heroTitleClass }}">{!! \App\Support\Shop\ShopHeroBand::wrapTitle($category['name'] ?? '', $category['hero_accent_word'] ?? null, $site) !!}</h1>
                        @if (! empty($category['description']))
                            <p class="{{ $heroSubtitleClass }} {{ $heroHorizontalClass }}">{{ $category['description'] }}</p>
                        @endif
                    </div>
                </div>
                <div class="absolute bottom-0 inset-x-0 h-1.5" style="background-color: var(--color-accent);"></div>
            </section>
            <div class="mt-4">
                <x-shop.breadcrumbs :trail="$trail" />
            </div>
        @else
            <h1 class="text-3xl md:text-4xl font-extrabold mt-4">{{ $category['name'] }}</h1>
            @if (! empty($category['description']))
                <p class="mt-3 text-lg max-w-2xl" style="color: var(--color-text-muted);">{{ $category['description'] }}</p>
            @endif
        @endif

        @include('shop.partials.filters', [
            'facets' => $category['facets'] ?? [],
            'listing' => $listing ?? null,
            'listingPath' => \App\Support\Shop\ShopUrls::collectionAbsolute($categoryPath),
        ])

        @if ($children !== [])
            @php $childrenHaveImages = collect($children)->every(fn ($c) => ! empty($c['hero_image_url'])); @endphp
            @if ($childrenHaveImages)
            <section class="mt-6 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-6 max-w-full" aria-label="Sub-categories">
                @foreach ($children as $child)
                    <a href="{{ \App\Support\Shop\ShopUrls::collection($child['path'] ?? $child['slug']) }}"
                       class="block overflow-hidden focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                       style="border-radius: var(--radius-card); outline-color: var(--color-accent); background-color: var(--color-surface-alt);">
                        <img src="{{ $child['hero_image_url'] }}" alt="{{ $child['name'] }}" width="640" height="360" class="w-full object-cover" style="aspect-ratio: 16 / 9;">
                        <p class="px-3 py-3 font-semibold">{{ $child['name'] }}</p>
                    </a>
                @endforeach
            </section>
            @else
            {{-- No category images yet: a quiet pill row, same language as the category chips above. --}}
            <nav class="mt-4 flex flex-wrap items-center gap-2" aria-label="Sub-categories">
                <span class="text-xs font-bold tracking-widest uppercase mr-1" style="color: var(--color-text-muted);">In this category</span>
                @foreach ($children as $child)
                    <a href="{{ \App\Support\Shop\ShopUrls::collection($child['path'] ?? $child['slug']) }}"
                       class="inline-flex items-center px-4 py-2 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                       style="border: 1px solid var(--color-border); border-radius: 9999px; color: var(--color-text); background-color: var(--color-surface); outline-color: var(--color-accent);">{{ $child['name'] }}</a>
                @endforeach
            </nav>
            @endif
        @endif

        <section class="py-8 md:py-10">
            @if ($productCount === 0)
                <x-shop.empty-state :message="'No '.$shopNoun['plural'].' in this category yet. Try one of the categories above.'" action="Browse the shop" href="/shop" />
            @else
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-6 max-w-full">
                    @foreach ($products as $p)
                        @include('shop.partials.product-card', ['product' => $p])
                    @endforeach
                </div>
                @include('shop.partials.listing-pagination', [
                    'listing' => $listing ?? null,
                    'listingPath' => \App\Support\Shop\ShopUrls::collectionAbsolute($categoryPath),
                ])
            @endif
        </section>

        @if ($longCopy || $faqs !== [])
            <section class="py-10 md:py-14" aria-label="{{ $category['name'] }} details">
                <div class="{{ ($longCopy && $faqs !== []) ? 'grid gap-10 lg:grid-cols-2 lg:gap-12 lg:items-start' : 'max-w-3xl' }}">
                    @if ($longCopy)
                        <div data-category-long-copy
                             style="background-color: var(--color-surface-alt); border: 1px solid var(--color-border); border-radius: var(--radius-card); padding: 1.75rem 2rem;">{!! $longCopy !!}</div>
                    @endif

                    @if ($faqs !== [])
                        <div x-data="{ open: null }" data-category-faqs>
                            <h2 class="text-2xl font-bold">Frequently asked questions</h2>
                            <div class="mt-4 divide-y" style="border-color: var(--color-border);">
                                @foreach ($faqs as $faq)
                                    <details
                                        class="py-3"
                                        x-bind:open="open === {{ $loop->index }}"
                                        x-on:toggle="if ($event.target.open) { open = {{ $loop->index }} }"
                                    >
                                        <summary class="cursor-pointer font-semibold">{{ $faq['q'] }}</summary>
                                        <p class="mt-3" style="color: var(--color-text-muted);">{{ $faq['a'] }}</p>
                                    </details>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
                <style>
                    [data-category-long-copy] { line-height: 1.75; color: var(--color-text-muted); }
                    [data-category-long-copy] h2, [data-category-long-copy] h3 { color: var(--color-text); font-weight: 700; margin: 1.6em 0 0.5em; }
                    [data-category-long-copy] h2 { font-size: 1.2rem; }
                    [data-category-long-copy] h3 { font-size: 1.05rem; }
                    [data-category-long-copy] h2:first-child { margin-top: 0; }
                    [data-category-long-copy] p { margin: 0 0 1em; }
                    [data-category-long-copy] ul { list-style: disc; margin: 0.5em 0 1.25em; padding-left: 1.25rem; }
                    [data-category-long-copy] li { margin-bottom: 0.5em; }
                    [data-category-long-copy] a { color: var(--color-accent-text); text-decoration: underline; text-underline-offset: 2px; }
                    [data-category-long-copy] strong { color: var(--color-text); }
                </style>
            </section>
        @endif
    </div>
    @php
        $breadcrumbJsonLd = \App\Support\Shop\ShopJsonLd::breadcrumbList($trail, $canonical);
    @endphp
    <script type="application/ld+json">{!! \App\Support\Shop\ShopJsonLd::encode($breadcrumbJsonLd) !!}</script>
    @if ($faqs !== [])
        <script type="application/ld+json">{!! \App\Support\Shop\ShopJsonLd::encode(\App\Support\Shop\ShopJsonLd::faqPage($faqs)) !!}</script>
    @endif
</x-shop.layout>

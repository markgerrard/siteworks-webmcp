@php
    $hasHero = ! empty($snapshot['hero_image_url'])
        && \App\Support\Shop\ShopHeroBand::isEnabled($snapshot['hero_enabled'] ?? true);
    $heroHeight = $snapshot['hero_height'] ?? 'medium';
    $bgPositionY = $snapshot['bg_position_y'] ?? 50;
    $textZone = $snapshot['text_zone'] ?? 'middle-left';
    $heroWidth = $snapshot['hero_width'] ?? 'boxed';
    $heroPaddingClass = \App\Support\Shop\ShopHeroBand::paddingClass($heroHeight);
    $heroWidthClass = \App\Support\Shop\ShopHeroBand::widthClass($heroWidth);
    $heroOverlayClass = \App\Support\Shop\ShopHeroBand::overlayClass($textZone);
    $heroOverlayGradient = \App\Support\Shop\ShopHeroBand::overlayGradient($textZone);
    $heroVerticalClass = \App\Support\Shop\ShopHeroBand::verticalClass($textZone);
    $heroHorizontalClass = \App\Support\Shop\ShopHeroBand::horizontalClass($textZone);
    $shopLayoutCtx = app(\App\Services\Site\PageRenderer::class)->layoutContext($site);
    $heroTitleClass = \App\Support\Shop\ShopHeroBand::titleClass($shopLayoutCtx['renderTokens']['hero_inner_clamp_cap'] ?? '3rem');
    $heroHeadline = trim((string) ($snapshot['hero_headline'] ?? ''));
    if ($heroHeadline === '') {
        $heroHeadline = 'Shop';
    }
    $heroTextStyle = is_string($snapshot['hero_text_style'] ?? null)
        ? $snapshot['hero_text_style']
        : '';
    $heroCopySurfaceStyle = \App\Support\Shop\ShopHeroBand::copySurfaceStyle($heroTextStyle);
    $bgPositionStyle = "center {$bgPositionY}%";
    $homeHref = $shopLayoutCtx['homeHref'];
    $panel = \App\Support\Shop\ShopSearchPanel::for($site);
    $shopNoun = \App\Support\Shop\ShopCopy::pair($site);
    $productsBySlug = $snapshot['products'] ?? [];
    $canonicalPath = url('/shop');
    $canonical = $canonicalPath;
    $pageTitle = 'Shop — '.$site->business_name;
    $trail = [
        ['label' => 'Home', 'href' => $homeHref],
        ['label' => 'Shop', 'href' => $canonicalPath],
    ];
    $indexHasCategories = $indexHasCategories ?? false;
    if (! isset($indexHasCategories) || $indexHasCategories === false) {
        foreach ($snapshot['categories'] ?? [] as $cat) {
            $catSlugs = array_values(array_filter($cat['product_slugs'] ?? []));
            if ($catSlugs !== [] && ($cat['visibility'] ?? 'visible') === 'visible') {
                $indexHasCategories = true;
                break;
            }
        }
    }
    $relPrev = null;
    $relNext = null;
    if (is_array($listing ?? null)) {
        $listingState = is_array($listing['state'] ?? null) ? $listing['state'] : [];
        $listingPage = (int) ($listing['page'] ?? 1);
        $listingLast = (int) ($listing['lastPage'] ?? 1);
        $listingVariant = (int) ($listing['activeFilterCount'] ?? 0) > 0
            || (bool) ($listingState['sortExplicit'] ?? false);

        if (! $listingVariant && $listingPage > 1) {
            $canonical = \App\Support\Shop\ShopListingQuery::url($canonicalPath, $listingState, ['page' => $listingPage]);
        }
        $relPrev = $listingPage > 1
            ? \App\Support\Shop\ShopListingQuery::url($canonicalPath, $listingState, ['page' => $listingPage - 1])
            : null;
        $relNext = $listingPage < $listingLast
            ? \App\Support\Shop\ShopListingQuery::url($canonicalPath, $listingState, ['page' => $listingPage + 1])
            : null;
    }
@endphp
<x-shop.layout :site="$site" :nav-hero-below="$hasHero" :title="$pageTitle" :canonical="$canonical" :rel-prev="$relPrev" :rel-next="$relNext" :og-image="$site->ogImageUrl()" :og-url="$canonical">
    <div class="px-4 sm:px-6 lg:px-8 py-6 max-w-full">
        {{-- No visible breadcrumb on the storefront index — $trail still feeds the BreadcrumbList JSON-LD below. --}}
        @if ($hasHero)
            <section class="relative {{ $heroPaddingClass }} overflow-hidden flex flex-col {{ $heroVerticalClass }} max-w-full {{ $heroWidthClass }}"
                     style="background-image: url('{{ $snapshot['hero_image_url'] }}'); background-size: cover; background-position: {{ $bgPositionStyle }};{{ \App\Support\Shop\ShopHeroBand::widthStyle($heroWidth, flush: true) }}">
                <div class="absolute inset-0 {{ $heroOverlayClass }}" style="background: {{ $heroOverlayGradient }};"></div>
                <div class="relative z-10 w-full max-w-full {{ \App\Support\Shop\ShopHeroBand::innerClass($heroWidth) }}">
                    <div class="max-w-3xl {{ $heroHorizontalClass }}"@if ($heroCopySurfaceStyle !== '') style="{{ $heroCopySurfaceStyle }}"@endif>
                        <h1 class="{{ $heroTitleClass }}">
                            {!! \App\Support\Shop\ShopHeroBand::wrapTitle($heroHeadline, $snapshot['hero_accent_word'] ?? null, $site) !!}
                        </h1>
                    </div>
                </div>
                <div class="absolute bottom-0 inset-x-0 h-1.5" style="background-color: var(--color-accent);"></div>
            </section>
        @else
            <h1 class="text-3xl md:text-4xl font-extrabold mt-4">{{ $heroHeadline }}</h1>
        @endif

        @include('shop.partials.filters', ['facets' => $snapshot['facets'] ?? [], 'indexMode' => true])

        <div class="mt-6">
            @include('shop.partials.category-chips', [
                'categories' => $panel['categories'],
                'current' => null,
            ])
        </div>

        @foreach (\App\Support\Shop\ShopIndexBlocks::normalize($site->shop_index_blocks) as $blockIndex => $block)
            @if (($block['type'] ?? 'featured_products') === 'trust_strip')
                @include('site.sections.trust_strip', [
                    'pageId' => 0,
                    'sectionIndex' => $blockIndex,
                    'emitMarkers' => false,
                    'site' => $site,
                    'section' => $block,
                ])
            @else
                @include('site.sections.featured_products', [
                'pageId' => 0,
                'sectionIndex' => $blockIndex,
                'emitMarkers' => false,
                'mode' => 'public',
                'site' => $site,
                'section' => [
                    'type' => 'featured_products',
                    'title' => $block['heading'],
                    'source' => $block['source'],
                    'limit' => $block['limit'],
                    'layout' => $block['layout'],
                    '__suppress_eyebrow' => true,
                ],
                ])
            @endif
        @endforeach

        @if (! $indexHasCategories)
            @php $flatProducts = array_filter(($listing['products'] ?? $productsBySlug), 'is_array'); @endphp
            @if ($flatProducts === [])
                <div class="py-12 md:py-16">
                    <x-shop.empty-state message="Our shop is being stocked — check back soon." action="Back to the homepage" href="/" />
                </div>
            @else
                @include('shop.partials.listing-toolbar', [
                    'listing' => $listing ?? null,
                    'listingPath' => url('/shop'),
                    'showFilter' => false,
                ])
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-6 max-w-full py-8 md:py-10">
                    @foreach ($flatProducts as $p)
                        @include('shop.partials.product-card', ['product' => $p])
                    @endforeach
                </div>
                @include('shop.partials.listing-pagination', [
                    'listing' => $listing ?? null,
                    'listingPath' => url('/shop'),
                ])
            @endif
        @else
            @if (! empty($snapshot['featured_slugs']))
                <section class="py-8 md:py-10">
                    <div class="text-center mb-8">
                        <span class="text-sm font-bold tracking-widest uppercase mb-2 block" style="color: var(--color-accent);">Featured</span>
                        <h2 class="text-3xl md:text-4xl font-extrabold">Pick of the range</h2>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-6 max-w-full">
                        @foreach ($snapshot['featured_slugs'] as $slug)
                            @php $p = $snapshot['products'][$slug] ?? null; @endphp
                            @if ($p)
                                @include('shop.partials.product-card', ['product' => $p])
                            @endif
                        @endforeach
                    </div>
                </section>
            @endif

            @foreach ($snapshot['categories'] ?? [] as $cat)
                @php
                    $slugs = array_values(array_filter($cat['product_slugs'] ?? []));
                    $count = count($slugs);
                @endphp
                @if ($count > 0 && ($cat['visibility'] ?? 'visible') === 'visible')
                    <section class="py-8 md:py-10">
                        <div class="flex flex-wrap items-baseline justify-between gap-3 mb-6">
                            <h2 class="text-2xl md:text-3xl font-extrabold">
                                {{ $cat['name'] }}
                                <span class="text-base font-medium" style="color: var(--color-text-muted);">{{ $count }} {{ $count === 1 ? $shopNoun['singular'] : $shopNoun['plural'] }}</span>
                            </h2>
                            <a href="{{ \App\Support\Shop\ShopUrls::collection($cat['path'] ?? $cat['slug']) }}" class="text-sm font-semibold" style="color: var(--color-accent);">See all →</a>
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-6 max-w-full">
                            @foreach (array_slice($slugs, 0, 4) as $slug)
                                @php $p = $productsBySlug[$slug] ?? null; @endphp
                                @if ($p)
                                    @include('shop.partials.product-card', ['product' => $p])
                                @endif
                            @endforeach
                        </div>
                    </section>
                @endif
            @endforeach
        @endif
    </div>
    @php
        $breadcrumbJsonLd = \App\Support\Shop\ShopJsonLd::breadcrumbList($trail, $canonical);
    @endphp
    <script type="application/ld+json">{!! \App\Support\Shop\ShopJsonLd::encode($breadcrumbJsonLd) !!}</script>
</x-shop.layout>

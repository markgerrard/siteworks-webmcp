@props([
    'site',
    'navHeroBelow' => false,
    'title' => null,
    'metaDescription' => null,
    'canonical' => null,
    'robots' => null,
    'relPrev' => null,
    'relNext' => null,
    'ogTitle' => null,
    'ogDescription' => null,
    'ogImage' => null,
    'ogImageWidth' => null,
    'ogImageHeight' => null,
    'ogType' => 'website',
    'ogUrl' => null,
    'productPriceAmount' => null,
    'productPriceCurrency' => null,
])
@php
    // Reuse the site's published chrome so shop pages wear the same
    // theme/nav/footer as the rest of the brand.
    $ctx = app(\App\Services\Site\PageRenderer::class)->layoutContext($site);
    $tokens = $ctx['renderTokens'];
    $navItems = $ctx['navItems'];
    $logoUrl = $ctx['logoUrl'];
    $profile = $ctx['profile'];
    $homeHref = $ctx['homeHref'];
    $composition = $ctx['composition'];
    $themeKey = $composition['theme']['key'] ?? $site->theme ?? 'trades-bold';
    $topBarEnabled = $profile['top_bar_enabled'] ?? true;
    $shellInsetXl = $tokens['shell_inset_xl'] ?? '';
    $shellInsetXlRule = $shellInsetXl !== ''
        ? '@media (min-width: 1280px) { body[data-display-scale="grand"] .site-shell-container { padding-left: '.$shellInsetXl.'; padding-right: '.$shellInsetXl.'; } }'
        : '';
@endphp
<!DOCTYPE html>
<html lang="en-GB" data-theme="{{ $themeKey }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? ($site->business_name.' — Shop') }}</title>
    @if (filled($metaDescription))
        <meta name="description" content="{{ $metaDescription }}">
    @endif
    @if (filled($canonical))
        <link rel="canonical" href="{{ $canonical }}">
    @endif
    @if (filled($relPrev))
        <link rel="prev" href="{{ $relPrev }}">
    @endif
    @if (filled($relNext))
        <link rel="next" href="{{ $relNext }}">
    @endif
    @if (filled($robots))
        <meta name="robots" content="{{ $robots }}">
    @endif
    @php
        $ogTitle = $ogTitle ?? $title ?? ($site->business_name.' — Shop');
        // Share previews show og:title + domain and ignore og:site_name, so carry the business name in the title itself.
        if (filled($site->business_name) && ! str_contains($ogTitle, $site->business_name)) {
            $ogTitle .= ' — '.$site->business_name;
        }
        $ogDescription = $ogDescription ?? $metaDescription;
        $ogUrl = $ogUrl ?? $canonical ?? request()->url();
        $ogImage = $ogImage ?? $site->ogImageUrl();
        $ogImageIsCard = filled($ogImage) && $ogImage === $site->ogImageUrl();
        // Scrapers reject root-relative share URLs: absolutise against the request host.
        if (is_string($ogImage) && str_starts_with($ogImage, '/') && ! str_starts_with($ogImage, '//')) {
            $ogImage = url($ogImage);
        }
        $ogCardDimensions = $ogImageIsCard ? $site->ogImageCardDimensions() : ['width' => null, 'height' => null];
        $ogImageWidth = $ogImageWidth ?? $ogCardDimensions['width'];
        $ogImageHeight = $ogImageHeight ?? $ogCardDimensions['height'];
    @endphp
    @if (filled($ogTitle))
        <meta property="og:title" content="{{ $ogTitle }}">
        <meta name="twitter:title" content="{{ $ogTitle }}">
    @endif
    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:site_name" content="{{ $site->business_name }}">
    @if (filled($ogUrl))
        <meta property="og:url" content="{{ $ogUrl }}">
    @endif
    @if (filled($ogDescription))
        <meta property="og:description" content="{{ $ogDescription }}">
        <meta name="twitter:description" content="{{ $ogDescription }}">
    @endif
    <meta name="twitter:card" content="summary_large_image">
    @if (filled($ogImage))
        <meta property="og:image" content="{{ $ogImage }}">
        <meta name="twitter:image" content="{{ $ogImage }}">
        @if (filled($ogImageWidth))
            <meta property="og:image:width" content="{{ $ogImageWidth }}">
        @endif
        @if (filled($ogImageHeight))
            <meta property="og:image:height" content="{{ $ogImageHeight }}">
        @endif
        {{-- Second og:image is the 1:1 card for platforms that crop landscape previews (WhatsApp, some LinkedIn/iMessage clients). --}}
        @if ($ogImageIsCard && ! $site->ogImageIsCustom() && $site->ogImageSquareUrl())
            <meta property="og:image" content="{{ $site->ogImageSquareUrl() }}">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="1200">
        @endif
    @endif
    @if ($ogType === 'product' && filled($productPriceAmount) && filled($productPriceCurrency))
        <meta property="product:price:amount" content="{{ $productPriceAmount }}">
        <meta property="product:price:currency" content="{{ $productPriceCurrency }}">
    @endif
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="{{ $tokens['font_link_href'] }}" rel="stylesheet" />
    @php
        $surfaceKey = config('surfaces.current', 'all');
        $siteBuildDir = $surfaceKey === 'all' ? 'build' : 'build-'.$surfaceKey;
    @endphp
    @vite('resources/css/site.css', $siteBuildDir)
    <script defer src="/vendor/alpine.min.js"></script>
    <script defer src="/vendor/lucide.min.js"></script>
    <style>
        :root {
            --site-header-height: {{ \App\Services\Site\HeaderPresentation::scrolledHeaderHeight($site, $tokens) }};
            --color-primary: {{ $tokens['primary'] }};
            --color-primary-text: {{ $tokens['primary_text'] }};
            --color-primary-text-on-alt: {{ $tokens['primary_text_on_alt'] }};
            --color-accent: {{ $tokens['accent'] }};
            --color-accent-text: {{ $tokens['accent_text'] }};
            --color-accent-text-on-alt: {{ $tokens['accent_text_on_alt'] }};
            --color-tertiary: {{ $tokens['tertiary'] }};
            --color-surface: {{ $tokens['surface'] }};
            --color-surface-alt: {{ $tokens['surface_alt'] }};
            --color-border: {{ $tokens['border'] }};
            --color-text: {{ $tokens['text'] }};
            --color-text-on-alt: {{ $tokens['text_on_alt'] }};
            --color-text-muted: {{ $tokens['text_muted'] }};
            --color-text-muted-on-alt: {{ $tokens['text_muted_on_alt'] }};
            --color-band: {{ $tokens['band'] }};
            --color-text-on-band: {{ $tokens['text_on_band'] }};
            --color-band-overlay: {{ $tokens['band_overlay'] }};
            --color-text-on-primary: {{ $tokens['text_on_primary'] }};
            --color-text-on-accent: {{ $tokens['text_on_accent'] }};
            --color-surface-contrast: {{ $tokens['surface_contrast'] }};
            --color-text-on-contrast: {{ $tokens['text_on_contrast'] }};
            --color-text-muted-on-contrast: {{ $tokens['text_muted_on_contrast'] }};
            --color-accent-text-on-contrast: {{ $tokens['accent_text_on_contrast'] }};

            --font-display: {!! $tokens['display_font_stack'] !!};
            --font-body: {!! $tokens['body_font_stack'] !!};

            --radius-card: {{ $tokens['radius_card'] }};
            --radius-button: {{ $tokens['radius_button'] }};
            --section-spacing: {{ $tokens['section_spacing'] }};
            --container-width: {{ $tokens['container_width'] }};
            --heading-letter-spacing: {{ $tokens['heading_letter_spacing'] }};
        }
        :root {
            --brand-primary: var(--color-primary);
            --brand-primary-text: var(--color-primary-text);
            --brand-primary-text-on-alt: var(--color-primary-text-on-alt);
            --brand-accent: var(--color-accent);
            --brand-accent-text: var(--color-accent-text);
            --brand-accent-text-on-alt: var(--color-accent-text-on-alt);
            --brand-accent-text-on-contrast: var(--color-accent-text-on-contrast);
        }
        [x-cloak] { display: none !important; }
        body {
            font-family: var(--font-body);
            background-color: var(--color-surface);
            color: var(--color-text);
        }
        h1, h2, h3, h4, h5, h6 {
            font-family: var(--font-display);
            letter-spacing: var(--heading-letter-spacing);
        }
        .site-shell-container {
            width: 100%;
            max-width: var(--container-width);
            margin-left: auto;
            margin-right: auto;
        }
{!! $shellInsetXlRule !!}        .site-section-spacing {
            padding-top: var(--section-spacing);
            padding-bottom: var(--section-spacing);
        }
        @keyframes shop-cart-drawer-in {
            from { transform: translateX(100%); }
            to { transform: translateX(0); }
        }
        #shop-cart-drawer {
            animation: shop-cart-drawer-in 200ms ease-out;
        }
        @media (prefers-reduced-motion: reduce) {
            #shop-cart-drawer { animation: none; }
        }
    </style>
    @include('shop.partials.product-card-styles')
</head>
<body class="antialiased" style="{{ config('site.layout_body_style') }}"{!! $shellInsetXl !== '' ? ' data-display-scale="grand"' : '' !!}>
    @include('site.partials.announcement-strip')@include('site.partials.nav', [
        'navItems'       => $navItems,
        'navRowHeroBelow' => $navHeroBelow,
        'site'           => $site,
        'homeHref'       => $homeHref,
        'logoUrl'        => $logoUrl,
        'profile'        => $profile,
        'topBarEnabled'  => $topBarEnabled,
        'shopCartEnabled' => $ctx['shopCartEnabled'],
        'shopCartItemCount' => $ctx['shopCartItemCount'],
        'shopSearchEnabled' => $ctx['shopSearchEnabled'],
        'shopAccountEnabled' => $ctx['shopAccountEnabled'],
    ])

    <main class="site-shell-container" style="flex: 1 0 auto;">
        @if ($errors->any())
            <div class="px-4 sm:px-6 pt-6 max-w-full">
                <div
                    role="alert"
                    class="w-full rounded p-4"
                    style="color: var(--color-text); border: 1px solid var(--color-accent); background-color: var(--color-surface-alt); border-radius: var(--radius-card);"
                >
                    <p class="font-semibold">We couldn’t complete that action.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
        {{ $slot }}
    </main>

    @include('site.partials.footer', [
        'composition' => $composition,
        'site'        => $site,
        'profile'     => $profile,
    ])

    @if ($ctx['shopCartEnabled'])
        @include('shop.partials.cart-drawer')
    @endif

    <script>
        function paintShopLucideIcons() {
            if (window.lucide) {
                window.lucide.createIcons({ attrs: { 'stroke-width': '{{ $tokens['icon_stroke_width'] }}' } });
            }
        }
        document.addEventListener('DOMContentLoaded', paintShopLucideIcons);
        document.addEventListener('alpine:initialized', paintShopLucideIcons);
    </script>
</body>
</html>

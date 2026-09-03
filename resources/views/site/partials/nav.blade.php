@php
    $effectiveOverlay = $effectiveOverlay ?? (\App\Support\ChromeKnobs::headerMode($site) === 'overlay' && ($headerOverlayCapable ?? false));
    $rightAction = \App\Support\ChromeKnobs::rightAction($site);
    $storeControlsSlot = \App\Support\ChromeKnobs::storeControlsSlot($site);
    $storeControlsInline = $storeControlsSlot !== 'right';
    $navCtaTarget = \App\Support\ChromeKnobs::navCtaTarget($site);
    $ctaLabel = is_string($site->nav_cta_label ?? null) ? $site->nav_cta_label : null;
    if ($navCtaTarget === 'form') {
        $ctaUrl = ($pageHasForm ?? false) ? '#enquire' : '/contact';
        if ($ctaLabel === null || $ctaLabel === '') {
            $ctaLabel = 'Get a free quote';
        }
    } else {
        $ctaUrl = \App\Support\NavCta::safeUrl($site->nav_cta_url ?? null);
    }
    $hasCta = in_array($rightAction, ['cta', 'phone_cta'], true) && $ctaLabel !== null && $ctaLabel !== '' && $ctaUrl !== null && $ctaUrl !== '';
    $ctaEnquireClick = $ctaUrl === '#enquire'
        ? ' x-data @click.prevent="document.getElementById(\'enquire\')?.scrollIntoView({ behavior: window.matchMedia(\'(prefers-reduced-motion: reduce)\').matches ? \'auto\' : \'smooth\' })"'
        : '';
    $ctaEnquireClickMobile = $ctaUrl === '#enquire'
        ? ' x-data @click.prevent="document.getElementById(\'enquire\')?.scrollIntoView({ behavior: window.matchMedia(\'(prefers-reduced-motion: reduce)\').matches ? \'auto\' : \'smooth\' }); mobileNav = false"'
        : '';
    $displayTokens = is_array($renderTokens ?? null) ? $renderTokens : (is_array($tokens ?? null) ? $tokens : []);
    $navPaddingClass = $displayTokens['nav_padding_class'] ?? 'px-4 sm:px-6 lg:px-8';
    $storeControlIconClass = $displayTokens['store_control_icon_class'] ?? 'h-4 w-4';
    $storeControlTextClass = $displayTokens['store_control_text_class'] ?? 'text-sm';
    $chromeBrandRowClass = $displayTokens['chrome_brand_row_class'] ?? 'h-[104px] lg:h-[120px]';
@endphp@if (\App\Support\ChromeKnobs::layout($site) === 'centred')
@include('site.partials.nav-centred')
@else
{{-- Top utility bar --}}
@if (! $effectiveOverlay && ($topBarEnabled ?? true))
<div class="text-sm" style="background-color: var(--brand-primary); color: var(--color-text-on-primary, #ffffff);">
    <div class="site-shell-container {{ $navPaddingClass }}">
        <div class="flex justify-between items-center h-10">
            <div class="flex items-center gap-4">
                @php
                    // Some profiles produce verbose service_area strings with
                    // a parenthetical suffix like
                    // "North West England (covers Wigan and 18 nearby towns…)".
                    // Strip the parenthetical (it duplicates info that's
                    // surfaced on the suburb-list section) but DO NOT
                    // char-truncate — that previously clipped legitimate
                    // common UK phrasings mid-word, e.g.
                    // "Town and surrounding areas, County" (46
                    // chars) being cut at 40 to "…County…". Let CSS handle
                    // truncation against the actual available width via
                    // `truncate max-w-…`, and surface the full string in
                    // the title attribute for screen-reader/tooltip access.
                    $area = $profile['geo']['service_area'] ?? null;
                    if ($area) {
                        $area = trim(preg_replace('/\s*\(.*$/', '', $area));
                    }
                @endphp
                @if ($area)
                    <span class="hidden sm:flex items-center gap-1.5 opacity-90 min-w-0"
                          title="Serving {{ $area }}">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <span class="truncate max-w-[28ch] md:max-w-[42ch] lg:max-w-none">Serving {{ $area }}</span>
                    </span>
                @endif
                @if (!empty($profile['credibility']['trade_bodies']))
                    <span class="hidden md:flex items-center gap-1.5 opacity-90">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        {{ $profile['credibility']['trade_bodies'][0] }} Registered
                    </span>
                @endif
            </div>
            @if ($rightAction !== 'none' && ($phone = ($profile['contact']['phones'][0] ?? null)))
                <a href="tel:{{ $phone }}" class="flex items-center gap-1.5 font-semibold hover:opacity-80">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    {{ $phone }}
                </a>
            @endif
        </div>
    </div>
</div>
@endif

@php
    // Wordmark-style logos (typical for SaaS / product marketing sites) are
    // much wider than the icon-style logos the platform's default sizing was
    // tuned for. The default h-28 (112px) treatment pushes them off-screen.
    // Detect the saas_platform archetype and switch to a smaller cap —
    // unless the agent has set an explicit per-site logo_size override.
    // Standard keeps today's heuristic byte-identical; Compact forces
    // the small matrix; Large uses default × ~1.25 (scrolled pairs stay
    // proportional).
    $logoSizeKey = \App\Services\Site\HeaderPresentation::logoSizeKey($site);
    $logoCompact = $logoSizeKey === 'compact';
    $logoLarge = $logoSizeKey === 'large';
    $overlayLogoSizeKey = \App\Services\Site\HeaderPresentation::overlayLogoSizeKey($site);
    $overlayLogoCompact = $overlayLogoSizeKey === 'compact';
    $overlayLogoLarge = $overlayLogoSizeKey === 'large';
    // One size track for both marks when a floating logo exists: the floating
    // size while floating, the main logo's scrolled size once solid — so the
    // crossfade is always between identically sized marks (no ghosting).
    $logoSizeClasses = fn (bool $large, bool $compact): array => [
        'scrolled' => $large ? 'h-[4.9375rem] max-w-[281px] md:h-[7.875rem] md:max-w-[513px]' : ($compact ? 'h-10 max-w-[170px] md:h-12 md:max-w-[240px]' : 'h-[3.95rem] max-w-[225px] md:h-[6.3rem] md:max-w-[410px]'),
        'unscrolled' => $large ? 'h-[5.46875rem] max-w-[313px] md:h-[8.75rem] md:max-w-[569px]' : ($compact ? 'h-12 max-w-[200px] md:h-14 md:max-w-[280px]' : 'h-[4.375rem] max-w-[250px] md:h-28 md:max-w-[455px]'),
    ];
    $mainLogoSizes = $logoSizeClasses($logoLarge, $logoCompact);
    $floatingLogoSizes = $logoSizeClasses($overlayLogoLarge, $overlayLogoCompact);
    $overlayGlass = $effectiveOverlay ? \App\Support\ChromeKnobs::overlayGlass($site) : 'off';
    // Glass 'always' never shows the photo behind the bar, so the floating
    // state uses the selected (non-inverted) logo — no white copy, no crossfade.
    if ($overlayGlass === 'always') {
        $overlayLogoUrl = null;
    }
    $hasFloatingLogo = $effectiveOverlay && ! empty($overlayLogoUrl);
    // The floating STATE is sized by overlay_logo_size whenever it is set — with or
    // without a white floating mark — so the bar can grow into the solid state.
    $floatingSized = $effectiveOverlay && $site->overlay_logo_size !== null && (($isHomePage ?? true) || \App\Support\ChromeKnobs::overlayInnerScale($site) === 'overlay');
    $headerShrink = \App\Support\ChromeKnobs::headerShrink($site);
    $mainLogoScrolledClass = $headerShrink === 'off' ? $mainLogoSizes['unscrolled'] : $mainLogoSizes['scrolled'];
    $mainLogoUnscrolledClass = $floatingSized ? $floatingLogoSizes['unscrolled'] : $mainLogoSizes['unscrolled'];
    $floatingLogoScrolledClass = $headerShrink === 'off' ? $mainLogoSizes['unscrolled'] : $mainLogoSizes['scrolled'];
    $headerPaddingPx = \App\Services\Site\HeaderPresentation::headerPaddingPx($site, $displayTokens);
    $headerPaddingAttr = $headerPaddingPx > 0 ? " style=\"padding-top: {$headerPaddingPx}px; padding-bottom: {$headerPaddingPx}px;\"" : '';
    $floatingLogoUnscrolledClass = $floatingSized ? $floatingLogoSizes['unscrolled'] : $mainLogoSizes['unscrolled'];

    // Per-site header background (sites.header_bg). Null/invalid = the
    // white default. Link/burger/wordmark colours follow the bar's
    // luminance so a dark header (e.g. a light logo variant) stays
    // legible; dropdown + mobile panels take the same colour below.
    $headerBg = is_string($site->header_bg ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $site->header_bg)
        ? strtolower($site->header_bg)
        : '#ffffff';
    [$hbR, $hbG, $hbB] = sscanf($headerBg, '#%02x%02x%02x');
    $headerDark = (0.2126 * $hbR + 0.7152 * $hbG + 0.0722 * $hbB) / 255 < 0.5;
    $navRowBg = \App\Support\ChromeKnobs::navRowBg($site);
    $navRowPaint = $navRowBg ?? $headerBg;
    $navRowDark = \App\Support\ChromeKnobs::navRowIsDark($site);
    $navRowPattern = \App\Support\ChromeKnobs::navRowPattern($site);
    $navRowPatternAttr = $navRowPattern !== 'none' ? ' data-nav-row-pattern="'.$navRowPattern.'"' : '';
    $navRowStripStyle = $navRowBg !== null ? ' style="background-color: '.$navRowBg.';"' : '';
    $navRowAccentMode = \App\Support\ChromeKnobs::navRowAccentBorder($site);
    $navRowAccentBorder = $navRowAccentMode === 'on'
        || ($navRowAccentMode === 'no_hero' && ! ($navRowHeroBelow ?? false));
    // Hero below in no_hero mode: rule returns once the header is scrolled (hero off screen).
    $navRowAccentWhenStuck = $navRowAccentMode === 'no_hero' && ($navRowHeroBelow ?? false);
    $navLinkClass = $navRowDark
        ? 'text-white/80 hover:text-white'
        : 'text-gray-600 hover:text-gray-900';
    $headerBorderStyle = $headerDark ? 'border-color: rgba(255,255,255,0.15);' : '';
    $overlayGlassHairline = $headerDark ? 'rgba(255,255,255,.10)' : 'rgba(0,0,0,.08)';
    // Solid-header pages (no hero → no overlay arm) get the mode's scrolled-state glass
    // so a site on `scrolled`/`always` reads the same on every page. `floating` = glass
    // only while floating, so solid pages stay solid. Null knob ⇒ nothing emitted (D0).
    $solidGlass = (! $effectiveOverlay && in_array(\App\Support\ChromeKnobs::overlayGlass($site), ['scrolled', 'always'], true))
        ? \App\Support\ChromeKnobs::overlayGlass($site) : null;
    $solidGlassTint = $solidGlass === 'always' ? 85 : 78;
    $solidGlassStyle = $solidGlass === null ? '' :
        '<style>header[data-solid-glass]{background-color:color-mix(in srgb, '.$headerBg.' '.$solidGlassTint.'%, transparent)!important;border-color:'.$overlayGlassHairline.'!important;-webkit-backdrop-filter:blur(12px) saturate(1.4)!important;backdrop-filter:blur(12px) saturate(1.4)!important}'
        .'@supports not (backdrop-filter: blur(1px)){header[data-solid-glass]{background-color:color-mix(in srgb, '.$headerBg.' 92%, transparent)!important;-webkit-backdrop-filter:none!important;backdrop-filter:none!important}}'
        .'@media (prefers-reduced-transparency: reduce){header[data-solid-glass]{background-color:color-mix(in srgb, '.$headerBg.' 92%, transparent)!important;-webkit-backdrop-filter:none!important;backdrop-filter:none!important}}</style>';
    $solidGlassAttr = $solidGlass === null ? '' : ' data-solid-glass="'.$solidGlass.'"';
    $overlayHeaderTransition = $overlayGlass !== 'off'
        ? 'transition-[background-color,border-color,box-shadow,backdrop-filter] duration-700'
        : 'transition-[background-color,border-color,box-shadow] duration-700';

    // Dropdown + mobile panels follow the header colour so an open menu
    // reads as part of the bar, with the same luminance-adapted links.
    $navPanelStyle = "background-color: {$navRowPaint};".($navRowDark ? ' border-color: rgba(255,255,255,0.15);' : '');
    $navPanelLinkClass = $navRowDark
        ? 'text-white/80 hover:text-white hover:bg-white/10'
        : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50';
    $navMobileLinkClass = $navRowDark
        ? 'text-white/80 hover:text-white hover:bg-white/10'
        : 'text-gray-700 hover:bg-gray-50';
    $navGroupLabelStyle = $navRowDark ? 'color: rgba(255,255,255,0.55);' : '';

    // Vertical breathing room around tight-crop logos (sites.logo_margin,
    // px). Padding inside the img's fixed height box (object-contain)
    // shrinks the rendered mark without touching header heights. Clamp 12:
    // the smallest logo box (compact, scrolled mobile) is h-10 = 40px and
    // padding is border-box — 2x12px still leaves a 16px visible mark;
    // anything higher can erase the logo entirely.
    $logoMarginY = max(0, min(12, (int) ($site->logo_margin ?? 0)));
    $logoMarginStyle = $logoMarginY > 0 ? "padding-top: {$logoMarginY}px; padding-bottom: {$logoMarginY}px;" : '';
    $overlayLogoMarginY = $site->overlay_logo_margin === null ? $logoMarginY : max(0, min(12, (int) $site->overlay_logo_margin));
    $overlayLogoMarginStyle = $overlayLogoMarginY > 0 ? "padding-top: {$overlayLogoMarginY}px; padding-bottom: {$overlayLogoMarginY}px;" : '';
    $navCaseClass = match (\App\Support\ChromeKnobs::navCase($site)) { 'upper' => ' uppercase tracking-[0.1em] !text-[0.8125rem]', 'lower' => ' lowercase', default => '' };
    $headerHeights = \App\Services\Site\HeaderPresentation::headerHeightClasses($site, $floatingSized);
    // Overlay-logo crossfade hook; the else value is byte-identical to the historic class attribute (D0).
    $mainLogoClass = ($effectiveOverlay && ! empty($overlayLogoUrl)) ? 'w-auto object-contain js-main-logo' : 'w-auto object-contain';
    $navContainerStyle = \App\Support\ChromeKnobs::navContainerRenderStyle($site);
    $navContainerFill = \App\Support\ChromeKnobs::navContainerFill($site);
    $navContainerClass = \App\Support\ChromeKnobs::navContainerClass($site);
    $navContainerCss = \App\Support\ChromeKnobs::navContainerCss($site);
@endphp
{{-- Main header. Transitions are gated behind a 'ready' flag that flips
     true on $nextTick so the initial class application doesn't animate
     (without this, on first paint Alpine applies the height classes
     via :class AFTER the static class list is rendered, and
     transition-all runs the height change like a 200ms animation from
     an implicit 0 to the final size — reads as a logo flicker). --}}
@php
    $shopSearchPanel = ($shopSearchEnabled ?? false) ? \App\Support\Shop\ShopSearchPanel::for($site) : null;
    $shopSearchXData = ($shopSearchEnabled ?? false) ? ', ...shopSearch()' : '';
    // No Blade directives inside a tag: the Alpine scope walker (AlpineHandlerScopeTest)
    // treats @if/@else inside an element as branch tokens and loses the element stack.
    $shopSearchToggleAttr = $effectiveOverlay
        ? ' :class="(! scrolled) ? \'text-white/85 hover:text-white\' : \''.e($navLinkClass).'\'"'
        : ' style="color: var(--brand-primary-text);"';
    $shopSearchAttrs = $shopSearchPanel
        ? ' data-shop-search-q="'.e($shopSearchPanel['query']).'" data-shop-search-url="'.e($shopSearchPanel['searchUrl']).'"'
        : '';
@endphp
@if ($effectiveOverlay)
<style>@if ($overlayGlass === 'off' || $overlayGlass === 'scrolled')header[data-header-mode="overlay"]:not([data-logo-state]){background-color:transparent!important;border-color:transparent!important;box-shadow:none!important}header[data-header-mode="overlay"]:not([data-logo-state]) .js-scrim{display:block!important}header[data-header-mode="overlay"]:not([data-logo-state]) .js-ovl:not([data-nav-container] *){color:rgba(255,255,255,.85)!important}@if ($overlayGlass === 'scrolled')header[data-header-mode="overlay"][data-overlay-glass="scrolled"][data-logo-state="main"],header[data-header-mode="overlay"][data-overlay-glass="scrolled"][data-scrolled="true"]{background-color:color-mix(in srgb, {{ $headerBg }} 78%, transparent)!important;border-color:{{ $overlayGlassHairline }}!important;-webkit-backdrop-filter:blur(12px) saturate(1.4)!important;backdrop-filter:blur(12px) saturate(1.4)!important}@supports not (backdrop-filter: blur(1px)){header[data-header-mode="overlay"][data-overlay-glass="scrolled"][data-scrolled="true"]{background-color:color-mix(in srgb, {{ $headerBg }} 92%, transparent)!important;-webkit-backdrop-filter:none!important;backdrop-filter:none!important}}@media (prefers-reduced-transparency: reduce){header[data-header-mode="overlay"][data-overlay-glass="scrolled"][data-scrolled="true"]{background-color:color-mix(in srgb, {{ $headerBg }} 92%, transparent)!important;-webkit-backdrop-filter:none!important;backdrop-filter:none!important}}@endif @else header[data-header-mode="overlay"][data-overlay-glass]:not([data-logo-state]),header[data-header-mode="overlay"][data-overlay-glass="floating"]:not([data-logo-state="main"]):not([data-scrolled="true"]),header[data-header-mode="overlay"][data-overlay-glass="always"]:not([data-logo-state="main"]):not([data-scrolled="true"]){background-color:color-mix(in srgb, {{ $headerBg }} 45%, transparent)!important;border-color:{{ $overlayGlassHairline }}!important;box-shadow:none!important;-webkit-backdrop-filter:blur(12px) saturate(1.4)!important;backdrop-filter:blur(12px) saturate(1.4)!important}header[data-header-mode="overlay"][data-overlay-glass]:not([data-logo-state]) .js-ovl:not([data-nav-container] *){color:rgba(255,255,255,.85)!important}header[data-header-mode="overlay"][data-overlay-glass="floating"][data-logo-state="main"],header[data-header-mode="overlay"][data-overlay-glass="floating"][data-scrolled="true"]{background-color:{{ $headerBg }}!important;{{ $headerBorderStyle }}-webkit-backdrop-filter:none!important;backdrop-filter:none!important}header[data-header-mode="overlay"][data-overlay-glass="always"][data-logo-state="main"],header[data-header-mode="overlay"][data-overlay-glass="always"][data-scrolled="true"]{background-color:color-mix(in srgb, {{ $headerBg }} 85%, transparent)!important;border-color:{{ $overlayGlassHairline }}!important;-webkit-backdrop-filter:blur(12px) saturate(1.4)!important;backdrop-filter:blur(12px) saturate(1.4)!important}@supports not (backdrop-filter: blur(1px)){header[data-header-mode="overlay"][data-overlay-glass="floating"]:not([data-logo-state="main"]):not([data-scrolled="true"]),header[data-header-mode="overlay"][data-overlay-glass="always"]{background-color:color-mix(in srgb, {{ $headerBg }} 75%, transparent)!important;-webkit-backdrop-filter:none!important;backdrop-filter:none!important}}@media (prefers-reduced-transparency: reduce){header[data-header-mode="overlay"][data-overlay-glass="floating"]:not([data-logo-state="main"]):not([data-scrolled="true"]),header[data-header-mode="overlay"][data-overlay-glass="always"]{background-color:color-mix(in srgb, {{ $headerBg }} 75%, transparent)!important;-webkit-backdrop-filter:none!important;backdrop-filter:none!important}}@endif</style><noscript><style>@if ($overlayGlass === 'off' || $overlayGlass === 'scrolled')header[data-header-mode="overlay"]{background-color:{{ $headerBg }}!important;{{ $headerBorderStyle }}}header[data-header-mode="overlay"] .js-scrim{display:none!important}header[data-header-mode="overlay"] .js-ovl:not([data-nav-container] *){color:{{ $headerDark ? 'rgba(255,255,255,.8)' : '#4b5563' }}!important}@else header[data-header-mode="overlay"]{background-color:color-mix(in srgb, {{ $headerBg }} 45%, transparent)!important;border-color:{{ $overlayGlassHairline }}!important;box-shadow:none!important;-webkit-backdrop-filter:blur(12px) saturate(1.4)!important;backdrop-filter:blur(12px) saturate(1.4)!important}header[data-header-mode="overlay"] .js-ovl:not([data-nav-container] *){color:{{ $headerDark ? 'rgba(255,255,255,.8)' : '#4b5563' }}!important}@supports not (backdrop-filter: blur(1px)){header[data-header-mode="overlay"]{background-color:color-mix(in srgb, {{ $headerBg }} 75%, transparent)!important;-webkit-backdrop-filter:none!important;backdrop-filter:none!important}}@media (prefers-reduced-transparency: reduce){header[data-header-mode="overlay"]{background-color:color-mix(in srgb, {{ $headerBg }} 75%, transparent)!important;-webkit-backdrop-filter:none!important;backdrop-filter:none!important}}@endif</style></noscript><header x-data="{ scrolled: false, ready: false, settled: false, pending: false, _t: null{!! $shopSearchXData !!} }"{!! $shopSearchAttrs !!}
        x-init="scrolled = window.scrollY > 10; $nextTick(() => { ready = true; requestAnimationFrame(() => requestAnimationFrame(() => settled = true)) })"
        @scroll.window="window.scrollY <= 10 ? (clearTimeout(_t), pending = false, scrolled = false) : ((scrolled || pending) ? null : (pending = true, _t = setTimeout(() => { scrolled = window.scrollY > 10; pending = false }, 180)))"
        data-header-mode="overlay"{!! $overlayGlass !== 'off' ? ' data-overlay-glass="'.e($overlayGlass).'"' : '' !!}
        :data-scrolled="scrolled ? 'true' : 'false'"
        :data-logo-state="scrolled ? 'main' : 'overlay'"
        :data-settled="(ready && settled) ? 'true' : 'false'"
        :class="[(! scrolled) ? 'shadow-none' : 'shadow-md', (ready && settled) ? '{{ $overlayHeaderTransition }}' : '']"
        :style="(! scrolled) ? 'background-color: transparent; border-color: transparent;' : 'background-color: {{ $headerBg }}; {{ $headerBorderStyle }}'"
        class="border-b border-gray-200 fixed top-0 inset-x-0 z-50"
        style="background-color: {{ $headerBg }}; {{ $headerBorderStyle }}">
@else
{!! $solidGlassStyle !!}<header x-data="{ scrolled: false, ready: false{!! $shopSearchXData !!} }"{!! $shopSearchAttrs !!}{!! $solidGlassAttr !!}
        x-init="scrolled = window.scrollY > 10; $nextTick(() => ready = true)"
        @scroll.window="scrolled = window.scrollY > 10"
        :class="[scrolled ? 'shadow-md' : 'shadow-sm', ready ? 'transition-shadow duration-200' : '']"
        class="border-b border-gray-200 sticky top-0 z-50"
        style="background-color: {{ $headerBg }}; {{ $headerBorderStyle }}">
@endif@if ($effectiveOverlay && ($overlayGlass === 'off' || $overlayGlass === 'scrolled'))<div x-show="!scrolled" x-cloak class="js-scrim pointer-events-none absolute inset-x-0 top-0 h-40 bg-gradient-to-b from-black/55 to-transparent -z-10"></div>@endif
    <nav class="site-shell-container {{ $navPaddingClass }}"{!! $headerPaddingAttr !!}>
        {{-- Header heights bumped 25% (default) / sticky shrinks 10%
             from new default. Ask: bigger logo
             but only modest sticky shrink so it stays prominent.
             Large = default matrix × 1.25 (same scrolled/unscrolled ratio). --}}
        {{-- Sizes are rendered statically (unscrolled) so nothing flashes at natural size before
             Alpine initialises on a hard refresh; the object-form :class swaps them when scrolled. --}}
        <div :class="{ '{{ $headerHeights['unscrolled'] }}': !scrolled, '{{ $headerHeights['scrolled'] }}': scrolled, 'transition-all duration-200': ready }"
             class="flex justify-between items-center {{ $headerHeights['unscrolled'] }}">
            {{-- Logo / wordmark. The <a> reserves horizontal space
                 (min-width) so the layout doesn't shift while the logo
                 image downloads — browser has no natural width for an
                 <img w-auto> until src loads. fetchpriority=high +
                 eager loading put the logo in the first network batch
                 so the reserved space gets filled ASAP. --}}
            @if (!empty($logoUrl))
                {{-- Mobile width caps are tighter than md+ to stop a wide
                     logo from pushing the phone CTA off-screen on small
                     viewports. The sticky-shrunk size also drops a
                     notch on mobile so the post-scroll layout stays
                     comfortably under the available width. --}}
                <a href="{{ $homeHref ?? '/' }}" class="flex items-center gap-3 {{ $logoLarge ? 'min-w-[175px] md:min-w-[275px]' : ($logoCompact ? 'min-w-[100px] md:min-w-[150px]' : 'min-w-[140px] md:min-w-[220px]') }}"@if ($effectiveOverlay && ! $headerDark && ($logoPlate ?? true) && empty($overlayLogoUrl)) :class="(! scrolled) ? 'bg-white/85 rounded px-2' : ''"@endif>@if ($effectiveOverlay && ! empty($overlayLogoUrl))<style>.js-overlay-logo{opacity:1}.js-main-logo{opacity:0}header .js-overlay-logo,header .js-main-logo{transition:none}header[data-logo-state="main"] .js-overlay-logo{opacity:0}header[data-logo-state="main"] .js-main-logo{opacity:1}header[data-settled="true"] .js-overlay-logo,header[data-settled="true"] .js-main-logo{transition:opacity 700ms cubic-bezier(.4,0,.2,1),height 700ms cubic-bezier(.4,0,.2,1),max-width 700ms cubic-bezier(.4,0,.2,1)}header[data-settled="true"]>nav>div.transition-all{transition-duration:700ms}</style><noscript><style>.js-overlay-logo{opacity:0}.js-main-logo{opacity:1}</style></noscript><span class="relative inline-flex">
                    <img src="{{ $overlayLogoUrl }}"
                         alt="{{ $site->business_name }}"
                         loading="eager"
                         fetchpriority="high"
                         decoding="async"
                         aria-hidden="true"
                         {{-- Default = current values × 1.25 (h-14→h-[4.375rem],
                              h-[5.6rem]→h-28). Sticky = new default × 0.9 so the
                              shrink delta is gentle. Large = default × 1.25. --}}
                         :class="{ '{{ $floatingLogoUnscrolledClass }}': !scrolled, '{{ $floatingLogoScrolledClass }}': scrolled, 'transition-all duration-200': ready }"
                         class="w-auto object-contain absolute left-0 top-1/2 -translate-y-1/2 js-overlay-logo {{ $floatingLogoUnscrolledClass }}"
                         @if ($overlayLogoMarginStyle) style="{{ $overlayLogoMarginStyle }}" @endif>@endif

                    <img src="{{ $logoUrl }}"
                         alt="{{ $site->business_name }}"
                         loading="eager"
                         fetchpriority="high"
                         decoding="async"
                         {{-- Default = current values × 1.25 (h-14→h-[4.375rem],
                              h-[5.6rem]→h-28). Sticky = new default × 0.9 so the
                              shrink delta is gentle. Large = default × 1.25. --}}
                         :class="{ '{{ $mainLogoUnscrolledClass }}': !scrolled, '{{ $mainLogoScrolledClass }}': scrolled, 'transition-all duration-200': ready }"
                         class="{{ $mainLogoClass }} {{ $mainLogoUnscrolledClass }}"
                         @if ($logoMarginStyle) style="{{ $logoMarginStyle }}" @endif>
                @if ($effectiveOverlay && ! empty($overlayLogoUrl))</span>@endif</a>
            @else
                <a href="{{ $homeHref ?? '/' }}">
                    <span class="text-2xl font-extrabold tracking-tight{{ $effectiveOverlay ? ' js-ovl' : '' }}" style="color: {{ $headerDark ? '#ffffff' : 'var(--brand-primary-text)' }};"@if ($effectiveOverlay) :style="(! scrolled) ? 'color: #ffffff' : 'color: {{ $headerDark ? '#ffffff' : 'var(--brand-primary-text)' }}'"@endif>
                        {{ $site->business_name }}
                    </span>
                </a>
            @endif

            {{-- Desktop nav --}}
            <div class="hidden md:flex items-center space-x-8{{ $navRowPattern !== 'none' ? ' relative isolate' : '' }}"{!! $navRowPatternAttr !!}{!! $navRowStripStyle !!}>
                @if ($navRowPattern !== 'none')
                    @include('site.partials.nav-row-pattern')
                @endif
                @if ($navContainerStyle !== 'none')<style>[data-nav-container][data-nav-container-style]>a:not(:hover),[data-nav-container][data-nav-container-style]>button:not(:hover),[data-nav-container][data-nav-container-style]>[data-shop-nav-style]>a:not(:hover),[data-nav-container][data-nav-container-style]>div>button:not(:hover){color:var(--nav-container-ink)}@if ($navContainerFill === 'glass')[data-nav-container][data-nav-container-fill="glass"]::before{content:"";position:absolute;inset:0;z-index:-1;border-radius:inherit;background-color:var(--nav-container-bg);-webkit-backdrop-filter:blur(12px) saturate(1.4);backdrop-filter:blur(12px) saturate(1.4);pointer-events:none}@supports not (backdrop-filter:blur(1px)){[data-nav-container][data-nav-container-fill="glass"]::before{background-color:var(--color-surface);-webkit-backdrop-filter:none;backdrop-filter:none}}@media (prefers-reduced-transparency:reduce){[data-nav-container][data-nav-container-fill="glass"]::before{background-color:var(--color-surface);-webkit-backdrop-filter:none;backdrop-filter:none}}@elseif ($navContainerFill === 'pattern')@media (prefers-reduced-transparency:reduce){[data-nav-container][data-nav-container-fill="pattern"]{background-color:var(--color-surface)!important;background-image:none!important}}@endif</style><div data-nav-container data-nav-container-style="{{ $navContainerStyle }}" data-nav-container-fill="{{ $navContainerFill }}" class="flex items-center gap-8 {{ $headerShrink === 'on' ? '' : 'px-5 py-2 ' }}{{ $navContainerClass }} transition-[padding,background-color,box-shadow,backdrop-filter] duration-200"@if ($headerShrink === 'on') :class="scrolled ? 'px-3 py-1.5' : 'px-5 py-2'"@endif style="{{ $navContainerCss }}">@endif@foreach ($navItems ?? [] as $item)
                    @if (in_array($item['shop_nav_style'] ?? null, ['dropdown', 'mega'], true))
                        @include('site.partials.nav-shop-desktop')
                    @elseif (($item['type'] ?? '') === 'group')
                        {{-- Services dropdown --}}
                        <div x-data="{ open: false }" class="relative"
                             @mouseenter="if (window.innerWidth >= 768) open = true"
                             @mouseleave="if (window.innerWidth >= 768) open = false">
                            <button @click="open = !open" @click.away="open = false"
                                    class="text-sm font-medium transition-colors{{ $effectiveOverlay ? ' js-ovl' : ' '.$navLinkClass }}{{ $navCaseClass }} flex items-center gap-1 cursor-pointer"@if ($effectiveOverlay) :class="(! scrolled) ? 'text-white/85 hover:text-white' : '{{ $navLinkClass }}'"@endif>
                                {{ $item['label'] }}
                                <svg class="w-3.5 h-3.5 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="open" x-cloak
                                 x-transition:enter="transition ease-out duration-100"
                                 x-transition:enter-start="opacity-0 scale-95"
                                 x-transition:enter-end="opacity-100 scale-100"
                                 x-transition:leave="transition ease-in duration-75"
                                 x-transition:leave-start="opacity-100 scale-100"
                                 x-transition:leave-end="opacity-0 scale-95"
                                 class="absolute top-full left-0 mt-2 w-64 rounded-lg shadow-lg border border-gray-200 py-2 z-50"
                                 style="{{ $navPanelStyle }}">
                                @foreach ($item['children'] ?? [] as $child)
                                    <a href="{{ $child['href'] }}"
                                       class="block px-4 py-2 text-sm transition-colors {{ $navPanelLinkClass }}{{ $navCaseClass }}">
                                        {{ $child['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <a href="{{ $item['href'] }}"
                           class="text-sm font-medium transition-colors{{ $effectiveOverlay ? ' js-ovl' : ' '.$navLinkClass }}{{ $navCaseClass }}"@if ($effectiveOverlay) :class="(! scrolled) ? 'text-white/85 hover:text-white' : '{{ $navLinkClass }}'"@endif>
                            {{ $item['label'] }}
                        </a>
                    @endif
                @endforeach@if ($navContainerStyle !== 'none')</div>@endif@if (($shopSearchEnabled ?? false) && $storeControlsInline)<span x-data="{}" class="contents"><button type="button" data-shop-search-toggle aria-label="Search the shop" aria-expanded="false" aria-controls="shop-search-panel" @click.stop="toggle($event.currentTarget)" class="inline-flex items-center p-1 {{ $storeControlTextClass }} font-medium transition-opacity hover:opacity-80{{ $effectiveOverlay ? ' js-ovl' : '' }}"{!! $shopSearchToggleAttr !!}><i data-lucide="search" class="{{ $storeControlIconClass }}" aria-hidden="true"></i></button></span>@endif@if (($shopCartEnabled ?? false) && $storeControlsInline)<a href="/shop/cart" data-shop-cart-control class="inline-flex items-center gap-2 {{ $storeControlTextClass }} font-medium transition-opacity hover:opacity-80" style="color: var(--brand-primary-text);" aria-label="Cart, {{ $shopCartItemCount ?? 0 }} {{ ($shopCartItemCount ?? 0) === 1 ? 'item' : 'items' }}"><svg class="{{ $storeControlIconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13 5.4 5M7 13l-2.3 2.3c-.6.6-.2 1.7.7 1.7H17m0 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4ZM9 19a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/></svg><span>Cart</span><span data-shop-cart-count class="inline-flex min-h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-bold" style="background-color: var(--brand-accent); color: var(--color-text-on-accent);">{{ $shopCartItemCount ?? 0 }}</span></a>@endif
            </div>
@if ($storeControlsSlot === 'right' && (($shopSearchEnabled ?? false) || ($shopCartEnabled ?? false)))
<div class="hidden md:flex items-center gap-6 ml-10" data-store-controls-slot="right">@if ($shopSearchEnabled ?? false)<span x-data="{}" class="contents"><button type="button" data-shop-search-toggle aria-label="Search the shop" aria-expanded="false" aria-controls="shop-search-panel" @click.stop="toggle($event.currentTarget)" class="inline-flex items-center p-1 {{ $storeControlTextClass }} font-medium transition-opacity hover:opacity-80{{ $effectiveOverlay ? ' js-ovl' : '' }}"{!! $shopSearchToggleAttr !!}><i data-lucide="search" class="{{ $storeControlIconClass }}" aria-hidden="true"></i></button></span>@endif@if ($shopCartEnabled ?? false)<a href="/shop/cart" data-shop-cart-control class="inline-flex items-center gap-2 {{ $storeControlTextClass }} font-medium transition-opacity hover:opacity-80" style="color: var(--brand-primary-text);" aria-label="Cart, {{ $shopCartItemCount ?? 0 }} {{ ($shopCartItemCount ?? 0) === 1 ? 'item' : 'items' }}"><svg class="{{ $storeControlIconClass }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13 5.4 5M7 13l-2.3 2.3c-.6.6-.2 1.7.7 1.7H17m0 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4ZM9 19a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/></svg><span>Cart</span><span data-shop-cart-count class="inline-flex min-h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-bold" style="background-color: var(--brand-accent); color: var(--color-text-on-accent);">{{ $shopCartItemCount ?? 0 }}</span></a>@endif</div>
@endif

            {{-- Desktop phone CTA --}}
            @if ($rightAction === 'phone_cta')
                @php $headerPhone = $profile['contact']['phones'][0] ?? null; @endphp
                @if ($headerPhone || $hasCta)
                <div class="hidden lg:flex items-center gap-10">
                    @if ($headerPhone)
                    <a href="tel:{{ $headerPhone }}"
                       class="font-medium text-sm{{ $effectiveOverlay ? ' js-ovl' : ' '.$navLinkClass }}{{ $navCaseClass }}"@if ($effectiveOverlay) :class="(! scrolled) ? 'text-white/70 hover:text-white' : '{{ $navLinkClass }}'"@endif>{{ $headerPhone }}</a>
                    @endif
                    @if ($hasCta)
                    <a href="{{ $ctaUrl }}"{!! $ctaEnquireClick !!}
                       class="inline-flex items-center gap-2 px-5 py-2.5 rounded-md font-bold text-sm shadow-md transition-all hover:shadow-lg hover:brightness-110{{ $navCaseClass }}"
                       style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff);">{{ $ctaLabel }}</a>
                    @endif
                </div>
                @endif
            @elseif ($rightAction === 'none')
            @elseif ($hasCta)
                <a href="{{ $ctaUrl }}"{!! $ctaEnquireClick !!}
                   class="hidden lg:inline-flex items-center gap-2 px-5 py-2.5 rounded-md font-bold text-sm shadow-md transition-all hover:shadow-lg hover:brightness-110"
                   style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff);">{{ $ctaLabel }}</a>
            @elseif ($phone = ($profile['contact']['phones'][0] ?? null))
                <a href="tel:{{ $phone }}"
                   class="hidden lg:flex items-center gap-2 px-5 py-2.5 rounded-md font-bold text-sm shadow-md transition-all hover:shadow-lg hover:brightness-110"
                   style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff);">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    {{ $phone }}
                </a>
            @endif@if ($rightAction === 'phone_cta')
                @php $mdPhone = $profile['contact']['phones'][0] ?? null; @endphp
                @if ($mdPhone || $hasCta)
                <div class="hidden md:flex lg:hidden items-center gap-3">
                    @if ($mdPhone)
                    <a href="tel:{{ preg_replace('/\s+/', '', $mdPhone) }}" class="flex items-center p-2{{ $effectiveOverlay ? ' js-ovl' : '' }}"@if ($effectiveOverlay) :class="(! scrolled) ? 'text-white/85' : '{{ $navLinkClass }}'"@else style="color: var(--brand-accent-text);"@endif aria-label="Call {{ $mdPhone }}"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg></a>
                    @endif
                    @if ($hasCta)
                    <a href="{{ $ctaUrl }}"{!! $ctaEnquireClick !!}
                       class="inline-flex items-center gap-2 px-5 py-2.5 rounded-md font-bold text-sm shadow-md transition-all hover:shadow-lg hover:brightness-110{{ $navCaseClass }}"
                       style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff);">{{ $ctaLabel }}</a>
                    @endif
                </div>
                @endif
            @elseif ($rightAction === 'none')
            @elseif ($effectiveOverlay)
                @if ($hasCta)
                    <a href="{{ $ctaUrl }}"{!! $ctaEnquireClick !!}
                       class="hidden md:inline-flex lg:hidden items-center gap-2 px-5 py-2.5 rounded-md font-bold text-sm shadow-md transition-all hover:shadow-lg hover:brightness-110"
                       style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff);">{{ $ctaLabel }}</a>
                @elseif ($overlayMdPhone = ($profile['contact']['phones'][0] ?? null))
                    <a href="tel:{{ preg_replace('/\s+/', '', $overlayMdPhone) }}" class="hidden md:flex lg:hidden items-center p-2 js-ovl" :class="(! scrolled) ? 'text-white/85' : '{{ $navLinkClass }}'" aria-label="Call {{ $overlayMdPhone }}"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg></a>
                @endif
            @endif

            {{-- Mobile hamburger --}}
            <div class="flex items-center gap-3 md:hidden" x-data="{ mobileNav: false }">
                @if ($rightAction === 'none')
                @elseif ($rightAction === 'phone_cta')
                    @if ($phone = ($profile['contact']['phones'][0] ?? null))
                    <a href="tel:{{ preg_replace('/\s+/', '', $phone) }}"
                       class="p-2 rounded-md{{ $effectiveOverlay ? ' js-ovl' : '' }}"
                       aria-label="Call {{ $phone }}"
                       @if (! $effectiveOverlay)style="color: var(--brand-accent-text);"@else :class="(! scrolled) ? 'text-white/85' : '[color:var(--brand-accent-text)]'"@endif>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    </a>
                    @endif
                @elseif ($hasCta)
                    <a href="{{ $ctaUrl }}"{!! $ctaEnquireClick !!}
                       class="px-3 py-1.5 rounded text-xs font-bold"
                       style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff);">{{ $ctaLabel }}</a>
                @elseif ($phone = ($profile['contact']['phones'][0] ?? null))
                    <a href="tel:{{ preg_replace('/\s+/', '', $phone) }}"
                       class="p-2 rounded-md{{ $effectiveOverlay ? ' js-ovl' : '' }}"
                       aria-label="Call {{ $phone }}"
                       @if (! $effectiveOverlay)style="color: var(--brand-accent-text);"@else :class="(! scrolled) ? 'text-white/85' : '[color:var(--brand-accent-text)]'"@endif>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    </a>
                @endif
                <button @click="mobileNav = !mobileNav" class="p-2{{ $effectiveOverlay ? '' : ' '.($headerDark ? 'text-white/80' : 'text-gray-600') }}{{ $effectiveOverlay ? ' js-ovl' : '' }}"
                        x-bind:aria-label="mobileNav ? 'Close menu' : 'Open menu'"
                        x-bind:aria-expanded="mobileNav ? 'true' : 'false'"
                        aria-controls="mobile-nav-panel"
                        aria-label="Open menu"@if ($effectiveOverlay) :class="(! scrolled) ? 'text-white/85' : '{{ $headerDark ? 'text-white/80' : 'text-gray-600' }}'"@endif>
                    <svg x-show="!mobileNav" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    <svg x-show="mobileNav" x-cloak class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

                {{-- Mobile nav overlay --}}
                <div id="mobile-nav-panel"
                     x-show="mobileNav" x-cloak
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 -translate-y-2"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 translate-y-0"
                     x-transition:leave-end="opacity-0 -translate-y-2"
                     @click.away="mobileNav = false"
                     class="absolute top-full left-0 right-0 shadow-lg border-t border-gray-100 z-50"
                     style="{{ $navPanelStyle }}">
                    <div class="site-shell-container px-4 py-4 space-y-1">
                        @if($rightAction === 'phone_cta' && $hasCta)<a href="{{ $ctaUrl }}"{!! $ctaEnquireClickMobile !!}
                           class="block w-full text-center px-5 py-2.5 rounded-md font-bold text-sm shadow-md transition-all hover:shadow-lg hover:brightness-110{{ $navCaseClass }}"
                           style="background-color: var(--brand-accent); color: var(--color-text-on-accent, #ffffff);">{{ $ctaLabel }}</a>
@endif@foreach ($navItems ?? [] as $item)
                            @if (in_array($item['shop_nav_style'] ?? null, ['dropdown', 'mega'], true))
                                @include('site.partials.nav-shop-mobile')
                            @elseif (($item['type'] ?? '') === 'group')
                                <div class="px-3 pt-2 pb-1 text-xs font-semibold {{ $navRowDark ? '' : 'text-gray-400' }} uppercase tracking-wide" style="{{ $navGroupLabelStyle }}">
                                    {{ $item['label'] }}
                                </div>
                                @foreach ($item['children'] ?? [] as $child)
                                    <a href="{{ $child['href'] }}" @click="mobileNav = false"
                                       class="block px-6 py-2 text-sm font-medium {{ $navMobileLinkClass }} rounded-md{{ $navCaseClass }}">
                                        {{ $child['label'] }}
                                    </a>
                                @endforeach
                            @else
                                <a href="{{ $item['href'] }}" @click="mobileNav = false"
                                   class="block px-3 py-2.5 text-sm font-medium {{ $navMobileLinkClass }} rounded-md{{ $navCaseClass }}">
                                    {{ $item['label'] }}
                                </a>
                            @endif
                        @endforeach@if ($shopSearchEnabled ?? false)<button type="button" data-shop-search-toggle aria-label="Search the shop" aria-expanded="false" aria-controls="shop-search-panel" @click.stop="mobileNav = false; toggle($event.currentTarget)" class="flex items-center gap-2 rounded-md px-3 py-2.5 text-sm font-medium" style="color: var(--brand-primary-text);"><i data-lucide="search" class="h-4 w-4" aria-hidden="true"></i></button>@endif@if ($shopCartEnabled ?? false)<a href="/shop/cart" data-shop-cart-control @click="mobileNav = false" class="flex items-center justify-between gap-3 rounded-md px-3 py-2.5 text-sm font-medium" style="color: var(--brand-primary-text);"><span class="inline-flex items-center gap-2"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13 5.4 5M7 13l-2.3 2.3c-.6.6-.2 1.7.7 1.7H17m0 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4ZM9 19a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/></svg>Cart</span><span data-shop-cart-count class="inline-flex min-h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-bold" style="background-color: var(--brand-accent); color: var(--color-text-on-accent);">{{ $shopCartItemCount ?? 0 }}</span></a>@endif
                    </div>
                </div>
            </div>
        </div>
    </nav>
@if ($shopSearchEnabled ?? false)@include('site.partials.shop-search-panel', ['shopSearchPanel' => $shopSearchPanel])@endif@if (! $effectiveOverlay && $navRowAccentBorder)<div aria-hidden="true" data-nav-row-accent="always" class="absolute bottom-0 inset-x-0 h-1.5 pointer-events-none" style="background-color: var(--color-accent);"></div>@elseif (! $effectiveOverlay && $navRowAccentWhenStuck)<div aria-hidden="true" data-nav-row-accent="stuck" x-show="scrolled" x-cloak class="absolute bottom-0 inset-x-0 h-1.5 pointer-events-none" style="background-color: var(--color-accent);"></div>@endif</header>
@endif

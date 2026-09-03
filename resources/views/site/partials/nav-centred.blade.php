@php
    $chromeKey = \App\Support\ChromeKnobs::chromeKey($site);
    $storeLabels = \App\Support\ChromeKnobs::storeControls($site) === 'icons+labels';
    $logoHeight = \App\Support\ChromeKnobs::logoHeight($site);
    $desktopLogoClass = match ($logoHeight) {
        'sm' => 'h-[56px] lg:h-[72px]',
        'lg' => 'h-[88px] lg:h-[112px]',
        'xl' => 'h-[120px] lg:h-[152px]',
        default => 'h-[72px] lg:h-[96px]',
    };
    // xl: inline heights (no bundle rebuild needed for new arbitrary values) and a taller brand row.
    $desktopLogoStyle = $logoHeight === 'xl' ? ' style="height: 152px;"' : '';
    $brandRowStyle = $logoHeight === 'xl' ? ' style="height: 192px;"' : '';
    $navSticky = \App\Support\ChromeKnobs::headerShrink($site) === 'on';
    $navCase = \App\Support\ChromeKnobs::navCase($site);
    $navCaseClass = $navCase === 'caps'
        ? ' text-[13px] tracking-[0.16em] uppercase'
        : ($navCase === 'upper' ? ' uppercase tracking-[0.1em] !text-[0.8125rem]' : ($navCase === 'lower' ? ' lowercase' : ''));
    $headerBg = is_string($site->header_bg ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $site->header_bg)
        ? strtolower($site->header_bg)
        : '#ffffff';
    [$hbR, $hbG, $hbB] = sscanf($headerBg, '#%02x%02x%02x');
    $headerDark = (0.2126 * $hbR + 0.7152 * $hbG + 0.0722 * $hbB) / 255 < 0.5;
    $navRowBg = \App\Support\ChromeKnobs::navRowBg($site);
    $navRowPaint = $navRowBg ?? $headerBg;
    $navRowDark = \App\Support\ChromeKnobs::navRowIsDark($site);
    $navRowPattern = \App\Support\ChromeKnobs::navRowPattern($site);
    $navLinkClass = $navRowDark
        ? 'text-white/80 hover:text-white'
        : 'text-gray-600 hover:text-gray-900';
    $navMobileLinkClass = $navRowDark
        ? 'text-white/80 hover:text-white hover:bg-white/10'
        : 'text-gray-700 hover:bg-gray-50';
    $navPanelStyle = "background-color: {$navRowPaint};".($navRowDark ? ' border-color: rgba(255,255,255,0.15);' : '');
    $navGroupLabelStyle = $navRowDark ? 'color: rgba(255,255,255,0.55);' : '';
    $shopSearchPanel = ($shopSearchEnabled ?? false) ? \App\Support\Shop\ShopSearchPanel::for($site) : null;
    $shopSearchXData = ($shopSearchEnabled ?? false) ? ', ...shopSearch()' : '';
    $shopSearchAttrs = $shopSearchPanel
        ? ' data-shop-search-q="'.e($shopSearchPanel['query']).'" data-shop-search-url="'.e($shopSearchPanel['searchUrl']).'"'
        : '';
    $shopAccountEnabled = $shopAccountEnabled ?? false;
    $shopCartEnabled = $shopCartEnabled ?? false;
    $shopSearchEnabled = $shopSearchEnabled ?? false;
    $currentPageId = isset($page) ? $page->id : null;
    $controlPill = \App\Support\ChromeKnobs::storeControlStyle($site) === 'pill';
    $controlClass = 'inline-flex items-center gap-2 text-sm font-medium transition-opacity hover:opacity-80'
        .($controlPill ? ' rounded-full px-3 py-1.5 shadow-sm ring-1 ring-black/10' : '');
    // Pill background: translucent white on light headers, translucent white-ink on dark ones; text keeps the header colour.
    $controlStyle = 'color: var(--brand-primary-text);'.($controlPill ? ' background: '.($headerDark ? 'rgba(255,255,255,0.16)' : 'rgba(255,255,255,0.9)').';' : '');
    $navRowControlStyle = 'color: '.($navRowDark ? '#ffffff' : 'var(--brand-primary-text)').';'.($controlPill ? ' background: '.($navRowDark ? 'rgba(255,255,255,0.16)' : 'rgba(255,255,255,0.9)').';' : '');
    $labelClass = 'hidden md:inline text-xs tracking-[0.18em] uppercase';
    $stickyInit = $navSticky
        ? ' $nextTick(() => { const s = $refs.chromeSentinel; if (!s) return; const io = new IntersectionObserver(([e]) => { stuck = !e.isIntersecting }, { threshold: 0 }); io.observe(s); })'
        : '';
    $searchLabelHtml = $storeLabels ? '<span class="'.e($labelClass).'">Search</span>' : '';
    $accountLabelHtml = $storeLabels ? '<span class="'.e($labelClass).'">Account</span>' : '';
    $bagLabelHtml = $storeLabels ? '<span class="'.e($labelClass).'">Bag</span>' : '';
    $navRowAccentMode = \App\Support\ChromeKnobs::navRowAccentBorder($site);
    $navRowAccentBorder = $navRowAccentMode === 'on'
        || ($navRowAccentMode === 'no_hero' && ! ($navRowHeroBelow ?? false));
    // Hero below in no_hero mode: the hero's own rule wins at the top, but once the
    // nav sticks (hero off screen) the accent comes back so stuck navs match.
    $navRowAccentWhenStuck = $navRowAccentMode === 'no_hero' && ($navRowHeroBelow ?? false);
    $navRowWrapClass = ($navRowPattern !== 'none' || $navRowAccentBorder || $navRowAccentWhenStuck) ? 'relative isolate' : '';
    $navRowPatternAttr = $navRowPattern !== 'none' ? ' data-nav-row-pattern="'.$navRowPattern.'"' : '';
    $stickyWrapAttrs = $navSticky
        ? ' data-chrome-sticky="nav" class="sticky top-0 z-50 motion-safe:transition-shadow'.($navRowWrapClass !== '' ? ' '.$navRowWrapClass : '').'" :class="stuck ? \'shadow-[0_1px_0_rgba(0,0,0,0.08)]\' : \'\'" :data-stuck="stuck ? \'true\' : \'false\'"'
        : ($navRowWrapClass !== '' ? ' class="'.$navRowWrapClass.'"' : '');
    $navContainerStyle = \App\Support\ChromeKnobs::navContainerRenderStyle($site);
    $navContainerFill = \App\Support\ChromeKnobs::navContainerFill($site);
    $navContainerClass = \App\Support\ChromeKnobs::navContainerClass($site);
    $navContainerCss = \App\Support\ChromeKnobs::navContainerCss($site);
    $navContainerBand = $navContainerStyle === 'band';
@endphp
<header x-data="{ stuck: false, mobileNav: false{!! $shopSearchXData !!} }"{!! $shopSearchAttrs !!}
        x-init="{!! $stickyInit !!}"
        data-header-layout="centred"
        data-chrome-preset="{{ $chromeKey }}"
        class="z-50"
        {{-- display:contents — the header must not form a box, or position:sticky on the nav row is
             confined to the header and scrolls away with it (only the nav floats: house rule). --}}
        style="display: contents;">
@php
    $brandPattern = \App\Support\ChromeKnobs::brandPattern($site);
    $brandImageUrl = $brandPattern === 'image' ? \App\Support\ChromeKnobs::brandImageUrl($site) : null;
    $brandImageOpacity = $brandImageUrl !== null ? \App\Support\ChromeKnobs::brandImageOpacity($site) : 0.12;
    $brandImageFit = $brandImageUrl !== null ? \App\Support\ChromeKnobs::brandImageFit($site) : 'cover';
    $brandImagePosY = $brandImageUrl !== null ? \App\Support\ChromeKnobs::brandImagePositionY($site) : 50;
    $brandImageSizeCss = $brandImageFit === 'tile'
        ? 'background-repeat: repeat; background-size: auto;'
        : 'background-size: cover;';
@endphp
    <div data-chrome-brand-band data-brand-pattern="{{ $brandPattern }}" class="w-full relative" style="background: {{ $headerBg }}">
        @if ($brandPattern === 'image' && $brandImageUrl !== null)
        <div aria-hidden="true" data-brand-image class="absolute inset-0 pointer-events-none" style="background-image: url('{{ $brandImageUrl }}'); {{ $brandImageSizeCss }} background-position: center {{ $brandImagePosY }}%; opacity: {{ $brandImageOpacity }};"></div>
        @elseif ($brandPattern !== 'none')
        {{-- Pattern layer: inline SVG (no data: URI, no external asset), tinted with the brand primary
             via currentColor at low opacity so it follows the palette. --}}
        <svg aria-hidden="true" class="absolute inset-0 w-full h-full pointer-events-none" style="color: var(--color-primary); opacity: 0.07;">
            <defs>
                @if ($brandPattern === 'swirl')
                <pattern id="brand-pattern" width="120" height="120" patternUnits="userSpaceOnUse">
                    <path d="M0 60c20-30 40-30 60 0s40 30 60 0" fill="none" stroke="currentColor" stroke-width="2"/>
                    <path d="M-60 120c20-30 40-30 60 0s40 30 60 0 40-30 60 0" fill="none" stroke="currentColor" stroke-width="2"/>
                    <path d="M-60 0c20-30 40-30 60 0s40 30 60 0 40-30 60 0" fill="none" stroke="currentColor" stroke-width="2"/>
                </pattern>
                @else
                <pattern id="brand-pattern" width="24" height="24" patternUnits="userSpaceOnUse">
                    <circle cx="12" cy="12" r="1.5" fill="currentColor"/>
                </pattern>
                @endif
            </defs>
            <rect width="100%" height="100%" fill="url(#brand-pattern)"/>
        </svg>
        @endif
    <div data-chrome-brand-row class="relative hidden md:grid site-shell-container grid-cols-[1fr_auto_1fr] items-center {{ $chromeBrandRowClass ?? 'h-[104px] lg:h-[120px]' }}"{!! $brandRowStyle !!}>
        <div class="justify-self-start">
            @if ($shopSearchEnabled)
                <button type="button" data-shop-search-toggle aria-label="Search" aria-expanded="false" aria-controls="shop-search-panel" @click.stop="toggle($event.currentTarget)" class="{{ $controlClass }}" style="{{ $controlStyle }}"><i data-lucide="search" class="h-4 w-4" aria-hidden="true"></i>{!! $searchLabelHtml !!}</button>
            @endif
        </div>
        <a href="{{ $homeHref ?? '/' }}" class="justify-self-center">
            @if (! empty($logoUrl))
                <img src="{{ $logoUrl }}" alt="{{ $site->business_name }}" class="{{ $desktopLogoClass }} w-auto"{!! $desktopLogoStyle !!}>
            @else
                <span class="text-2xl font-extrabold tracking-tight" style="color: {{ $headerDark ? '#ffffff' : 'var(--brand-primary-text)' }};">{{ $site->business_name }}</span>
            @endif
        </a>
        <div class="justify-self-end flex items-center gap-6">
            @if ($shopAccountEnabled)
                <a href="/shop/account/login" class="{{ $controlClass }}" style="{{ $controlStyle }}"><i data-lucide="user" class="h-4 w-4" aria-hidden="true"></i>{!! $accountLabelHtml !!}</a>
            @endif
            @if ($shopCartEnabled)
                <a href="/shop/cart" data-shop-cart-control class="{{ $controlClass }}" style="{{ $controlStyle }}" aria-label="Bag, {{ $shopCartItemCount ?? 0 }} {{ ($shopCartItemCount ?? 0) === 1 ? 'item' : 'items' }}"><i data-lucide="shopping-bag" class="h-4 w-4" aria-hidden="true"></i>{!! $bagLabelHtml !!}<span data-shop-cart-count class="inline-flex min-h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-bold" style="background-color: var(--brand-accent); color: var(--color-text-on-accent);">{{ $shopCartItemCount ?? 0 }}</span></a>
            @endif
            @if (! $shopAccountEnabled && ! $shopCartEnabled)
                @include('site.partials.nav-right-action')
            @endif
        </div>
    </div>

    <div data-chrome-mobile-row class="relative grid md:hidden site-shell-container grid-cols-[1fr_auto_1fr] items-center h-[72px] relative">
        <div class="justify-self-start">
            <button @click="mobileNav = !mobileNav" class="p-2 {{ $headerDark ? 'text-white/80' : 'text-gray-600' }}"
                    x-bind:aria-label="mobileNav ? 'Close menu' : 'Open menu'"
                    x-bind:aria-expanded="mobileNav ? 'true' : 'false'"
                    aria-controls="mobile-nav-panel"
                    aria-label="Open menu">
                <svg x-show="!mobileNav" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                <svg x-show="mobileNav" x-cloak class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <a href="{{ $homeHref ?? '/' }}" class="justify-self-center">
            @if (! empty($logoUrl))
                <img src="{{ $logoUrl }}" alt="{{ $site->business_name }}" class="h-[56px] w-auto">
            @else
                <span class="text-lg font-extrabold tracking-tight" style="color: {{ $headerDark ? '#ffffff' : 'var(--brand-primary-text)' }};">{{ $site->business_name }}</span>
            @endif
        </a>
        <div class="justify-self-end flex items-center gap-3">
            @if ($shopSearchEnabled)
                <button type="button" data-shop-search-toggle aria-label="Search" aria-expanded="false" aria-controls="shop-search-panel" @click.stop="toggle($event.currentTarget)" class="p-1" style="color: var(--brand-primary-text);"><i data-lucide="search" class="h-4 w-4" aria-hidden="true"></i></button>
            @endif
            @if ($shopCartEnabled)
                <a href="/shop/cart" data-shop-cart-control class="p-1" style="color: var(--brand-primary-text);" aria-label="Bag, {{ $shopCartItemCount ?? 0 }} {{ ($shopCartItemCount ?? 0) === 1 ? 'item' : 'items' }}"><i data-lucide="shopping-bag" class="h-4 w-4" aria-hidden="true"></i></a>
            @endif
        </div>
        <div id="mobile-nav-panel"
             x-show="mobileNav" x-cloak
             x-transition:enter="motion-safe:transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="motion-safe:transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 -translate-y-2"
             @click.away="mobileNav = false"
             class="absolute top-full left-0 right-0 shadow-lg border-t border-gray-100 z-50"
             style="{{ $navPanelStyle }}">
            <div class="site-shell-container px-4 py-4 space-y-1">
                @foreach ($navItems ?? [] as $item)
                    @if (in_array($item['shop_nav_style'] ?? null, ['dropdown', 'mega'], true))
                        @include('site.partials.nav-shop-mobile')
                    @elseif (($item['type'] ?? '') === 'group')
                        <div class="px-3 pt-2 pb-1 text-xs font-semibold {{ $navRowDark ? '' : 'text-gray-400' }} uppercase tracking-wide" style="{{ $navGroupLabelStyle }}">
                            {{ $item['label'] }}
                        </div>
                        @foreach ($item['children'] ?? [] as $child)
                            @php $childCurrent = ($currentPageId !== null && ($child['page_id'] ?? null) === $currentPageId) ? ' aria-current="page"' : ''; @endphp
                            <a href="{{ $child['href'] }}" @click="mobileNav = false"{!! $childCurrent !!}
                               class="block px-6 py-2 text-sm font-medium {{ $navMobileLinkClass }} rounded-md{{ $navCaseClass }}">
                                {{ $child['label'] }}
                            </a>
                        @endforeach
                    @else
                        @php $itemCurrent = ($currentPageId !== null && ($item['page_id'] ?? null) === $currentPageId) ? ' aria-current="page"' : ''; @endphp
                        <a href="{{ $item['href'] }}" @click="mobileNav = false"{!! $itemCurrent !!}
                           class="block px-3 py-2.5 text-sm font-medium {{ $navMobileLinkClass }} rounded-md{{ $navCaseClass }}">
                            {{ $item['label'] }}
                        </a>
                    @endif
                @endforeach
                @if ($shopAccountEnabled)
                    <a href="/shop/account/login" @click="mobileNav = false" class="flex items-center gap-2 rounded-md px-3 py-2.5 text-sm font-medium" style="color: var(--brand-primary-text);"><i data-lucide="user" class="h-4 w-4" aria-hidden="true"></i>Account</a>
                @endif
            </div>
        </div>
    </div>

    </div>
    <div x-ref="chromeSentinel" data-chrome-sticky-sentinel class="h-px w-full" aria-hidden="true"></div>
    <div{!! $stickyWrapAttrs !!}{!! $navRowPatternAttr !!} style="background: {{ $navRowPaint }}">
        @if ($navRowPattern !== 'none')
            @include('site.partials.nav-row-pattern')
        @endif
        @if ($navRowAccentBorder)
            <div aria-hidden="true" data-nav-row-accent="always" class="absolute bottom-0 inset-x-0 h-1.5 pointer-events-none" style="background-color: var(--color-accent);"></div>
        @elseif ($navRowAccentWhenStuck)
            <div aria-hidden="true" data-nav-row-accent="stuck" x-show="stuck" x-cloak class="absolute bottom-0 inset-x-0 h-1.5 pointer-events-none" style="background-color: var(--color-accent);"></div>
        @endif
        <nav data-chrome-nav-row aria-label="Primary" class="hidden md:grid site-shell-container grid-cols-[1fr_auto_1fr] items-center h-12 border-t border-black/5{{ $navContainerBand ? ' relative' : '' }}">
            <div class="justify-self-start{{ $navContainerBand ? ' col-start-1 row-start-1 z-10' : '' }}">
                @if ($shopSearchEnabled && $navSticky)
                    <button type="button" data-shop-search-toggle x-show="stuck" x-cloak aria-label="Search" aria-expanded="false" aria-controls="shop-search-panel" @click.stop="toggle($event.currentTarget)" class="{{ $controlClass }}" style="{{ $navRowControlStyle }}"><i data-lucide="search" class="h-4 w-4" aria-hidden="true"></i></button>
                @endif
            </div>
            @if ($navContainerStyle === 'none')<div class="justify-self-center flex justify-center gap-8 lg:gap-10 items-center">@else<style>[data-nav-container][data-nav-container-style]>a:not(:hover),[data-nav-container][data-nav-container-style]>button:not(:hover),[data-nav-container][data-nav-container-style]>[data-shop-nav-style]>a:not(:hover),[data-nav-container][data-nav-container-style]>div>button:not(:hover){color:var(--nav-container-ink)}@if ($navContainerFill === 'glass')[data-nav-container][data-nav-container-fill="glass"]::before{content:"";position:absolute;inset:0;z-index:-1;border-radius:inherit;background-color:var(--nav-container-bg);-webkit-backdrop-filter:blur(12px) saturate(1.4);backdrop-filter:blur(12px) saturate(1.4);pointer-events:none}@supports not (backdrop-filter:blur(1px)){[data-nav-container][data-nav-container-fill="glass"]::before{background-color:var(--color-surface);-webkit-backdrop-filter:none;backdrop-filter:none}}@media (prefers-reduced-transparency:reduce){[data-nav-container][data-nav-container-fill="glass"]::before{background-color:var(--color-surface);-webkit-backdrop-filter:none;backdrop-filter:none}}@elseif ($navContainerFill === 'pattern')@media (prefers-reduced-transparency:reduce){[data-nav-container][data-nav-container-fill="pattern"]{background-color:var(--color-surface)!important;background-image:none!important}}@endif</style><div data-nav-container data-nav-container-style="{{ $navContainerStyle }}" data-nav-container-fill="{{ $navContainerFill }}"{{ $navContainerBand ? ' data-nav-container-band' : '' }} class="justify-self-center flex justify-center gap-8 lg:gap-10 items-center {{ $navSticky ? '' : 'px-5 py-2 ' }}{{ $navContainerClass }} transition-[padding,background-color,box-shadow,backdrop-filter] duration-200{{ $navContainerBand ? ' col-start-1 col-end-4 row-start-1 w-full' : '' }}"@if ($navSticky) :class="stuck ? 'px-3 py-1.5' : 'px-5 py-2'"@endif style="{{ $navContainerCss }}">@endif
                @foreach ($navItems ?? [] as $item)
                    @if (in_array($item['shop_nav_style'] ?? null, ['dropdown', 'mega'], true))
                        @include('site.partials.nav-shop-desktop', [
                            'effectiveOverlay' => false,
                            'shopTriggerClass' => 'font-medium transition-colors '.$navLinkClass.$navCaseClass.' flex items-center gap-1',
                            'shopChildClass' => 'block px-4 py-2 text-sm transition-colors '.($navRowDark ? 'text-white/80 hover:text-white hover:bg-white/10' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50').$navCaseClass,
                            'shopIndentClass' => 'block px-6 py-2 text-sm transition-colors '.($navRowDark ? 'text-white/80 hover:text-white hover:bg-white/10' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50').$navCaseClass,
                        ])
                    @elseif (($item['type'] ?? '') === 'group')
                        <div x-data="{ open: false }" class="relative"
                             @mouseenter="if (window.innerWidth >= 768) open = true"
                             @mouseleave="if (window.innerWidth >= 768) open = false">
                            <button @click="open = !open" @click.away="open = false"
                                    class="font-medium transition-colors {{ $navLinkClass }}{{ $navCaseClass }} flex items-center gap-1 cursor-pointer">
                                {{ $item['label'] }}
                                <svg class="w-3.5 h-3.5 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="open" x-cloak
                                 x-transition:enter="motion-safe:transition ease-out duration-100"
                                 x-transition:enter-start="opacity-0 scale-95"
                                 x-transition:enter-end="opacity-100 scale-100"
                                 x-transition:leave="motion-safe:transition ease-in duration-75"
                                 x-transition:leave-start="opacity-100 scale-100"
                                 x-transition:leave-end="opacity-0 scale-95"
                                 class="absolute top-full left-0 mt-2 w-64 rounded-lg shadow-lg border border-gray-200 py-2 z-50"
                                 style="{{ $navPanelStyle }}">
                                @foreach ($item['children'] ?? [] as $child)
                                    @php $childCurrent = ($currentPageId !== null && ($child['page_id'] ?? null) === $currentPageId) ? ' aria-current="page"' : ''; @endphp
                                    <a href="{{ $child['href'] }}"{!! $childCurrent !!}
                                       class="block px-4 py-2 text-sm transition-colors {{ $navRowDark ? 'text-white/80 hover:text-white hover:bg-white/10' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}{{ $navCaseClass }}">
                                        {{ $child['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @else
                        @php $itemCurrent = ($currentPageId !== null && ($item['page_id'] ?? null) === $currentPageId) ? ' aria-current="page"' : ''; @endphp
                        <a href="{{ $item['href'] }}"{!! $itemCurrent !!}
                           class="font-medium transition-colors {{ $navLinkClass }}{{ $navCaseClass }}">
                            {{ $item['label'] }}
                        </a>
                    @endif
                @endforeach
            </div>
            <div class="justify-self-end{{ $navContainerBand ? ' col-start-3 row-start-1 z-10' : '' }}">
                @if ($shopCartEnabled && $navSticky)
                    <a href="/shop/cart" data-shop-cart-control x-show="stuck" x-cloak class="{{ $controlClass }}" style="{{ $navRowControlStyle }}" aria-label="Bag, {{ $shopCartItemCount ?? 0 }} {{ ($shopCartItemCount ?? 0) === 1 ? 'item' : 'items' }}"><i data-lucide="shopping-bag" class="h-4 w-4" aria-hidden="true"></i><span data-shop-cart-count class="inline-flex min-h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-bold" style="background-color: var(--brand-accent); color: var(--color-text-on-accent);">{{ $shopCartItemCount ?? 0 }}</span></a>
                @endif
            </div>
        </nav>
        @if ($shopSearchEnabled)@include('site.partials.shop-search-panel', ['shopSearchPanel' => $shopSearchPanel])@endif
    </div>
</header>

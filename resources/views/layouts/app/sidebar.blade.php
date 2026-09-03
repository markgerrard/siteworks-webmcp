<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark" style="--cp-left-dock: 0px; --cp-topbar: 3.5rem">
    <head>
        {{-- Synchronous: stamp stored nav-group open classes on <html>
             before first paint. Flux reads `open` only at upgrade, so a
             later Alpine x-init write is a no-op; the class is applied to
             the disclosure below, before @fluxScripts. Current child still
             wins via Blade `:expanded`. @cspNonce (SPACE required). --}}
        <script @cspNonce>
            (function () {
                try {
                    if (localStorage.getItem('siteworks.nav.content') === '1') {
                        document.documentElement.classList.add('nav-content-open');
                    }
                    if (localStorage.getItem('siteworks.nav.shop') === '1') {
                        document.documentElement.classList.add('nav-shop-open');
                    }
                } catch (e) {}
            })();
        </script>
        @include('partials.head')
        {{-- Flux's sidebar layout lays <body> out as a row; the fixed top bar
             escapes it, so offset the row and the sticky sidebar below it. --}}
        <style @cspNonce>
            body { padding-top: var(--cp-topbar); }
            [data-flux-sidebar] { top: var(--cp-topbar) !important; height: calc(100dvh - var(--cp-topbar)) !important; }
        </style>
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <aside id="cp-left-dock" hidden></aside>
        <x-cp-topbar :search="true" :fixed="true" :brand-pane="true">
            <x-slot:nav-toggle>
                <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
            </x-slot:nav-toggle>
        </x-cp-topbar>
        <flux:sidebar sticky collapsible="mobile" data-cp-sidebar class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header class="lg:hidden">
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                    @if (auth()->user()->isStaff())
                        <flux:sidebar.item icon="building-office" :href="route('clients.index')" :current="request()->routeIs('clients.*')" wire:navigate>
                            {{ __('Clients') }}
                        </flux:sidebar.item>
                    @endif
                    <flux:sidebar.item icon="folder" :href="route('sites.index')" :current="request()->routeIs('sites.*')" wire:navigate>
                        {{ __('Sites') }}
                    </flux:sidebar.item>
                    @if (auth()->user()->isAdmin())
                        <flux:sidebar.item icon="cog-6-tooth" :href="route('admin.index')" :current="request()->routeIs('admin.*')" wire:navigate>
                            {{ __('Admin') }}
                        </flux:sidebar.item>
                    @endif
                </flux:sidebar.group>
            </flux:sidebar.nav>

            @php
                $navSite = request()->route('site');
                $navSite = $navSite instanceof \App\Models\Site ? $navSite : null;
                $navSection = is_string(request()->route('section')) ? request()->route('section') : null;
                $navHasShop = $navSite !== null && $navSite->shopEnabled();
                $navHasOrders = $navSite !== null
                    && ($navSite->shopEnabled() || $navSite->hasEstablishedShop())
                    && $navSite->shopShowsAccountOrders();
                $navShowShopGroup = $navHasShop || $navHasOrders;
                $navIsAdmin = auth()->user()?->isAdmin() ?? false;
                $contentNavCurrent = in_array($navSection, ['pages', 'navigation', 'design', 'personalise', 'chatbot'], true);
                $navShopRoute = match (true) {
                    request()->routeIs('sites.shop.products') => 'sites.shop.products',
                    request()->routeIs('sites.shop.categories') => 'sites.shop.categories',
                    request()->routeIs('sites.shop.orders') => 'sites.shop.orders',
                    request()->routeIs('sites.shop.storefront') => 'sites.shop.storefront',
                    request()->routeIs('sites.shop.reviews') => 'sites.shop.reviews',
                    default => null,
                };
                $shopNavCurrent = $navShopRoute !== null
                    || $navSection === 'ops'
                    || request()->routeIs('shop.admin.products.edit', 'shop.admin.orders.show');

                $navSectionHref = function (\App\Models\Site $target) use ($navSection, $navShopRoute, $navIsAdmin): string {
                    if ($navShopRoute !== null) {
                        return route($navShopRoute, $target);
                    }
                    if ($navSection === null) {
                        return route('sites.show', $target);
                    }
                    if ($navSection === 'ops' && ! $navIsAdmin) {
                        return route('sites.show', $target);
                    }

                    return route('sites.section', ['site' => $target, 'section' => $navSection]);
                };

                $switcherSites = $navSite
                    ? auth()->user()->accessibleSites()->orderByDesc('updated_at')->limit(8)->get(['id', 'business_name', 'updated_at'])
                    : collect();
            @endphp

            @if ($navSite)
                <flux:sidebar.nav class="flex-1">
                    <flux:sidebar.group class="flex-1">
                        <div class="px-3 py-2">
                            <flux:dropdown align="start" class="w-full">
                                <button type="button" class="flex w-full items-center gap-1 text-sm text-zinc-400 font-medium leading-none hover:text-zinc-800 dark:hover:text-white cursor-pointer">
                                    <span class="truncate">{{ $navSite->business_name }}</span>
                                    <flux:icon name="chevron-down" class="size-3 shrink-0" />
                                </button>
                                <flux:menu>
                                    @foreach ($switcherSites as $option)
                                        <flux:menu.item :href="$navSectionHref($option)" wire:navigate>
                                            {{ $option->business_name }}
                                        </flux:menu.item>
                                    @endforeach
                                    <flux:menu.separator />
                                    <flux:menu.item :href="route('sites.index')" wire:navigate>
                                        {{ __('All sites') }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>

                        <flux:sidebar.item icon="home" :href="route('sites.show', $navSite)" :current="request()->routeIs('sites.show')" wire:navigate>
                            {{ __('Overview') }}
                        </flux:sidebar.item>

                        <flux:sidebar.group
                            expandable
                            icon="document-text"
                            :heading="__('Content')"
                            :expanded="$contentNavCurrent"
                            data-nav-persist="siteworks.nav.content"
                            data-nav-current="{{ $contentNavCurrent ? '1' : '0' }}"
                            data-current="{{ $contentNavCurrent ? 'true' : 'false' }}"
                            class="data-[current=true]:[&>button]:text-(--color-accent-content) data-[current=true]:[&>button]:bg-white dark:data-[current=true]:[&>button]:bg-white/[7%] data-[current=true]:[&>button]:border-zinc-200 dark:data-[current=true]:[&>button]:border-transparent"
                            x-data="{ open: {{ $contentNavCurrent ? 'true' : 'false' }} || document.documentElement.classList.contains('nav-content-open') }"
                            x-init="
                                const key = $el.dataset.navPersist;
                                // Flux dispatches lofi-disclosable-change BEFORE it stamps data-open and never removes the
                                // initial `open` attribute — so read only data-open, one frame later.
                                const persist = () => {
                                    requestAnimationFrame(() => {
                                        try {
                                            localStorage.setItem(key, $el.hasAttribute('data-open') ? '1' : '0');
                                        } catch (e) {}
                                    });
                                };
                                $el.addEventListener('lofi-disclosable-change', persist);
                            "
                        >
                            <flux:sidebar.item icon="document-text" :href="route('sites.section', ['site' => $navSite, 'section' => 'pages'])" :current="$navSection === 'pages'" wire:navigate>
                                {{ __('Pages') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="bars-3" :href="route('sites.section', ['site' => $navSite, 'section' => 'navigation'])" :current="$navSection === 'navigation'" wire:navigate>
                                {{ __('Navigation') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="paint-brush" :href="route('sites.section', ['site' => $navSite, 'section' => 'design'])" :current="$navSection === 'design'" wire:navigate>
                                {{ __('Design') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="user-circle" :href="route('sites.section', ['site' => $navSite, 'section' => 'personalise'])" :current="$navSection === 'personalise'" wire:navigate>
                                {{ __('Personalise') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="chat-bubble-left-right" :href="route('sites.section', ['site' => $navSite, 'section' => 'chatbot'])" :current="$navSection === 'chatbot'" wire:navigate>
                                {{ __('Chatbot') }}
                            </flux:sidebar.item>
                        </flux:sidebar.group>

                        @if ($navShowShopGroup)
                            <flux:sidebar.group
                                expandable
                                icon="shopping-bag"
                                :heading="__('Shop')"
                                :expanded="$shopNavCurrent"
                                data-nav-persist="siteworks.nav.shop"
                                data-nav-current="{{ $shopNavCurrent ? '1' : '0' }}"
                                data-current="{{ $shopNavCurrent ? 'true' : 'false' }}"
                                class="data-[current=true]:[&>button]:text-(--color-accent-content) data-[current=true]:[&>button]:bg-white dark:data-[current=true]:[&>button]:bg-white/[7%] data-[current=true]:[&>button]:border-zinc-200 dark:data-[current=true]:[&>button]:border-transparent"
                                x-data="{ open: {{ $shopNavCurrent ? 'true' : 'false' }} || document.documentElement.classList.contains('nav-shop-open') }"
                                x-init="
                                    const key = $el.dataset.navPersist;
                                    // Flux dispatches lofi-disclosable-change BEFORE it stamps data-open and never removes the
                                    // initial `open` attribute — so read only data-open, one frame later.
                                    const persist = () => {
                                        requestAnimationFrame(() => {
                                            try {
                                                localStorage.setItem(key, $el.hasAttribute('data-open') ? '1' : '0');
                                            } catch (e) {}
                                        });
                                    };
                                    $el.addEventListener('lofi-disclosable-change', persist);
                                "
                            >
                                @if ($navHasShop)
                                    <flux:sidebar.item icon="cube" :href="route('sites.shop.products', $navSite)" :current="request()->routeIs('sites.shop.products')" wire:navigate>
                                        {{ __('Products') }}
                                    </flux:sidebar.item>
                                    <flux:sidebar.item icon="squares-2x2" :href="route('sites.shop.categories', $navSite)" :current="request()->routeIs('sites.shop.categories')" wire:navigate>
                                        {{ __('Categories') }}
                                    </flux:sidebar.item>
                                @endif
                                @if ($navHasOrders)
                                    <flux:sidebar.item icon="inbox-stack" :href="route('sites.shop.orders', $navSite)" :current="request()->routeIs('sites.shop.orders')" wire:navigate>
                                        {{ __('Orders') }}
                                    </flux:sidebar.item>
                                @endif
                                @if ($navHasShop)
                                    <flux:sidebar.item icon="building-storefront" :href="route('sites.shop.storefront', $navSite)" :current="request()->routeIs('sites.shop.storefront')" wire:navigate>
                                        {{ __('Storefront') }}
                                    </flux:sidebar.item>
                                    <flux:sidebar.item icon="star" :href="route('sites.shop.reviews', $navSite)" :current="request()->routeIs('sites.shop.reviews')" wire:navigate>
                                        {{ __('Reviews') }}
                                    </flux:sidebar.item>
                                @endif
                                @if ($navHasShop && $navIsAdmin)
                                    <flux:sidebar.item icon="wrench-screwdriver" :href="route('sites.section', ['site' => $navSite, 'section' => 'ops'])" :current="$navSection === 'ops'" wire:navigate>
                                        {{ __('Ops') }}
                                    </flux:sidebar.item>
                                @endif
                            </flux:sidebar.group>
                        @endif
                        <flux:sidebar.item icon="inbox" :href="route('sites.section', ['site' => $navSite, 'section' => 'enquiries'])" :current="$navSection === 'enquiries'" wire:navigate>
                            {{ __('Enquiries') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="clock" :href="route('sites.section', ['site' => $navSite, 'section' => 'history'])" :current="$navSection === 'history'" wire:navigate>
                            {{ __('History') }}
                        </flux:sidebar.item>

                        <flux:sidebar.spacer />

                        <flux:sidebar.item icon="cog-6-tooth" :href="route('sites.section', ['site' => $navSite, 'section' => 'settings'])" :current="$navSection === 'settings'" wire:navigate>
                            {{ __('Settings') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                </flux:sidebar.nav>
            @else
                <flux:spacer />

                <flux:sidebar.nav>
                </flux:sidebar.nav>
            @endif

        </flux:sidebar>

        {{ $slot }}

        <x-cp-assistant-panel />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        {{-- Before Flux upgrades ui-disclosure (open is init-only). Current
             child already has `open` from Blade; stored-open adds it from
             the html class stamped in <head>. Never removes open. --}}
        <script @cspNonce>
            (function () {
                document.querySelectorAll('ui-disclosure[data-nav-persist]').forEach(function (el) {
                    var current = el.dataset.navCurrent === '1';
                    var slug = (el.dataset.navPersist || '').replace('siteworks.nav.', '');
                    var stored = false;
                    try { stored = localStorage.getItem('siteworks.nav.' + slug) === '1'; } catch (e) {}
                    // Read storage directly: wire:navigate re-applies the server's <html class>, wiping the
                    // head-stamped nav-*-open class, and the identical head script is not re-run.
                    if (current || stored || document.documentElement.classList.contains('nav-' + slug + '-open')) {
                        el.setAttribute('open', '');
                    }
                });
            })();
        </script>
        @fluxScripts(['nonce' => \Illuminate\Support\Facades\Vite::cspNonce()])
    </body>
</html>

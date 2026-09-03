@php
    $user = auth()->user();
    $accessibleSites = $user?->accessibleSites()->orderBy('business_name')->get() ?? collect();
    $activeSite = $site ?? $accessibleSites->first();

    $sectionRoute = function (string $name) use ($activeSite) {
        if (! $activeSite) {
            return '#';
        }
        $routeName = "client.portal.{$name}";
        return \Illuminate\Support\Facades\Route::has($routeName)
            ? route($routeName, $activeSite)
            : '#';
    };

    $contentSections = [
        ['key' => 'site',         'icon' => 'document-text',          'label' => 'Pages',         'route' => 'client.portal.site'],
        ['key' => 'navigation',   'icon' => 'bars-3',                 'label' => 'Navigation',    'route' => 'client.portal.navigation'],
        ['key' => 'design',       'icon' => 'paint-brush',            'label' => 'Design',        'route' => 'client.portal.design'],
        ['key' => 'chatbot',      'icon' => 'chat-bubble-left-right', 'label' => 'Chatbot',       'route' => 'client.portal.chatbot'],
    ];

    $shopSections = [
        ...($activeSite?->portalShopReachable()
            ? [
                ['key' => 'shop.products', 'icon' => 'cube', 'label' => 'Products', 'route' => 'client.portal.shop.products'],
                ['key' => 'shop.categories', 'icon' => 'squares-2x2', 'label' => 'Categories', 'route' => 'client.portal.shop.categories'],
                ['key' => 'shop.storefront', 'icon' => 'building-storefront', 'label' => 'Storefront', 'route' => 'client.portal.shop.storefront'],
                ['key' => 'shop.reviews', 'icon' => 'star', 'label' => 'Reviews', 'route' => 'client.portal.shop.reviews'],
            ]
            : []),
        ...($activeSite?->hasEstablishedShop() && $activeSite->shopShowsAccountOrders() // orders are owed after payment, flag or not
            ? [['key' => 'orders', 'icon' => 'inbox-stack', 'label' => 'Orders', 'route' => 'client.portal.orders']]
            : []),
    ];

    $restSections = [
        ['key' => 'business-info','icon' => 'briefcase',              'label' => 'Business Info', 'route' => 'client.portal.business-info'],
        ['key' => 'enquiries',    'icon' => 'inbox',                  'label' => 'Enquiries',     'route' => 'client.portal.enquiries'],
        ['key' => 'history',      'icon' => 'clock',                  'label' => 'History',       'route' => 'client.portal.history'],
        ...(config('site.native_reviews_enabled') && $activeSite?->native_reviews_enabled
            ? [['key' => 'reviews', 'icon' => 'star', 'label' => 'Reviews', 'route' => 'client.portal.reviews']]
            : []),
    ];

    $navGroups = [
        ['key' => 'content', 'label' => 'Content', 'icon' => 'document-text', 'items' => $contentSections],
        ...($shopSections !== []
            ? [['key' => 'shop', 'label' => 'Shop', 'icon' => 'shopping-bag', 'items' => $shopSections]]
            : []),
    ];

    if ($activeSite) {
        $publicHost = $activeSite->custom_domain && $activeSite->custom_domain_status === 'active'
            ? $activeSite->custom_domain
            : $activeSite->previewHostname();
        $hasPublicHost = $activeSite->preview_domain
            || ($activeSite->custom_domain && $activeSite->custom_domain_status === 'active');
        $viewSiteHref = $publicHost
            ? 'https://'.$publicHost.'/_edit/view-live'
            : ($activeSite->latestPreview ? route('preview.show', $activeSite->latestPreview->slug) : null);
        $homePage = $activeSite->generatedPages()
            ->where('page_type', 'home')
            ->whereNull('archived_at')
            ->first();
        $statusValue = $activeSite->status->value;
        $showStatusBadge = $statusValue !== 'review';
        $statusColor = match ($statusValue) {
            'draft' => 'zinc',
            'published' => 'green',
            'failed' => 'red',
            default => 'blue',
        };
    }
@endphp

{{-- Sidebar mini-state is driven by the `sidebar-mini` class on <html>,
     pinned synchronously by the inline script in the layout's <head>
     before first paint. All collapsed-state visibility/layout is done
     via Tailwind arbitrary parent variants ([html.sidebar-mini_&]:…)
     so the rendered DOM is correct on first paint regardless of when
     Alpine hydrates — no FOUC flicker on heavy pages like Pages.
     Alpine still owns the toggle: clicks update the html class and
     persist to localStorage. --}}
<div class="contents">

    {{-- Mobile drawer backdrop --}}
    <div x-show="mobileOpen" x-transition.opacity x-cloak
         x-on:click="mobileOpen = false"
         class="lg:hidden fixed inset-0 z-40 bg-black/40">
    </div>

    {{-- The sidebar itself — desktop sticky below the top bar, mobile slide-in.
         Width flips between mini (4rem) and full (16rem) on lg+. --}}
    <aside
        data-cp-sidebar
        x-bind:class="{
            'translate-x-0': mobileOpen,
            '-translate-x-full lg:translate-x-0': !mobileOpen,
        }"
        class="fixed lg:sticky top-[var(--cp-topbar)] z-50 lg:z-auto
               flex h-[calc(100vh-var(--cp-topbar))] w-64 shrink-0 flex-col
               lg:w-64 [html.sidebar-mini_&]:lg:w-16
               border-e border-zinc-700 bg-zinc-900
               transition-[transform,width] duration-150 ease-out"
    >
        {{-- Header: collapse-to-mini / mobile close (brand lives in the top bar) --}}
        <div class="flex h-12 items-center gap-2 border-b border-zinc-700 px-3
                    justify-end [html.sidebar-mini_&]:justify-center">
            {{-- Desktop mini toggle --}}
            <button type="button"
                    x-on:click="mini = true"
                    class="hidden lg:flex h-8 w-8 items-center justify-center rounded-md text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100 cursor-pointer
                           [html.sidebar-mini_&]:hidden"
                    aria-label="Collapse sidebar">
                <flux:icon name="chevron-double-left" class="size-4" />
            </button>
            {{-- Mobile close --}}
            <button type="button" x-on:click="mobileOpen = false"
                    class="lg:hidden flex h-8 w-8 items-center justify-center rounded-md text-zinc-400 hover:bg-zinc-800 cursor-pointer"
                    aria-label="Close navigation">
                <flux:icon name="x-mark" class="size-4" />
            </button>
        </div>

        {{-- Mini-state expand button (only visible when mini, sits below logo) --}}
        <button type="button"
                x-on:click="mini = false"
                class="hidden [html.sidebar-mini_&]:lg:flex h-8 mx-auto mt-2 w-8 items-center justify-center rounded-md text-zinc-400 hover:bg-zinc-800 hover:text-zinc-100 cursor-pointer"
                aria-label="Expand sidebar">
            <flux:icon name="chevron-double-right" class="size-4" />
        </button>

        {{-- Active site block: switcher, status.
             View site / Edit site now live in the top bar. --}}
        @if ($activeSite)
            <div class="border-b border-zinc-700
                        px-4 py-3 space-y-3
                        [html.sidebar-mini_&]:p-2 [html.sidebar-mini_&]:space-y-0">
                <div class="[html.sidebar-mini_&]:hidden">
                    <livewire:client.site-switcher
                        :active-site-id="$activeSite->id"
                        wire:key="switcher-{{ $activeSite->id }}"
                    />
                </div>
                @if ($showStatusBadge)
                    <div class="[html.sidebar-mini_&]:hidden">
                        <flux:badge size="sm" :color="$statusColor">{{ ucfirst($statusValue) }}</flux:badge>
                    </div>
                @endif
            </div>
        @endif

        {{-- Section nav --}}
        <nav class="flex-1 overflow-y-auto py-3
                    px-3 [html.sidebar-mini_&]:px-1">
            @if ($activeSite)
                <p class="px-2 mb-1 text-xs font-semibold uppercase tracking-wider text-zinc-400
                          [html.sidebar-mini_&]:hidden">
                    {{ __('Site') }}
                </p>
                @foreach ($navGroups as $group)
                    @php
                        $groupCurrent = collect($group['items'])->contains(
                            fn (array $item): bool => request()->routeIs($item['route'], $item['route'].'.*')
                        );
                        // A group header is a toggle/parent, not a leaf — it's "current"
                        // only because a child matches. So it must NOT paint the filled
                        // bg-accent/15 block (that would stack a second translucent slab
                        // behind the active child); it tints its label with accent text
                        // only. The fill is reserved for the case a group is its own
                        // route target with no active child (none today, but kept honest).
                        $groupIsOwnTarget = isset($group['route'])
                            && request()->routeIs($group['route'], $group['route'].'.*');
                        $groupOwnFill = $groupIsOwnTarget && ! $groupCurrent;
                        // Literal class strings so Tailwind's view scan (and @source inline) keep the
                        // arbitrary parent variants. Current groups omit the gate — first paint is
                        // always open, matching today's forceOpen.
                        $navClosedGate = match ($group['key']) {
                            'content' => '[html.nav-closed-content_&]:hidden',
                            'shop' => '[html.nav-closed-shop_&]:hidden',
                            default => '',
                        };
                    @endphp
                    <div
                        class="mb-1"
                        data-nav-persist="siteworks.nav.{{ $group['key'] }}"
                        data-nav-current="{{ $groupCurrent ? '1' : '0' }}"
                        x-data="{ open: {{ $groupCurrent ? 'true' : 'false' }} || !document.documentElement.classList.contains('nav-closed-{{ $group['key'] }}') }"
                        x-init="
                            $watch('open', v => {
                                const key = $el.dataset.navPersist.replace('siteworks.nav.', '');
                                document.documentElement.classList.toggle('nav-closed-' + key, !v);
                                document.documentElement.classList.toggle('nav-open-' + key, v);
                                try { localStorage.setItem($el.dataset.navPersist, v ? '1' : '0'); } catch (e) {}
                            });
                        "
                    >
                        <button type="button"
                                title="{{ $group['label'] }}"
                                x-on:click="open = !open"
                                x-bind:aria-expanded="open.toString()"
                                @class([
                                    'group relative flex h-9 w-full items-center gap-3 rounded-md text-sm transition-colors',
                                    'px-3 [html.sidebar-mini_&]:justify-center [html.sidebar-mini_&]:px-0',
                                    'bg-accent/15' => $groupOwnFill,
                                    'text-accent font-semibold' => $groupCurrent || $groupOwnFill,
                                    'text-zinc-300 hover:bg-zinc-800 hover:text-white' => ! $groupCurrent && ! $groupOwnFill,
                                ])>
                            <flux:icon :name="$group['icon']" class="size-5 shrink-0" />
                            <span class="truncate [html.sidebar-mini_&]:hidden">{{ $group['label'] }}</span>
                            <span class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 -translate-y-1/2 whitespace-nowrap rounded-md bg-zinc-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-lg transition-opacity duration-100 group-hover:opacity-100 dark:bg-zinc-700
                                         hidden [html.sidebar-mini_&]:inline-block">
                                {{ $group['label'] }}
                            </span>
                        </button>
                        <ul @class([
                                'space-y-0.5 ps-3 [html.sidebar-mini_&]:ps-0',
                                $navClosedGate => ! $groupCurrent,
                            ])
                            x-show="open">
                            @foreach ($group['items'] as $s)
                                @php
                                    $current = request()->routeIs($s['route'], $s['route'].'.*');
                                @endphp
                                <li>
                                    <a href="{{ $sectionRoute($s['key']) }}"
                                       title="{{ $s['label'] }}"
                                       @class([
                                           'group relative flex h-9 items-center gap-3 rounded-md text-sm transition-colors',
                                           'px-3 [html.sidebar-mini_&]:justify-center [html.sidebar-mini_&]:px-0',
                                           'bg-accent/15 text-accent font-semibold' => $current,
                                           'text-zinc-300 hover:bg-zinc-800 hover:text-white' => ! $current,
                                       ])>
                                        <flux:icon :name="$s['icon']" class="size-5 shrink-0" />
                                        <span class="truncate [html.sidebar-mini_&]:hidden">{{ $s['label'] }}</span>
                                        <span class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 -translate-y-1/2 whitespace-nowrap rounded-md bg-zinc-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-lg transition-opacity duration-100 group-hover:opacity-100 dark:bg-zinc-700
                                                     hidden [html.sidebar-mini_&]:inline-block">
                                            {{ $s['label'] }}
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
                <ul class="space-y-0.5">
                    @foreach ($restSections as $s)
                        @php
                            $current = request()->routeIs($s['route'], $s['route'].'.*');
                        @endphp
                        <li>
                            <a href="{{ $sectionRoute($s['key']) }}"
                               title="{{ $s['label'] }}"
                               @class([
                                   'group relative flex h-9 items-center gap-3 rounded-md text-sm transition-colors',
                                   'px-3 [html.sidebar-mini_&]:justify-center [html.sidebar-mini_&]:px-0',
                                   'bg-accent/15 text-accent font-semibold' => $current,
                                   'text-zinc-300 hover:bg-zinc-800 hover:text-white' => ! $current,
                               ])>
                                <flux:icon :name="$s['icon']" class="size-5 shrink-0" />
                                <span class="truncate [html.sidebar-mini_&]:hidden">{{ $s['label'] }}</span>
                                <span class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 -translate-y-1/2 whitespace-nowrap rounded-md bg-zinc-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-lg transition-opacity duration-100 group-hover:opacity-100 dark:bg-zinc-700
                                             hidden [html.sidebar-mini_&]:inline-block">
                                    {{ $s['label'] }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif

            <p class="px-2 mt-5 mb-1 text-xs font-semibold uppercase tracking-wider text-zinc-400
                      [html.sidebar-mini_&]:hidden">
                {{ __('Account') }}
            </p>
            <ul class="space-y-0.5">
                <li>
                    @php $teamCurrent = request()->routeIs('client.team'); @endphp
                    <a href="{{ route('client.team') }}"
                       title="{{ __('Team') }}"
                       @class([
                           'group relative flex h-9 items-center gap-3 rounded-md text-sm transition-colors',
                           'px-3 [html.sidebar-mini_&]:justify-center [html.sidebar-mini_&]:px-0',
                           'bg-accent/15 text-accent font-semibold' => $teamCurrent,
                           'text-zinc-300 hover:bg-zinc-800 hover:text-white' => ! $teamCurrent,
                       ])>
                        <flux:icon name="users" class="size-5 shrink-0" />
                        <span class="truncate [html.sidebar-mini_&]:hidden">{{ __('Team') }}</span>
                        <span class="pointer-events-none absolute left-full top-1/2 z-50 ml-2 -translate-y-1/2 whitespace-nowrap rounded-md bg-zinc-900 px-2 py-1 text-xs font-medium text-white opacity-0 shadow-lg transition-opacity duration-100 group-hover:opacity-100 dark:bg-zinc-700
                                     hidden [html.sidebar-mini_&]:inline-block">
                            {{ __('Team') }}
                        </span>
                    </a>
                </li>
            </ul>
        </nav>

    </aside>
</div>

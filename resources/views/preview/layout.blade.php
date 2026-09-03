{{--
    ═══════════════════════════════════════════════════════════════════════
    LEGACY RENDERER — DORMANT.  This does NOT serve live public traffic.
    Do not put SEO/markup work here without reading the note below.
    ═══════════════════════════════════════════════════════════════════════

    Two renderers exist. Which one runs is decided by a middleware flag, NOT
    by anything in these files:

        Host: branded preview FQDN / custom domain
          → App\Http\Middleware\ResolvePreviewHost  (prepended, global)
              ├── config('site.use_versioned_renderer') === true   ← CURRENT
              │     → PublicSiteController → PageRenderer
              │       → resources/views/site/page.blade.php
              │       + resources/views/site/sections/*
              └── false  (the config default)                      ← THIS FILE
                    → PreviewController::showByHost() → THIS FILE
                      + resources/views/preview/sections/*

    SITE_USE_VERSIONED_RENDERER=true in every environment, so this template currently renders NO host-routed traffic.

    It is dormant, not dead. Still reachable via:
      - the /preview/{slug} and /preview/{slug}/{page} routes
        (routes/site-public.php:9-10)
      - the rollback path if the flag is ever cleared

    WHY THIS COMMENT EXISTS: this tree is dormant while the flag is off, so
    markup added here (e.g. structured data like LocalBusiness + FAQPage
    JSON-LD) renders nowhere live and is easy to mistake for the active
    template. Anything you add here is invisible in production until the
    flag is flipped back.

    Live-traffic markup belongs in resources/views/site/page.blade.php.
--}}
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @php
            $currentContent = $pages[$currentPage ?? 'home'] ?? $pages[array_key_first($pages)] ?? [];
            $seo = $currentContent['seo'] ?? [];
            $geo = $currentContent['geo'] ?? [];
        @endphp
        <title>{{ $seo['meta_title'] ?? ($project->business_name ?? $profile['name']) . ' - Preview' }}</title>
        @if (!empty($seo['meta_description']))
            <meta name="description" content="{{ $seo['meta_description'] }}">
        @endif
        @if (!empty($geo['service_area']))
            <meta name="geo.placename" content="{{ $geo['service_area'] }}">
        @endif
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet" />
        <script src="https://cdn.tailwindcss.com"></script>
        <script defer src="/vendor/alpine.min.js"></script>
        <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
        <style>
            :root {
                --brand-primary: {{ $theme['primary_color'] ?? '#1e40af' }};
                --brand-accent: {{ $theme['accent_color'] ?? '#f59e0b' }};
@include('site.partials.site-texture-css')
            }
            [x-cloak] { display: none !important; }
            body { font-family: 'Inter', sans-serif; }
@include('site.partials.site-texture-rules')
        </style>
    </head>
    <body class="antialiased bg-white">
        {{-- Top utility bar --}}
        @if ($topBarEnabled ?? true)
        <div class="text-white text-sm" style="background-color: var(--brand-primary);">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-10">
                    <div class="flex items-center gap-4">
                        @if ($area = ($profile['geo']['service_area'] ?? null))
                            <span class="hidden sm:flex items-center gap-1.5 text-white/90">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                Serving {{ $area }}
                            </span>
                        @endif
                        @if (!empty($profile['credibility']['trade_bodies']))
                            <span class="hidden md:flex items-center gap-1.5 text-white/90">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                {{ $profile['credibility']['trade_bodies'][0] }} Registered
                            </span>
                        @endif
                    </div>
                    @if ($phone = ($profile['contact']['phones'][0] ?? null))
                        <a href="tel:{{ $phone }}" class="flex items-center gap-1.5 font-semibold text-white hover:text-white/90">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            {{ $phone }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
        @endif

        {{-- Main header --}}
        <header class="bg-white border-b border-gray-200 sticky top-0 z-50 shadow-sm">
            <nav class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                @php
                    $homeHref = ($layout ?? 'one_page') === 'multi_page'
                        ? $pageUrl('home')
                        : '#home';
                @endphp
                <div class="flex justify-between items-center h-20 md:h-24">
                    @if (!empty($logoUrl))
                        <a href="{{ $homeHref }}" class="flex items-center gap-3">
                            <img src="{{ $logoUrl }}"
                                 alt="{{ $project->business_name ?? $profile['name'] }}"
                                 class="h-14 md:h-16 w-auto object-contain max-w-[260px]">
                        </a>
                    @else
                        <a href="{{ $homeHref }}">
                            <h1 class="text-2xl font-extrabold tracking-tight" style="color: var(--brand-primary);">
                                {{ $project->business_name ?? $profile['name'] }}
                            </h1>
                        </a>
                    @endif
                    @php
                        $hasServicePages = count(array_diff($pageKeys ?? [], ['home', 'about', 'contact'])) > 0;
                        $navEnabled = ! empty($navigation['enabled']);
                        $navItems = $navigation['items'] ?? [];

                        // Auto-group: when nav is disabled and there are more than 5 pages,
                        // group service pages under a "Services" dropdown automatically.
                        $autoGroupItems = [];
                        if (! $navEnabled && count($pageKeys ?? []) > 5) {
                            $servicePages = array_diff($pageKeys ?? [], ['home', 'about', 'contact']);
                            foreach (($pageKeys ?? []) as $pk) {
                                if ($pk === 'home') {
                                    continue;
                                }
                                if (in_array($pk, ['about', 'contact'])) {
                                    $autoGroupItems[] = ['page' => $pk, 'nav_label' => ($navLabels ?? [])[$pk] ?? ucwords(str_replace('-', ' ', $pk))];
                                }
                            }
                            if (count($servicePages) > 0) {
                                $children = [];
                                foreach ($servicePages as $sp) {
                                    $children[] = [
                                        'page' => $sp,
                                        'nav_label' => ($navLabels ?? [])[$sp] ?? ucwords(str_replace('-', ' ', preg_replace('/-'.preg_quote(strtolower($project->location ?? ''), '/').'$/', '', $sp))),
                                    ];
                                }
                                // Insert Services group before contact
                                $contactItem = null;
                                $autoGroupFiltered = [];
                                foreach ($autoGroupItems as $agi) {
                                    if ($agi['page'] === 'contact') {
                                        $contactItem = $agi;
                                    } else {
                                        $autoGroupFiltered[] = $agi;
                                    }
                                }
                                $autoGroupFiltered[] = ['page' => '_group_services', 'type' => 'group', 'nav_label' => 'Services', 'children' => $children];
                                if ($contactItem) {
                                    $autoGroupFiltered[] = $contactItem;
                                }
                                $autoGroupItems = $autoGroupFiltered;
                            }
                        }
                        $useGroupedNav = $navEnabled || ! empty($autoGroupItems);
                        $groupedItems = $navEnabled ? $navItems : $autoGroupItems;
                    @endphp
                    <div class="hidden md:flex items-center space-x-8">
                        @if ($useGroupedNav)
                            @foreach ($groupedItems as $navItem)
                                @php $isGroup = ($navItem['type'] ?? '') === 'group'; @endphp
                                @if ($isGroup)
                                    {{-- Dropdown group --}}
                                    <div x-data="{ open: false }" class="relative"
                                         @mouseenter="if (window.innerWidth >= 768) open = true"
                                         @mouseleave="if (window.innerWidth >= 768) open = false">
                                        <button @click="open = !open" @click.away="open = false"
                                                class="text-sm font-medium transition-colors text-gray-600 hover:text-gray-900 flex items-center gap-1 cursor-pointer">
                                            {{ $navItem['nav_label'] }}
                                            <svg class="w-3.5 h-3.5 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                        </button>
                                        <div x-show="open" x-cloak
                                             x-transition:enter="transition ease-out duration-100"
                                             x-transition:enter-start="opacity-0 scale-95"
                                             x-transition:enter-end="opacity-100 scale-100"
                                             x-transition:leave="transition ease-in duration-75"
                                             x-transition:leave-start="opacity-100 scale-100"
                                             x-transition:leave-end="opacity-0 scale-95"
                                             class="absolute top-full left-0 mt-2 w-56 bg-white rounded-lg shadow-lg border border-gray-200 py-2 z-50">
                                            @foreach ($navItem['children'] ?? [] as $child)
                                                @php
                                                    $childHref = ($layout ?? 'one_page') === 'multi_page'
                                                        ? $pageUrl($child['page'])
                                                        : '#'.$child['page'];
                                                    $childActive = ($layout ?? 'one_page') === 'multi_page'
                                                        && ($currentPage ?? '') === $child['page'];
                                                @endphp
                                                <a href="{{ $childHref }}"
                                                   class="block px-4 py-2 text-sm transition-colors {{ $childActive ? 'text-gray-900 font-semibold bg-gray-50' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' }}">
                                                    {{ $child['nav_label'] }}
                                                </a>
                                            @endforeach
                                        </div>
                                    </div>
                                @else
                                    @php
                                        $href = ($layout ?? 'one_page') === 'multi_page'
                                            ? $pageUrl($navItem['page'])
                                            : '#'.$navItem['page'];
                                        $isActive = ($layout ?? 'one_page') === 'multi_page'
                                            && ($currentPage ?? '') === $navItem['page'];
                                    @endphp
                                    <a href="{{ $href }}"
                                       class="text-sm font-medium transition-colors {{ $isActive ? 'text-gray-900 underline underline-offset-8' : 'text-gray-600 hover:text-gray-900' }}">
                                        {{ $navItem['nav_label'] }}
                                    </a>
                                @endif
                            @endforeach
                        @else
                            @foreach (($pageKeys ?? array_keys($pages)) as $navPage)
                                {{-- Hide Home from nav when service pages exist — the logo already links home --}}
                                @continue($navPage === 'home' && $hasServicePages)
                                @php
                                    $href = ($layout ?? 'one_page') === 'multi_page'
                                        ? $pageUrl($navPage)
                                        : '#'.$navPage;
                                    $isActive = ($layout ?? 'one_page') === 'multi_page'
                                        && ($currentPage ?? '') === $navPage;
                                @endphp
                                <a href="{{ $href }}"
                                   class="text-sm font-medium transition-colors {{ $isActive ? 'text-gray-900 underline underline-offset-8' : 'text-gray-600 hover:text-gray-900' }}">
                                    {{ ($navLabels ?? [])[$navPage] ?? ucwords(str_replace('-', ' ', preg_replace('/-'.preg_quote(strtolower($project->location ?? ''), '/').'$/', '', $navPage))) }}
                                </a>
                            @endforeach
                        @endif
                    </div>
                    @if ($phone = ($profile['contact']['phones'][0] ?? null))
                        <a href="tel:{{ $phone }}"
                           class="hidden lg:flex items-center gap-2 px-5 py-2.5 rounded-md font-bold text-white text-sm shadow-md transition-all hover:shadow-lg hover:brightness-110"
                           style="background-color: var(--brand-accent);">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            {{ $phone }}
                        </a>
                    @endif

                    {{-- Mobile hamburger --}}
                    <div class="flex items-center gap-3 md:hidden" x-data="{ mobileNav: false }">
                        @if ($phone = ($profile['contact']['phones'][0] ?? null))
                            <a href="tel:{{ $phone }}" class="p-2 rounded-md" style="color: var(--brand-accent);">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            </a>
                        @endif
                        <button @click="mobileNav = !mobileNav" class="p-2 text-gray-600">
                            <svg x-show="!mobileNav" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                            <svg x-show="mobileNav" x-cloak class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>

                        {{-- Mobile nav overlay --}}
                        <div x-show="mobileNav" x-cloak
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 -translate-y-2"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             x-transition:leave="transition ease-in duration-150"
                             x-transition:leave-start="opacity-100 translate-y-0"
                             x-transition:leave-end="opacity-0 -translate-y-2"
                             @click.away="mobileNav = false"
                             class="absolute top-full left-0 right-0 bg-white shadow-lg border-t border-gray-100 z-50">
                            <div class="max-w-7xl mx-auto px-4 py-4 space-y-1">
                                @php
                                    $mobileItems = $useGroupedNav ? $groupedItems : array_map(fn($p) => ['page' => $p, 'nav_label' => ($navLabels ?? [])[$p] ?? ucwords(str_replace('-', ' ', preg_replace('/-'.preg_quote(strtolower($project->location ?? ''), '/').'$/', '', $p)))], array_filter($pageKeys ?? [], fn($p) => $p !== 'home' || !$hasServicePages));
                                @endphp
                                @foreach ($mobileItems as $mItem)
                                    @php $mIsGroup = ($mItem['type'] ?? '') === 'group'; @endphp
                                    @if ($mIsGroup)
                                        <div class="py-2">
                                            <span class="text-xs font-bold uppercase tracking-wider text-gray-400 px-3">{{ $mItem['nav_label'] }}</span>
                                            @foreach ($mItem['children'] ?? [] as $mChild)
                                                @php
                                                    $mHref = ($layout ?? 'one_page') === 'multi_page'
                                                        ? $pageUrl($mChild['page'])
                                                        : '#'.$mChild['page'];
                                                @endphp
                                                <a href="{{ $mHref }}" @click="mobileNav = false"
                                                   class="block px-6 py-2.5 text-sm text-gray-700 hover:bg-gray-50 rounded-md">
                                                    {{ $mChild['nav_label'] }}
                                                </a>
                                            @endforeach
                                        </div>
                                    @else
                                        @php
                                            $mHref = ($layout ?? 'one_page') === 'multi_page'
                                                ? $pageUrl($mItem['page'])
                                                : '#'.$mItem['page'];
                                        @endphp
                                        <a href="{{ $mHref }}" @click="mobileNav = false"
                                           class="block px-3 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 rounded-md">
                                            {{ $mItem['nav_label'] }}
                                        </a>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </nav>
        </header>

        <main>
            @foreach ($pages as $pageType => $content)
                {{-- In multi-page mode, only render the currently-requested page.
                     In one-page mode every page stacks on the single URL. --}}
                @if (($layout ?? 'one_page') === 'multi_page' && $pageType !== ($currentPage ?? ''))
                    @continue
                @endif
                <div id="{{ $pageType }}">
                    @foreach ($content as $sectionName => $sectionData)
                        @continue($pageType === 'contact' && $sectionName === 'cta')
                        {{-- contact_form is rendered inside the details section
                             as a 2-column layout, not as a standalone section --}}
                        @continue($sectionName === 'contact_form')
                        @continue($sectionName === 'seo' || $sectionName === 'geo')
                        @if (view()->exists("preview.sections.{$sectionName}"))
                            @php
                                $heroData = ($sectionName === 'hero') ? ($heroImages[$pageType] ?? null) : null;
                            @endphp
                            @include("preview.sections.{$sectionName}", [
                                'data' => $sectionData,
                                'profile' => $profile,
                                'project' => $project,
                                'pageType' => $pageType,
                                'heroSizeConfig' => $heroSizeConfig ?? [],
                                'heroImageUrl' => is_array($heroData)
                                    ? (($watermarkEnabled ?? true) && !empty($heroData['watermark_url'])
                                        ? $heroData['watermark_url']
                                        : ($heroData['url'] ?? null))
                                    : $heroData,
                                'heroPlacement' => is_array($heroData) ? $heroData : [],
                                'layout' => $layout,
                                'previewSlug' => $previewSlug,
                                'pageKeys' => $pageKeys,
                            ])
                        @endif
                    @endforeach
                </div>
            @endforeach
        </main>

        {{-- Dark footer --}}
        <footer class="bg-gray-900 text-gray-300">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-10">
                    {{-- Company info --}}
                    <div>
                        <h3 class="text-lg font-bold text-white mb-3">{{ $project->business_name ?? $profile['name'] }}</h3>
                        @if ($area = ($profile['geo']['service_area'] ?? null))
                            <p class="text-sm text-gray-400 flex items-center gap-2 mb-3">
                                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                Serving {{ $area }}
                            </p>
                        @endif
                        @if (!empty($profile['credibility']['trade_bodies']))
                            <div class="mt-4 pt-4 border-t border-gray-700">
                                @foreach ($profile['credibility']['trade_bodies'] as $body)
                                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-400 mr-3 mb-2">
                                        <svg class="w-3.5 h-3.5" style="color: var(--brand-accent);" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                        {{ $body }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Contact details --}}
                    <div>
                        <h3 class="text-lg font-bold text-white mb-3">Get In Touch</h3>
                        <div class="space-y-3">
                            @if ($phone = ($profile['contact']['phones'][0] ?? null))
                                <a href="tel:{{ $phone }}" class="flex items-center gap-2 text-sm text-gray-400 hover:text-white transition-colors">
                                    <svg class="w-4 h-4 flex-shrink-0" style="color: var(--brand-accent);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                    {{ $phone }}
                                </a>
                            @endif
                            @if ($email = ($profile['contact']['emails'][0] ?? null))
                                <a href="mailto:{{ $email }}" class="flex items-center gap-2 text-sm text-gray-400 hover:text-white transition-colors">
                                    <svg class="w-4 h-4 flex-shrink-0" style="color: var(--brand-accent);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                    {{ $email }}
                                </a>
                            @endif
                        </div>
                    </div>

                    {{-- Quick links --}}
                    <div class="md:col-span-2">
                        <h3 class="text-lg font-bold text-white mb-3">Quick Links</h3>
                        <div class="grid grid-cols-2 gap-x-6 gap-y-2">
                            @php
                                // Build flat footer links — use footer_labels from navigation config when enabled.
                                $footerLinks = [];
                                if (! empty($navigation['enabled']) && ! empty($navigation['items'])) {
                                    // Always include Home first
                                    $footerLinks[] = ['page' => 'home', 'label' => 'Home'];
                                    foreach ($navigation['items'] as $navItem) {
                                        if (($navItem['type'] ?? '') === 'group') {
                                            foreach ($navItem['children'] ?? [] as $child) {
                                                $footerLinks[] = ['page' => $child['page'], 'label' => $child['footer_label'] ?? $child['nav_label']];
                                            }
                                        } else {
                                            $footerLinks[] = ['page' => $navItem['page'], 'label' => $navItem['footer_label'] ?? $navItem['nav_label']];
                                        }
                                    }
                                } else {
                                    foreach (($pageKeys ?? array_keys($pages)) as $fp) {
                                        $footerLinks[] = ['page' => $fp, 'label' => ucwords(str_replace('-', ' ', $fp))];
                                    }
                                }
                            @endphp
                            @foreach ($footerLinks as $fl)
                                @php
                                    $footerHref = ($layout ?? 'one_page') === 'multi_page'
                                        ? $pageUrl($fl['page'])
                                        : '#'.$fl['page'];
                                @endphp
                                <a href="{{ $footerHref }}" class="text-sm text-gray-400 hover:text-white transition-colors">
                                    {{ $fl['label'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="mt-10 border-t border-gray-700 pt-6 flex flex-col sm:flex-row justify-between items-center gap-4 text-sm text-gray-500">
                    <p>&copy; {{ date('Y') }} {{ $project->business_name ?? $profile['name'] }}. All rights reserved.</p>
                    <p class="text-xs text-gray-600">Preview generated for {{ $project->business_name ?? $profile['name'] }}</p>
                </div>
            </div>
        </footer>
        <script>lucide.createIcons();</script>

        {{-- JSON-LD: LocalBusiness --}}
        @php
            $bizName = $project->business_name ?? $profile['name'] ?? '';
            $contact = $profile['contact'] ?? [];
            $geoProfile = $profile['geo'] ?? [];
            $localBusiness = [
                '@context' => 'https://schema.org',
                '@type' => 'LocalBusiness',
                'name' => $bizName,
                'description' => $seo['meta_description'] ?? '',
            ];
            if (!empty($geoProfile['service_area'])) {
                $localBusiness['areaServed'] = $geoProfile['service_area'];
            }
            if (!empty($contact['phones'][0])) {
                $localBusiness['telephone'] = $contact['phones'][0];
            }
            if (!empty($contact['emails'][0])) {
                $localBusiness['email'] = $contact['emails'][0];
            }
            if (!empty($contact['address'])) {
                $localBusiness['address'] = [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $contact['address'],
                    'addressLocality' => $project->location ?? '',
                ];
            }
            if (!empty($logoUrl)) {
                $localBusiness['logo'] = $logoUrl;
            }
            $services = $profile['services'] ?? [];
            if (!empty($services)) {
                $localBusiness['hasOfferCatalog'] = [
                    '@type' => 'OfferCatalog',
                    'name' => 'Services',
                    'itemListElement' => array_map(fn($s) => [
                        '@type' => 'Offer',
                        'itemOffered' => ['@type' => 'Service', 'name' => $s],
                    ], array_slice($services, 0, 10)),
                ];
            }

            // FAQPage schema for pages with faqs section
            $faqs = $currentContent['faqs']['items'] ?? [];
            $faqSchema = null;
            if (!empty($faqs)) {
                $faqSchema = [
                    '@context' => 'https://schema.org',
                    '@type' => 'FAQPage',
                    'mainEntity' => array_map(fn($faq) => [
                        '@type' => 'Question',
                        'name' => $faq['question'] ?? '',
                        'acceptedAnswer' => [
                            '@type' => 'Answer',
                            'text' => $faq['answer'] ?? '',
                        ],
                    ], $faqs),
                ];
            }
        @endphp
        <script type="application/ld+json">{!! json_encode($localBusiness, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
        @if ($faqSchema)
            <script type="application/ld+json">{!! json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
        @endif
        @if ($shouldShowFacebookDisclaimer ?? false)
            @include('preview.partials.legal-disclaimer')
        @endif
    </body>
</html>

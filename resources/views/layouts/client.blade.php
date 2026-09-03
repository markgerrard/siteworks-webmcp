@props(['title' => null, 'site' => null, 'width' => 'full', 'agentToolsSet' => 'portal_base'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark" style="--cp-left-dock: 0px; --cp-topbar: 3.5rem">
    <head>
        {{-- Synchronous: pin <html>.sidebar-mini and nav-group open/closed
             classes before first paint so the collapsed sidebar and group
             panels render at the stored state on navigation, without a
             flicker once Alpine hydrates.

             Nav group keys must be listed here — iterating localStorage
             prefixes is not possible pre-paint without knowing the keys.
             Matches $navGroups in layouts/client/sidebar.blade.php
             (content, shop).

             @cspNonce (with the SPACE — glued to the tag name it never compiles
             and renders as page text) because the customer surface now carries a
             strict nonce CSP; without it this script is refused and the sidebar
             flickers full→mini on every navigation. --}}
        <script @cspNonce>
            (function () {
                try {
                    if (localStorage.getItem('siteworks.client.sidebar.mini') === '1') {
                        document.documentElement.classList.add('sidebar-mini');
                    }
                    ['content', 'shop'].forEach(function (key) {
                        var stored = localStorage.getItem('siteworks.nav.' + key);
                        if (stored === '0') {
                            document.documentElement.classList.add('nav-closed-' + key);
                        }
                        if (stored === '1') {
                            document.documentElement.classList.add('nav-open-' + key);
                        }
                    });
                } catch (e) {}
            })();
        </script>
        @include('partials.head')
        @if ($title)
            <title>{{ $title }}</title>
        @endif
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        @if ($site)
            <x-shop.agent-tools-seed :site="$site" surface="portal-shop" :set="$agentToolsSet" />
        @endif
        @php
            $helpKey = 'siteworks.clientPortalHelp.collapsed';
        @endphp

        <div
            x-data="{
                mini: document.documentElement.classList.contains('sidebar-mini'),
                mobileOpen: false,
            }"
            x-init="
                $watch('mini', v => {
                    localStorage.setItem('siteworks.client.sidebar.mini', v ? '1' : '0');
                    document.documentElement.classList.toggle('sidebar-mini', v);
                });
            "
            class="flex min-h-screen w-full flex-col"
        >
            <x-cp-topbar :home="route('client.portal.landing')" :site="$site">
                <x-slot:nav-toggle>
                    <button
                        type="button"
                        x-on:click="mobileOpen = true"
                        class="lg:hidden flex h-9 w-9 items-center justify-center rounded-md text-zinc-300 hover:bg-zinc-800 cursor-pointer"
                        aria-label="Open navigation"
                    >
                        <flux:icon name="bars-3" class="size-5" />
                    </button>
                </x-slot:nav-toggle>
            </x-cp-topbar>

            <div class="flex min-h-0 w-full flex-1">
            <aside id="cp-left-dock" hidden></aside>
            @include('layouts.client.sidebar', ['site' => $site])

            <div x-data="{ helpOpen: localStorage.getItem('{{ $helpKey }}') !== '1' }"
                 x-init="$watch('helpOpen', v => localStorage.setItem('{{ $helpKey }}', v ? '0' : '1'))"
                 class="flex flex-1 min-w-0">
                <main class="flex-1 min-w-0">
@if ($width === 'page')
<x-cp-page-width :width="$width">{{ $slot }}</x-cp-page-width>
@else
                    {{ $slot }}
@endif
                </main>

                @isset($help)
                    {{-- Right help panel — sticky on desktop. --}}
                    <aside
                        x-show="helpOpen"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 lg:translate-x-2"
                        x-transition:enter-end="opacity-100 lg:translate-x-0"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 lg:translate-x-0"
                        x-transition:leave-end="opacity-0 lg:translate-x-2"
                        class="hidden lg:block w-80 shrink-0 border-s border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900"
                        x-cloak
                    >
                        <div class="sticky top-0 max-h-screen overflow-y-auto p-6">
                            <div class="flex items-center justify-between mb-3">
                                <flux:heading size="sm" class="uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                    {{ __('Help') }}
                                </flux:heading>
                                <button type="button" x-on:click="helpOpen = false"
                                        class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-100 cursor-pointer"
                                        aria-label="Hide help panel">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                </button>
                            </div>
                            <div class="space-y-5 text-sm text-zinc-700 dark:text-zinc-200">
                                {{ $help }}
                            </div>
                        </div>
                    </aside>

                    {{-- Floating "?" button to re-open help (desktop + mobile). --}}
                    <button type="button"
                            x-show="!helpOpen"
                            x-on:click="helpOpen = true"
                            class="fixed right-4 bottom-4 z-40 flex h-12 w-12 items-center justify-center rounded-full bg-accent text-accent-foreground shadow-lg hover:opacity-90 cursor-pointer"
                            aria-label="Show help panel"
                            x-cloak>
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                    </button>

                    {{-- Mobile drawer variant --}}
                    <div x-show="helpOpen"
                         x-transition.opacity
                         class="lg:hidden fixed inset-0 z-50 bg-black/40"
                         x-on:click="helpOpen = false"
                         x-cloak>
                    </div>
                    <aside x-show="helpOpen"
                           x-transition:enter="transition ease-out duration-200"
                           x-transition:enter-start="translate-x-full"
                           x-transition:enter-end="translate-x-0"
                           x-transition:leave="transition ease-in duration-150"
                           x-transition:leave-start="translate-x-0"
                           x-transition:leave-end="translate-x-full"
                           class="lg:hidden fixed top-0 right-0 z-50 w-80 max-w-[85vw] h-full bg-white dark:bg-zinc-900 border-s border-zinc-200 dark:border-zinc-700 overflow-y-auto p-6"
                           x-cloak>
                        <div class="flex items-center justify-between mb-3">
                            <flux:heading size="sm" class="uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                {{ __('Help') }}
                            </flux:heading>
                            <button type="button" x-on:click="helpOpen = false"
                                    class="text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-100 cursor-pointer"
                                    aria-label="Close help">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            </button>
                        </div>
                        <div class="space-y-5 text-sm text-zinc-700 dark:text-zinc-200">
                            {{ $help }}
                        </div>
                    </aside>
                @endisset

                <x-cp-assistant-panel />
            </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts(['nonce' => \Illuminate\Support\Facades\Vite::cspNonce()])
    </body>
</html>

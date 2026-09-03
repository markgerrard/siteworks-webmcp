@props([
    'home' => null,
    'search' => false,
    'fixed' => false,
    'brandPane' => false,
    'site' => null,
])

@php
    $user = auth()->user();
    $home = $home ?? ($user?->isStaff()
        ? route('dashboard')
        : route('client.portal.landing'));
    $contextSite = null;
    if ($site instanceof \App\Models\Site) {
        $contextSite = $site;
    } elseif ($user?->isStaff()) {
        $maybeSite = request()->route('site');
        $contextSite = $maybeSite instanceof \App\Models\Site ? $maybeSite : null;
    }
@endphp

<header
    role="banner"
    data-cp-topbar
    data-flux-header
    {{ $attributes->class(($fixed ? 'fixed inset-x-0 top-0' : 'sticky top-0').' z-40 grid h-14 grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-stretch border-b border-zinc-800 bg-zinc-950 text-white dark:border-zinc-800 dark:bg-zinc-950') }}
>
    <div class="flex min-w-0 items-center justify-self-start self-stretch w-full">
        {{-- Brand pane: on sidebar layouts it spans exactly the sidebar's
             w-64 with its own end border, so the topbar reads as two panes
             aligned with the columns below it. --}}
        <div class="flex h-full shrink-0 items-center gap-2 px-3 {{ $brandPane ? 'lg:w-64 lg:border-e lg:border-zinc-800 lg:px-4' : '' }}">
            {{ $navToggle ?? '' }}

            <a
                href="{{ $home }}"
                wire:navigate
                data-cp-brand
                class="flex min-w-0 items-center gap-2"
            >
                <img
                    src="/images/sw-mark-dark.png"
                    alt="{{ config('app.name') }}"
                    class="h-7 w-auto shrink-0"
                >
                <span class="font-display truncate text-base font-extrabold leading-none tracking-tight text-white">
                    SiteWorks
                </span>
            </a>
        </div>

        @if ($contextSite)
            <div class="min-w-0 ps-4 pe-3">
                <x-cp-site-context :site="$contextSite" />
            </div>
        @endif
    </div>

    <div class="flex w-full max-w-xl items-center justify-self-center px-3">
    </div>

    <div class="flex items-center justify-end gap-1 justify-self-end pe-3 ps-3">
        @if ($contextSite)
            <x-cp-site-actions :site="$contextSite" />
            <div class="mx-1.5 h-5 w-px shrink-0 bg-zinc-800" aria-hidden="true"></div>
        @endif
        {{-- WebMCP site-tools pill (agent-view.js) mounts here instead of floating over the sidebar. --}}
        <div data-cp-status-slot class="flex items-center"></div>
        <x-cp-assistant-panel toggle />
        <x-desktop-user-menu placement="topbar" :name="auth()->user()->name" />
    </div>
</header>

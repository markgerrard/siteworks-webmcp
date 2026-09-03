@props([
    'site',
    'title',
    'width' => 'full',
    'agentToolsSet' => 'portal_base',
])

<x-layouts::app :title="$site->business_name" :width="$width">
    <x-shop.agent-tools-seed :site="$site" :set="$agentToolsSet" />
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
        {{-- Title only. Site name, status chip, View site, and Edit Site
             live in the agents top bar (x-cp-site-context). --}}
        <div class="flex items-center justify-between gap-4" data-cp-page-header>
            <flux:heading size="xl">{{ $title }}</flux:heading>
            @if (isset($primaryActions) || $site->status === \App\Enums\SiteStatus::Draft)
                <div class="flex items-center gap-3 flex-shrink-0">
                    {{ $primaryActions ?? '' }}
                    @if ($site->status === \App\Enums\SiteStatus::Draft)
                        @can('startPipeline', $site)
                            <form method="POST" action="{{ route('sites.start', $site) }}">
                                @csrf
                                <flux:button variant="primary" type="submit" icon="play" size="sm">
                                    {{ __('Start Pipeline') }}
                                </flux:button>
                            </form>
                        @endcan
                    @endif
                </div>
            @endif
        </div>

        @if (session('success'))
            <flux:callout variant="success" icon="check-circle">
                {{ session('success') }}
            </flux:callout>
        @endif

        {{-- Live pipeline status (only while running) --}}
        @if ($site->status !== \App\Enums\SiteStatus::Draft && $site->status !== \App\Enums\SiteStatus::Review && $site->status !== \App\Enums\SiteStatus::Published)
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <livewire:site-status :siteId="$site->id" />
            </div>
        @endif

        {{-- Sticky notice when the draft has unpublished changes. Invisible
             otherwise. Auto-hides immediately after publish fires (via the
             composition-published event). --}}
        @if (config('site.use_versioned_renderer') && $site->latestPreview)
            <livewire:site.unpublished-changes-banner :site-id="$site->id" />
        @endif

        <div class="w-full">
            {{ $slot }}
        </div>

        <div class="text-right">
            <flux:button variant="ghost" :href="route('sites.index')" icon="arrow-left">
                {{ __('Back to sites') }}
            </flux:button>
        </div>
    </div>
</x-layouts::app>

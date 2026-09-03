<x-layouts::client :title="__('Your sites')" width="full">
    <x-slot:help>
        <x-help.section icon="folder" title="Your sites">
            All the SiteWorks sites your team manages. Pick one to open its
            Pages, Design, Domain, and other settings.
        </x-help.section>

        <div class="space-y-3">
            <x-help.action icon="cursor-arrow-rays" label="Pick a site">
                Click any card to open its management area.
            </x-help.action>
            <x-help.action icon="arrows-right-left" label="Switch any time">
                The site switcher at the top of the sidebar lets you jump
                between sites without coming back here.
            </x-help.action>
        </div>

        <x-help.tip>
            Need another site? Talk to your account manager — we'll spin one
            up and add it to your team.
        </x-help.tip>
    </x-slot:help>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <flux:heading size="xl">{{ __('Your sites') }}</flux:heading>
        <flux:subheading>{{ __('Pick a site to manage.') }}</flux:subheading>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($sites as $site)
                <a href="{{ route('client.portal.site', $site) }}"
                   class="block rounded-xl border border-neutral-200 p-6 hover:border-accent dark:border-neutral-700 dark:hover:border-accent transition-colors">
                    <flux:heading size="lg" class="truncate">{{ $site->business_name }}</flux:heading>
                    <flux:subheading class="truncate">
                        {{ $site->business_type ?? '—' }} &middot; {{ $site->location ?? '—' }}
                    </flux:subheading>
                    @if ($site->status->value !== 'review')
                        <div class="mt-3">
                            <flux:badge size="sm" :color="match($site->status->value) {
                                'draft' => 'zinc',
                                'published' => 'green',
                                'failed' => 'red',
                                default => 'blue',
                            }">{{ ucfirst($site->status->value) }}</flux:badge>
                        </div>
                    @endif
                </a>
            @endforeach
        </div>
    </div>
</x-layouts::client>

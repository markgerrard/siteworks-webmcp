<x-layouts::client :title="__('Business Info').' — '.$site->business_name" :site="$site" width="page">
    <x-slot:help>
        <x-help.section icon="briefcase" title="Business Info">
            Phone, email, address, areas you cover. Updating it here updates
            every page on your site automatically.
        </x-help.section>

        <div class="space-y-3">
            <x-help.action icon="identification" label="Contact Details">
                Shown in the header, footer, and contact sections.
            </x-help.action>
            <x-help.action icon="map-pin" label="Geographic Scope">
                The areas you serve. Powers the chatbot's "do you cover X?"
                answers and location-based pages.
            </x-help.action>
        </div>

        <x-help.tip icon="magnifying-glass">
            Keep these accurate — Google reads them too. Consistent details
            help your local search rankings.
        </x-help.tip>
    </x-slot:help>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <flux:heading size="xl">{{ __('Business Info') }}</flux:heading>

        @if ($site->businessProfile)
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading size="lg" class="mb-4">{{ __('Contact Details') }}</flux:heading>
                <flux:separator class="mb-4" />
                <livewire:contact-editor :siteId="$site->id" />
            </div>

            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading size="lg" class="mb-4">{{ __('Geographic Scope') }}</flux:heading>
                <flux:separator class="mb-4" />
                <livewire:site-scope :siteId="$site->id" />
            </div>
        @else
            <flux:callout variant="warning" icon="exclamation-triangle">
                {{ __('Your site profile is still being prepared. Check back shortly.') }}
            </flux:callout>
        @endif
    </div>
</x-layouts::client>

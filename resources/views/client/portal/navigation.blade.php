<x-layouts::client :title="__('Navigation').' — '.$site->business_name" :site="$site" width="page">
    <x-slot:help>
        <x-help.section icon="bars-3" title="Navigation">
            The links your customers see at the top of every page. Reorder,
            rename, or hide them here.
        </x-help.section>

        <div class="space-y-3">
            <x-help.action icon="arrows-up-down" label="Drag to reorder">
                Change the order they appear in your nav.
            </x-help.action>
            <x-help.action icon="link" label="Add a custom link">
                Point at an external page or a specific section.
            </x-help.action>
            <x-help.action icon="arrow-path" label="Auto-updating">
                Page links update themselves when you rename a page.
            </x-help.action>
        </div>

        <x-help.tip>
            Keep it short — 4–6 items reads cleanly on mobile.
        </x-help.tip>
    </x-slot:help>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <flux:heading size="xl">{{ __('Navigation') }}</flux:heading>
        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <livewire:nav-manager :siteId="$site->id" />
        </div>
    </div>
</x-layouts::client>

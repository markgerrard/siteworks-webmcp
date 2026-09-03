<x-layouts::client :title="__('History').' — '.$site->business_name" :site="$site" width="full">
    <x-slot:help>
        <x-help.section icon="clock" title="Version History">
            Every publish saves a snapshot. If something breaks — a typo,
            a wrong layout, an accidental delete — you can roll back.
        </x-help.section>

        <div class="space-y-3">
            <x-help.action icon="eye" label="Preview">
                Opens that version in a new tab. Nothing changes — it's
                just a look.
            </x-help.action>
            <x-help.action icon="arrow-uturn-left" label="Restore">
                Replaces your current draft with that version. Publish to
                make it live.
            </x-help.action>
        </div>

        <x-help.tip icon="shield-check">
            Restore is non-destructive — the version you roll back from
            stays in the list. You can always go forward again.
        </x-help.tip>
    </x-slot:help>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <flux:heading size="xl">{{ __('Version History') }}</flux:heading>
        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <livewire:site.version-history :site-id="$site->id" />
        </div>
    </div>
</x-layouts::client>

<x-layouts::client :title="__('Categories').' — '.$site->business_name" :site="$site" width="full" agent-tools-set="sandbox">
    <x-slot:help>
        <x-help.section icon="squares-2x2" title="Categories">
            Keep category names short and familiar — they're the aisles
            visitors browse.
        </x-help.section>
    </x-slot:help>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <flux:heading size="xl">{{ __('Categories') }}</flux:heading>

        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <livewire:shop.category-manager
                :site-id="$site->id"
                edit-route="client.portal.shop.products.edit"
                list-route="client.portal.shop.products"
            />
        </div>
    </div>
</x-layouts::client>

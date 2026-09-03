<x-layouts::client :title="__('Products').' — '.$site->business_name" :site="$site" width="full" agent-tools-set="sandbox">
    <x-slot:help>
        <x-help.section icon="cube" title="Products">
            Edit catalogue items here — name, description and whether
            they are on sale.
        </x-help.section>
    </x-slot:help>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <flux:heading size="xl">{{ __('Products') }}</flux:heading>

        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <livewire:shop.products-list
                :site-id="$site->id"
                edit-route="client.portal.shop.products.edit"
                export-route="client.portal.shop.products.export"
            />
        </div>
    </div>
</x-layouts::client>

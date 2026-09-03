<x-layouts::client :title="__('Edit product').' — '.$site->business_name" :site="$site" width="page" agent-tools-set="sandbox">
    <x-slot:help>
        <x-help.section icon="pencil-square" title="Edit product">
            Change the name, description and category, then save. Publish
            when it's ready to show on the shop.
        </x-help.section>

        <x-help.tip icon="arrow-left">
            Use Back to shop when you're done — that's the catalogue, not
            the public site.
        </x-help.tip>
    </x-slot:help>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <livewire:shop.product-editor
            :site-id="$site->id"
            :product-id="$product->id"
            list-route="client.portal.shop.products"
            orders-route="client.portal.orders"
        />
    </div>
</x-layouts::client>

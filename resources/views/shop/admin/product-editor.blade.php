<x-layouts::app width="full">
    <x-save-bar />
    <div class="p-4">
        <div data-save-bar="save">
            <livewire:shop.product-editor :site-id="$siteId" :product-id="$productId" />
        </div>
    </div>
    <x-shop.agent-tools-seed :site="$site" />
</x-layouts::app>

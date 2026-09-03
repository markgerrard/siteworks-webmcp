<x-layouts::app width="page">
    @isset($site)
        <x-shop.agent-tools-seed :site="$site" set="portal_base" />
    @endisset
    <livewire:shop.order-detail :site-id="$siteId" :order-id="$orderId" />
</x-layouts::app>

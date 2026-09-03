<x-layouts::client :title="__('Order').' — '.$site->business_name" :site="$site" width="page">
    <x-slot:help>
        <x-help.section icon="inbox-stack" title="Order">
            Customer, items and totals for this order. Dispatch and
            refund actions sit on the same page.
        </x-help.section>

        <x-help.tip icon="pencil-square">
            Internal notes are only visible to you — the customer never
            sees them.
        </x-help.tip>
    </x-slot:help>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <flux:button variant="ghost" :href="route('client.portal.orders', $site)" icon="arrow-left">
            {{ __('Back to orders') }}
        </flux:button>
        <livewire:shop.order-detail :site-id="$site->id" :order-id="$order->id" />
    </div>
</x-layouts::client>

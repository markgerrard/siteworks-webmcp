<x-layouts::client :title="__('Orders').' — '.$site->business_name" :site="$site" width="full">
    <x-slot:help>
        <x-help.section icon="inbox-stack" title="Orders">
            Paid orders land here, newest first. Open one to see the
            items, address and payment.
        </x-help.section>

        <div class="space-y-3">
            <x-help.action icon="truck" label="Dispatch">
                Mark an order shipped when it leaves, and add a tracking
                number if you have one.
            </x-help.action>
        </div>

        <x-help.tip icon="clock">
            Shipped and cancelled orders stay on the list so you can look
            one up later.
        </x-help.tip>
    </x-slot:help>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <flux:heading size="xl">{{ __('Orders') }}</flux:heading>
        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <livewire:shop.orders-list
                :site-id="$site->id"
                route-name="client.portal.orders.show"
            />
        </div>
    </div>
</x-layouts::client>

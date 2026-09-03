<x-layouts::client :title="__('Storefront').' — '.$site->business_name" :site="$site" width="page" agent-tools-set="sandbox">
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <flux:heading size="xl">{{ __('Storefront') }}</flux:heading>

        @php
            $shippingRate = \App\Models\Shop\ShippingRate::query()->where('site_id', $site->id)->first();
            $shippingStrategy = match ($shippingRate?->strategy) {
                'weight_tiers' => __('Weight tiers'),
                'flat_with_free_threshold' => __('Flat rate'),
                default => null,
            };
        @endphp
        @if ($shippingStrategy)
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Shipping') }}: {{ $shippingStrategy }}</p>
        @endif

        <livewire:shop.fulfilment-editor :site-id="$site->id" />
    </div>
</x-layouts::client>

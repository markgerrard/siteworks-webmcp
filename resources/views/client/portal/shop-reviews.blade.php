<x-layouts::client :title="__('Reviews').' — '.$site->business_name" :site="$site" width="full" agent-tools-set="sandbox">
    <x-slot:help>
        <x-help.section icon="star" title="Reviews">
            Approve, hide or remove product reviews for this shop.
        </x-help.section>
    </x-slot:help>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <flux:heading size="xl">{{ __('Reviews') }}</flux:heading>

        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <livewire:shop.product-reviews-moderation :site-id="$site->id" />
        </div>
    </div>
</x-layouts::client>

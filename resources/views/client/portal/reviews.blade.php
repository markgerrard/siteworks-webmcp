<x-layouts::client :title="__('Reviews').' — '.$site->business_name" :site="$site" width="full">
    <x-slot:help>
        <x-help.section icon="star" title="Reviews">
            Reviews visitors leave on your website land here first. Nothing
            goes live until you approve it.
        </x-help.section>

        <div class="space-y-3">
            <x-help.action icon="check-circle" label="Approve">
                Publishes the review to your live site within a few minutes.
            </x-help.action>
            <x-help.action icon="x-circle" label="Reject">
                Keeps the review off your site. Rejected reviews stay in
                the Rejected list for your records — if you change your
                mind, ask your account manager to restore one.
            </x-help.action>
        </div>

        <x-help.tip icon="shield-check">
            Only approved reviews are ever shown to visitors.
        </x-help.tip>
    </x-slot:help>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <flux:heading size="xl">{{ __('Reviews') }}</flux:heading>
        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <livewire:client.review-moderation :site-id="$site->id" />
        </div>
    </div>
</x-layouts::client>

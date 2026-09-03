<x-layouts::client :title="__('Enquiries').' — '.$site->business_name" :site="$site" width="full">
    <x-slot:help>
        <x-help.section icon="inbox" title="Enquiries">
            Every quote or contact form submitted on your website is saved
            here, newest first — even if the notification email goes astray.
        </x-help.section>

        <div class="space-y-3">
            <x-help.action icon="envelope" label="Reply">
                Click the email address to reply directly from your own
                mail app.
            </x-help.action>
        </div>

        <x-help.tip icon="clock">
            Fast replies win work — most customers go with the first
            tradesperson who gets back to them.
        </x-help.tip>
    </x-slot:help>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <flux:heading size="xl">{{ __('Enquiries') }}</flux:heading>
        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <livewire:client.enquiries-inbox :site-id="$site->id" />
        </div>
    </div>
</x-layouts::client>

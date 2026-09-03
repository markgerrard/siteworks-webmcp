<x-layouts::client :title="__('Team')" width="page">
    <x-slot:help>
        <x-help.section icon="users" title="Team">
            Invite colleagues to help manage your SiteWorks account. Everyone
            on your team gets full edit access.
        </x-help.section>

        <div class="space-y-3">
            <x-help.action icon="paper-airplane" label="Invite">
                Sends a one-time link to set their password. Full access on
                first sign-in.
            </x-help.action>
            <x-help.action icon="arrow-path" label="Resend">
                Useful if the original invite email got lost.
            </x-help.action>
            <x-help.action icon="user-minus" label="Remove">
                Revokes access immediately. They can be re-invited later.
            </x-help.action>
        </div>

        <x-help.tip>
            You can't remove yourself — get a teammate to do it, or contact
            support.
        </x-help.tip>
    </x-slot:help>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <flux:heading size="xl">{{ __('Team') }}</flux:heading>
        <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700 max-w-2xl">
            <livewire:customer.team />
        </div>
    </div>
</x-layouts::client>

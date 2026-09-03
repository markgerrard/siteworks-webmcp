<x-layouts::client :title="__('Your Account')" width="page">
    <x-slot:help>
        <x-help.section icon="user" title="Your Account">
            Your personal landing page. The full profile editor — name,
            email, password — is on its way.
        </x-help.section>

        <div class="space-y-3">
            <x-help.action icon="phone" label="Need a change now?">
                Contact your account manager — they can update name, email,
                or reset your password.
            </x-help.action>
            <x-help.action icon="users" label="Manage other users">
                Head to <strong>Team</strong> in the sidebar to invite,
                resend, or remove teammates.
            </x-help.action>
        </div>
    </x-slot:help>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl p-6">
        <flux:heading size="xl">{{ __('Welcome, :name', ['name' => auth()->user()->name]) }}</flux:heading>
        <flux:subheading>
            {{ __("You're signed in to :app.", ['app' => config('app.name')]) }}
        </flux:subheading>
        <p class="text-sm text-neutral-500 dark:text-neutral-400 max-w-prose">
            {{ __('Your profile editor is coming soon. Reach out to your account manager if you need anything urgently.') }}
        </p>
    </div>
</x-layouts::client>

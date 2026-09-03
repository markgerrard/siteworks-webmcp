<x-layouts::auth :title="__('Staff Area')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Welcome, ') . auth()->user()->name" :description="__('You are signed in to the staff area.')" />

        <form method="POST" action="{{ route('agent.logout') }}">
            @csrf
            <flux:button variant="ghost" type="submit" class="w-full">
                {{ __('Sign out') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>

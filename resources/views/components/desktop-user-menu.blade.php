@props([
    'name' => null,
    'placement' => 'sidebar',
])

<flux:dropdown position="bottom" align="{{ $placement === 'topbar' ? 'end' : 'start' }}">
    @if ($placement === 'topbar')
        <button
            type="button"
            class="flex max-w-full cursor-pointer items-center gap-2 rounded-md px-1.5 py-1 text-sm text-zinc-100 hover:bg-zinc-800"
            data-test="sidebar-menu-button"
        >
            <flux:avatar
                :name="auth()->user()->name"
                :initials="auth()->user()->initials()"
                size="xs"
            />
            {{-- First name only pre-dropdown; the menu header shows the full
                 name + email once opened. --}}
            <span class="hidden max-w-[10rem] truncate sm:inline">{{ \Illuminate\Support\Str::before(trim($name ?? auth()->user()->name), ' ') }}</span>
            <flux:icon name="chevron-down" class="size-3 shrink-0 text-zinc-400" />
        </button>
    @else
        <flux:sidebar.profile
            :name="auth()->user()->name"
            :initials="auth()->user()->initials()"
            icon:trailing="chevrons-up-down"
            data-test="sidebar-menu-button"
        />
    @endif

    <flux:menu>
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <flux:avatar
                :name="auth()->user()->name"
                :initials="auth()->user()->initials()"
            />
            <div class="grid flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
            </div>
        </div>
        <flux:menu.separator />
        <flux:menu.radio.group>
            {{-- profile.edit lives in routes/settings.php which is only
                 wired into the agents surface today. Gate the Settings
                 link so this shared menu doesn't 500 when rendered on
                 the customer portal. Follow-up: add a customer-side
                 profile route so clients can edit their own account. --}}
            @if (\Illuminate\Support\Facades\Route::has('profile.edit'))
                <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                    {{ __('Settings') }}
                </flux:menu.item>
            @endif
            @php
                $logoutRoute = request()->getHost() === config('domains.agent_domain')
                    ? route('agent.logout')
                    : route('logout');
            @endphp
            <form method="POST" action="{{ $logoutRoute }}" class="w-full">
                @csrf
                <flux:menu.item
                    as="button"
                    type="submit"
                    icon="arrow-right-start-on-rectangle"
                    class="w-full cursor-pointer"
                    data-test="logout-button"
                >
                    {{ __('Log out') }}
                </flux:menu.item>
            </form>
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>

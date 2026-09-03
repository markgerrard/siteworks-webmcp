<?php

use App\Models\Client;
use App\Models\User;
use App\Services\Customer\InviteClientUser;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    #[Validate('required|email|max:255')]
    public string $inviteEmail = '';

    public ?string $flash = null;

    public function mount(): void
    {
        // Defensive: client.only middleware already enforces isClientUser
        // so this never fires for staff. The check is kept so the
        // component is safe to mount in tests / direct calls without the
        // middleware in front.
        abort_unless(auth()->user()?->isClientUser(), 403);
    }

    /**
     * Protected: only ever called internally (render + invite/remove
     * helpers). Public visibility made it a remotely callable Livewire
     * action returning serialized User models.
     */
    protected function team(): \Illuminate\Database\Eloquent\Collection
    {
        // Public methods on a Livewire component are remotely callable actions, so
        // a mount-only check leaves this read unguarded on every later request.
        // client_id is nullable: without the check, a user whose client_id had been
        // cleared would receive the STAFF user list instead of their own team.
        abort_unless(auth()->user()?->isClientUser(), 403);

        return User::where('client_id', auth()->user()->client_id)
            ->orderBy('id')
            ->get();
    }

    public function sendInvite(InviteClientUser $invite): void
    {
        $this->validate();

        $client = auth()->user()->client;
        abort_unless($client, 403);

        $invite($this->inviteEmail, $client, auth()->user());

        $this->flash = "Invite sent to {$this->inviteEmail}.";
        $this->reset('inviteEmail');
    }

    public function resendInvite(int $userId): void
    {
        $user = $this->team()->firstWhere('id', $userId);
        abort_unless($user, 404);

        $token = Password::broker()->createToken($user);
        $user->notify(new \App\Notifications\Customer\ClientUserInvited(
            token: $token,
            clientName: auth()->user()->client->name,
            invitedByName: auth()->user()->name,
        ));

        $this->flash = "Invite resent to {$user->email}.";
    }

    public function removeMember(int $userId): void
    {
        if ($userId === auth()->id()) {
            $this->flash = "You can't remove yourself from the team.";
            return;
        }

        $user = $this->team()->firstWhere('id', $userId);
        abort_unless($user, 404);

        $user->delete();

        $this->flash = "{$user->email} removed from the team.";
    }

    /**
     * Protected computed property instead of a with() method: with() is
     * a remotely callable Livewire action whose return value (User rows)
     * would be JSON-encoded into the response. #[Computed] + protected
     * keeps it render-only.
     */
    #[Computed]
    protected function members(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->team();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl">Team</flux:heading>
        <flux:subheading>
            People who can sign in and manage your sites.
        </flux:subheading>
    </div>

    @if ($flash)
        {{-- wire:key bound to flash content forces Livewire to re-mount the
             div on each new flash so x-init's timeout fires again rather
             than reusing the stale (already-hidden) instance. --}}
        <div wire:key="flash-{{ md5($flash) }}"
             x-data="{ show: true }"
             x-init="setTimeout(() => show = false, 4000)"
             x-show="show"
             x-transition:leave="transition ease-in duration-300"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="rounded-md border border-accent/40 bg-accent/10 px-4 py-3 text-sm text-accent-foreground dark:text-zinc-100">
            {{ $flash }}
        </div>
    @endif

    {{-- Invite form --}}
    <form wire:submit="sendInvite" class="flex flex-col gap-3">
        <flux:input
            wire:model="inviteEmail"
            label="Invite a teammate"
            type="email"
            placeholder="colleague@yourcompany.co.uk"
            autocomplete="off"
            required
        />
        <flux:button variant="primary" type="submit" class="self-start">
            Send invite
        </flux:button>
    </form>

    {{-- Member list --}}
    <div class="flex flex-col gap-3">
        <flux:heading size="lg">Current team</flux:heading>

        <ul class="divide-y divide-zinc-200 rounded-md border border-zinc-200 dark:divide-neutral-700 dark:border-neutral-700">
            @foreach ($this->members as $member)
                <li class="flex items-center justify-between gap-3 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="truncate text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                {{ $member->name }}
                            </span>
                            @if ($member->id === auth()->id())
                                <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-zinc-600 dark:bg-neutral-800 dark:text-zinc-300">
                                    You
                                </span>
                            @endif
                            {{-- "Pending" = invited but never logged in. We use
                                 last_login_at as the proof-of-acceptance
                                 signal because email_verified_at is set at
                                 invite time (the email click proves ownership). --}}
                            @if (! $member->last_login_at)
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                                    Pending
                                </span>
                            @endif
                        </div>
                        <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $member->email }}
                        </div>
                    </div>

                    @if ($member->id !== auth()->id())
                        <div class="flex items-center gap-2">
                            @if (! $member->last_login_at)
                                <flux:button
                                    wire:click="resendInvite({{ $member->id }})"
                                    size="sm"
                                    variant="subtle"
                                >
                                    Resend
                                </flux:button>
                            @endif
                            <x-confirm-button
                                name="remove-customer-member-{{ $member->id }}"
                                size="sm"
                                triggerVariant="danger"
                                triggerLabel="Remove"
                                title="Remove team member?"
                                description="{{ $member->email }} will lose access to the portal immediately."
                                confirmLabel="Remove"
                                confirmVariant="danger"
                                wire:click="removeMember({{ $member->id }})"
                            />
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</div>

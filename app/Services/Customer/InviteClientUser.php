<?php

namespace App\Services\Customer;

use App\Models\Client;
use App\Models\User;
use App\Notifications\Customer\ClientUserInvited;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteClientUser
{
    /**
     * Invite a new colleague to manage the inviter's client. Creates the
     * User row up front (so the password-reset broker has something to
     * tokenise against), then sends the custom invite notification.
     *
     * Behaviour notes:
     * - The user is pre-created with email_verified_at = now() because
     *   clicking the invite-email link itself proves inbox ownership.
     * - The initial password is a random 64-char string the recipient
     *   will never see — they set their own via the reset-password flow
     *   the notification links to.
     * - Email collisions are surfaced as ValidationException so the
     *   Livewire form can display them without a 500.
     */
    public function __invoke(
        string $email,
        Client $client,
        User $invitedBy,
        ?string $name = null,
    ): User {
        $email = mb_strtolower(trim($email));

        $existing = User::withTrashed()->where('email', $email)->first();
        if ($existing) {
            // Active user — refuse. The email belongs to someone with a
            // live account; even if they're on a different client we
            // require explicit removal there before re-inviting, so we
            // don't yank an active session out from under them.
            if ($existing->deleted_at === null) {
                $message = $existing->client_id === $client->id
                    ? 'This email is already on your team.'
                    : 'A user with this email already exists.';
                // Both invite forms (agents.client-team + customer.team)
                // bind the input to property `inviteEmail`. The error key
                // MUST match that property so Flux's auto-error rendering
                // surfaces the message — using `email` here lands the
                // message on a field that doesn't exist in the Livewire
                // component, so the UI stays silent. Service tests only
                // assert message text, not key.
                throw ValidationException::withMessages(['inviteEmail' => $message]);
            }

            // Soft-deleted — restore + reassign to this client. The
            // users.email unique index is full (not partial-on-deleted)
            // so a fresh forceCreate would hit a DB UNIQUE violation;
            // restoring keeps audit history (Site.created_by_user_id /
            // assigned_to_user_id references still resolve).
            // We forceFill rather than forceUpdate to keep the existing
            // password hash — they'll set a new one via the invite link
            // anyway, and forcing a new hash now invalidates any
            // already-in-flight reset tokens for the prior account.
            $user = $existing;
            $user->restore();
            $user->forceFill([
                'name' => $name ?: ($user->name ?: Str::of($email)->before('@')->headline()),
                'client_id' => $client->id,
                'email_verified_at' => now(),
                'last_login_at' => null,  // back to "Pending" state in the UI
                'role' => null,           // any prior staff role is gone — re-invited as client-only
            ])->save();
        } else {
            // forceCreate bypasses the User #[Fillable] attribute which
            // only allows name/email/password. We're a trusted server-side
            // caller setting client_id / email_verified_at
            // deliberately. Leaving role NULL — that's how isClientUser()
            // identifies a client-side user (role IS NULL && client_id IS
            // NOT NULL).
            $user = User::forceCreate([
                'name' => $name ?: Str::of($email)->before('@')->headline(),
                'email' => $email,
                'password' => Hash::make(Str::random(64)),
                'email_verified_at' => now(),
                'client_id' => $client->id,
            ]);
        }

        $token = Password::broker()->createToken($user);

        $user->notify(new ClientUserInvited(
            token: $token,
            clientName: $client->name,
            invitedByName: $invitedBy->name,
        ));

        return $user;
    }
}

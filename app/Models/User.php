<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AgentRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => AgentRole::class,
            'role_synced_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === AgentRole::Admin;
    }

    public function isAgent(): bool
    {
        return $this->role === AgentRole::Agent;
    }

    public function isManager(): bool
    {
        return $this->role === AgentRole::Manager;
    }

    public function isSeniorManager(): bool
    {
        return $this->role === AgentRole::SeniorManager;
    }

    public function isClientUser(): bool
    {
        return $this->role === null && $this->client_id !== null;
    }

    /**
     * Any staff role (agent, manager, senior_manager, admin).
     *
     * Use this for "can this user see staff UI / hit staff routes" checks.
     * Use the specific isAgent() / isAdmin() / etc when you need to
     * differentiate between staff tiers (e.g. admin-only actions).
     */
    public function isStaff(): bool
    {
        return $this->role !== null;
    }

    public function client(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    public function accessibleSites(): \Illuminate\Database\Eloquent\Builder
    {
        // Admins, managers, and senior managers see all sites.
        if ($this->isAdmin() || $this->isManager() || $this->isSeniorManager()) {
            return Site::query();
        }

        // Agents see only sites they created or are assigned to.
        if ($this->isAgent()) {
            return Site::where(fn ($q) => $q
                ->where('created_by_user_id', $this->id)
                ->orWhere('assigned_to_user_id', $this->id));
        }

        // Client users are scoped to their own client. Guard against a
        // null client_id: without the null check, every site where
        // client_id IS NULL would be visible to an unassigned client
        // user (a security review).
        if ($this->client_id === null) {
            return Site::whereRaw('1 = 0');
        }

        return Site::where('client_id', $this->client_id);
    }

    public function sites(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Site::class, 'created_by_user_id');
    }

    public function assignedSites(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Site::class, 'assigned_to_user_id');
    }

    /** Super-admin = admin plus membership of the env allowlist (`domains.super_admin_allowlist`). */
    public function isSuperAdmin(): bool
    {
        if (! $this->isAdmin()) {
            return false;
        }

        $allowed = array_filter(array_map('trim', explode(',', (string) config('domains.super_admin_allowlist', ''))));

        if ($allowed === []) {
            return false;
        }

        $identifiers = array_filter([
            $this->upn ? strtolower($this->upn) : null,
            $this->email ? strtolower($this->email) : null,
        ]);

        foreach ($allowed as $candidate) {
            if (in_array(strtolower($candidate), $identifiers, strict: true)) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace Database\Factories;

use App\Enums\AgentRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            // Default to a client user (role=null). Use ->staff() for staff users.
            'role' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Indicate that the user is an admin (staff role: admin).
     *
     * Composes staff() so production realities (null password, SSO metadata)
     * apply. Previously this state only flipped `role` which drifted from
     * the real post-Phase-7 shape. Tests that want a pre-SSO-style admin
     * with a usable password must set 'password' explicitly.
     */
    public function admin(): static
    {
        return $this->staff(AgentRole::Admin);
    }

    /**
     * Stable UPN used by super-admin factory rows so tests can configure the
     * domains.super_admin_allowlist to match.
     */
    public const SUPER_ADMIN_UPN = 'super.admin@test.example';

    /**
     * Indicate that the user is a super-admin. Tests that rely on super-admin
     * behaviour must also set domains.super_admin_allowlist to include SUPER_ADMIN_UPN.
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => AgentRole::Admin,
            'upn' => self::SUPER_ADMIN_UPN,
        ]);
    }

    /**
     * Indicate that the user is a staff member (SSO-authenticated role).
     *
     * Password is null — staff authenticate via SSO only.
     */
    public function staff(AgentRole $role = AgentRole::Agent): static
    {
        return $this->state(fn () => [
            'role' => $role,
            'microsoft_id' => fake()->uuid(),
            'upn' => fake()->unique()->safeEmail(),
            'tenant_id' => fake()->uuid(),
            'password' => null,
        ]);
    }
}

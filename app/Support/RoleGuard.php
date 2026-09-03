<?php

namespace App\Support;

use App\Models\User;

/**
 * The role predicates behind the Livewire guard traits.
 *
 * Extracted so the traits' hydrate hooks and the EnforceRoleGuards component hook
 * share one implementation — they are enforced at two different points in the
 * request precisely because neither point alone always runs.
 */
class RoleGuard
{
    public static function assertStaff(): void
    {
        $user = self::user();

        abort_unless($user !== null && $user->isStaff() && ! $user->trashed(), 403);
    }

    public static function assertAdmin(): void
    {
        $user = self::user();

        abort_unless($user !== null && $user->isAdmin() && ! $user->trashed(), 403);
    }

    public static function assertSuperAdmin(): void
    {
        $user = self::user();

        abort_unless($user !== null && $user->isSuperAdmin() && ! $user->trashed(), 403);
    }

    private static function user(): ?User
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user;
    }
}

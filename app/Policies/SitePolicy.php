<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;

class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Site $site): bool
    {
        return $this->belongsToUser($user, $site);
    }

    public function create(User $user): bool
    {
        return $this->isStaff($user);
    }

    public function update(User $user, Site $site): bool
    {
        return $this->belongsToUser($user, $site);
    }

    public function startPipeline(User $user, Site $site): bool
    {
        return $this->isStaff($user) && $this->belongsToUser($user, $site);
    }

    public function delete(User $user, Site $site): bool
    {
        return $this->isStaff($user) && $this->belongsToUser($user, $site);
    }

    private function isStaff(User $user): bool
    {
        return $user->isStaff();
    }

    private function belongsToUser(User $user, Site $site): bool
    {
        // Managers, senior managers, and admins can act on any site.
        if ($user->isManager() || $user->isSeniorManager() || $user->isAdmin()) {
            return true;
        }

        // Agents can only act on sites they created or are assigned to.
        // Staff access is scoped by site assignment, not by role alone.
        if ($user->isAgent()) {
            return $site->created_by_user_id === $user->id
                || $site->assigned_to_user_id === $user->id;
        }

        // Client users are scoped to their client.
        return $user->client_id !== null && $user->client_id === $site->client_id;
    }
}

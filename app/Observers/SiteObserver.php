<?php

namespace App\Observers;

use App\Models\Site;
use App\Services\Site\PublicPageCache;

class SiteObserver
{
    public function creating(Site $site): void
    {
        // Only seed the creator/assignee columns for staff users. If a client
        // user creates a site (e.g. self-serve onboarding), seeding their id
        // here would make the site invisible to every agent — agent scope is
        // "created_by OR assigned_to = me", which would never match a client.
        $user = auth()->user();
        if ($user && $user->role !== null) {
            $site->created_by_user_id ??= $user->id;
            $site->assigned_to_user_id ??= $user->id;
        }
    }

    /** Invalidates cached public pages when live-rendered fields change. */
    public function updated(Site $site): void
    {
        // Both fields are read live while rendering published compositions,
        // so neither changes the version_id embedded in the HTML cache key.
        if ($site->wasChanged(['shop_enabled', 'design_brief'])) {
            app(PublicPageCache::class)->invalidate($site);
        }

    }
}

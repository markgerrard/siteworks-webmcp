<?php

namespace App\Livewire\Concerns;

use App\Models\Site;

trait AuthorizesSiteAccess
{
    /**
     * Find the site by siteId and verify the authenticated user
     * is authorized to access it (agent or matching client).
     *
     * Protected: every consumer calls this internally on $this. Public
     * visibility made it a remotely callable Livewire action returning
     * the full Site model (agent-only columns included) as JSON on
     * every component using this trait.
     */
    protected function findAuthorizedSite(): ?Site
    {
        $site = Site::find($this->siteId);

        if (! $site) {
            return null;
        }

        $user = auth()->user();

        if (! $user) {
            return null;
        }

        if ($this->userCanAccessSite($user, $site)) {
            return $site;
        }

        return null;
    }

    private function userCanAccessSite($user, Site $site): bool
    {
        if ($user->isAdmin() || $user->isManager() || $user->isSeniorManager()) {
            return true;
        }

        if ($user->isAgent()) {
            return $site->created_by_user_id === $user->id
                || $site->assigned_to_user_id === $user->id;
        }

        return $user->client_id !== null && $user->client_id === $site->client_id;
    }

    /**
     * Like findAuthorizedSite(), but FAIL-CLOSED: aborts 403 instead of
     * returning null. Use whenever the caller doesn't branch on the
     * result — a bare findAuthorizedSite() statement silently continues
     * into the mutation on denial (the exact shape of the Sev-1 IDOR
     * fixed in 8141493).
     *
     * Protected for the same reason as findAuthorizedSite(): every
     * consumer calls it internally on $this, and public visibility made
     * it a remotely callable Livewire action returning the full Site
     * model (agent-only columns included) as JSON.
     */
    protected function assertAuthorizedSiteAccess(): Site
    {
        $site = $this->findAuthorizedSite();

        abort_unless($site !== null, 403);

        return $site;
    }

    /**
     * Fail closed on a flag-off site: 404, never a silent no-op.
     */
    protected function abortUnlessShopEnabled(): Site
    {
        $site = $this->assertAuthorizedSiteAccess();
        abort_unless($site->shopEnabled(), 404);

        return $site;
    }

    /**
     * Owed-after-payment surfaces (order fulfilment, refunds): reachable while the site has
     * orders, whether or not the flag is on.
     */
    protected function abortUnlessShopEstablished(): Site
    {
        $site = $this->assertAuthorizedSiteAccess();
        abort_unless($site->shopEnabled() || $site->hasEstablishedShop(), 404);

        return $site;
    }

    /**
     * Assert the authenticated user can access the given Site model,
     * aborting with 403 if not. For use in full-page Livewire components
     * that receive a bound Site model rather than a siteId integer.
     *
     * Protected: only ever called internally on $this. Public visibility
     * exposed it as a Livewire action whose Site parameter is implicitly
     * bound from a caller-supplied id — an authorization oracle at best.
     */
    protected function authorizeSiteAccess(Site $site): void
    {
        $user = auth()->user();

        abort_unless($user && $this->userCanAccessSite($user, $site), 403);
    }
}

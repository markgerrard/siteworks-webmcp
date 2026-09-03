<?php

namespace App\Services\Site\Editor;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/** Queued work is re-authorised against the current actor and site state. */
final class QueuedJobAuthorization
{
    /**
     * Why this run is no longer authorised, or null if it still is.
     */
    public function denialFor(?int $actorUserId, Site $site, ActorChannel $channel): ?string
    {
        if ($actorUserId === null) {
            return null;
        }

        $actor = User::find($actorUserId);

        if ($actor === null) {
            return 'actor_missing';
        }

        if (! Gate::forUser($actor)->check('update', $site)) {
            return 'policy';
        }

        if (! app(AgentToolsGate::class)->enabledFor($actor, $channel)) {
            return 'gate';
        }

        return null;
    }

    /**
     * denialFor() plus the run-time EXPOSURE re-check (spec § 8, ruling R1): a site's exposure set
     * can be revoked between enqueue and execution, and a queued job never re-enters
     * EditorOperations::run(), so this is where the paid internal-only operations
     * (generate_hero_video, render_preview) re-authorise. Agent channels only — the ui channel is
     * never exposure-gated — and a null actor stays a system dispatch with nothing to re-check.
     */
    public function denialForOperation(?int $actorUserId, Site $site, ActorChannel $channel, string $operation): ?string
    {
        $denial = $this->denialFor($actorUserId, $site, $channel);

        if ($denial !== null || $actorUserId === null) {
            return $denial;
        }

        if ($channel->isAgent() && ! app(ToolExposure::class)->exposes($site, $operation)) {
            return 'exposure';
        }

        return null;
    }

    /** A named initiator must still exist and retain access to the site. */
    public function accessDenialFor(?int $userId, Site $site): ?string
    {
        if ($userId === null) {
            return null;
        }

        $user = User::find($userId);

        if ($user === null) {
            return 'actor_missing';
        }

        return Gate::forUser($user)->check('update', $site) ? null : 'policy';
    }

    /** Throw when a queued run is no longer authorised. */
    public function assert(?int $actorUserId, Site $site, ActorChannel $channel): void
    {
        $denial = $this->denialFor($actorUserId, $site, $channel);

        if ($denial !== null) {
            throw new QueuedJobDenied($denial);
        }
    }

    /** Legacy initiators are checked for current site access. */
    public function assertInitiator(?int $initiatedByUserId, Site $site): void
    {
        if ($initiatedByUserId === null) {
            return;
        }

        $denial = $this->accessDenialFor($initiatedByUserId, $site);

        if ($denial !== null) {
            throw new QueuedJobDenied($denial);
        }
    }
}

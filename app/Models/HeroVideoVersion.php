<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * One row per successful home-hero-video generation (and per user upload /
 * composite chain in v1.2). Mirrors the HeroVersion table for images.
 *
 * Activation invariant: at most one row per site has is_active=true. Use
 * activate() to flip — it transactionally clears the prior active row and
 * mirrors this version's s3_key onto the site's home_hero_video_path so
 * the public template (which reads that one column) immediately serves
 * the chosen clip without an S3 copy.
 */
class HeroVideoVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        's3_key',
        'prompt',
        'provider',
        'resolution',
        'duration_secs',
        'source',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Public CDN URL for the clip. */
    public function url(): string
    {
        return Storage::disk('s3')->url($this->s3_key);
    }

    /**
     * Make THIS version the active one. Idempotent on the same row.
     *
     * Concurrent activations for the same site (two composite jobs, or a
     * generate + composite racing) MUST be serialised — otherwise the
     * "at most one active row per site" invariant can be violated, and
     * downstream Site columns (home_hero_video_path, home_hero_scene)
     * can drift out of sync with whichever row is actually active.
     *
     * We take a lockForUpdate() on the parent Site row at the top of
     * the transaction; every concurrent activation queues behind it.
     *
     * `extraSiteUpdates` lets a caller fold additional site columns into
     * the same locked transaction — used by CompositeHeroVideoJob to
     * write `home_hero_scene` atomically with the activation, so the
     * scene `composite_video_id` always points at the version that's
     * actually active.
     *
     * @param  array<string, mixed>  $extraSiteUpdates
     */
    public function activate(array $extraSiteUpdates = []): void
    {
        DB::transaction(function () use ($extraSiteUpdates) {
            // Lock the parent Site row first so any concurrent activate()
            // for this site queues behind us.
            Site::whereKey($this->site_id)->lockForUpdate()->first();

            // Always issue the UPDATE — never short-circuit on the in-memory
            // is_active flag. If the row was loaded as active before another
            // concurrent activation deactivated it (in between hydrate and
            // lock acquisition), trusting the stale flag here would skip the
            // re-mark and leave the site with NO active row even though we
            // just deactivated everything else. Use a row-scoped UPDATE so
            // we don't depend on Eloquent dirty-tracking either.
            //
            // Keyed only on id, never on is_active: a concurrently
            // deactivated row still matches and reports 1. A zero is then
            // either "row gone" or "row already active" (MySQL counts
            // changed rows, not matched rows). exists() tells those two
            // zeros apart. Mark before deactivating others so a deleted
            // row cannot clear the live version on the way out.
            $marked = HeroVideoVersion::whereKey($this->id)->update(['is_active' => true]);
            if ($marked === 0 && ! HeroVideoVersion::whereKey($this->id)->exists()) {
                Log::warning('Hero video activate skipped; version row was deleted.', [
                    'hero_video_version_id' => $this->id,
                    'site_id' => $this->site_id,
                    's3_key' => $this->s3_key,
                ]);

                return;
            }

            HeroVideoVersion::where('site_id', $this->site_id)
                ->where('id', '!=', $this->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $this->is_active = true;

            Site::whereKey($this->site_id)->update(array_merge([
                'home_hero_video_path' => $this->s3_key,
                'home_hero_video_provider' => $this->provider,
                'home_hero_video_tier' => $this->resolution,
                'home_hero_video_prompt' => $this->prompt,
                'home_hero_video_status' => 'ready',
            ], $extraSiteUpdates));
        });
    }
}

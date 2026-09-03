<?php

namespace App\Services\Site;

use App\Models\HeroVersion;
use App\Models\Site;
use App\Services\PreviewSnapshotWriter;
use App\Services\Site\Editor\DraftAssetSelections;
use Illuminate\Support\Facades\DB;

/**
 * Central activation helper for HeroVersion rows.
 *
 * Satisfies the partial unique index `hero_versions_one_active_per_page`
 * (see 2026_04_21_204900_add_partial_unique_indexes_for_active_flags)
 * by serialising the deactivate-existing + insert-new pair under a
 * single transaction with a lockForUpdate on the (site, page_type)
 * rows.
 *
 * Every code path that creates or activates a hero version must go
 * through here. Direct HeroVersion::create([..., is_active => true])
 * is not safe — a concurrent writer could race the deactivate and
 * the insert would fail the unique constraint.
 */
class HeroVersionService
{
    /**
     * Create a new HeroVersion and make it the active one for
     * (site_id, page_type, slot). Deactivates any prior active sibling with
     * the same slot in the same transaction.
     *
     * @param  array<string, mixed>  $attributes  Row attributes minus site_id + page_type + slot + is_active.
     * @param  string  $slot  'hero' (default), 'intro', 'band', 'band_2', or 'band_3'
     */
    public function activate(int $siteId, string $pageType, array $attributes, string $slot = 'hero'): HeroVersion
    {
        $this->assertKnownSlot($slot);

        return $this->withActivationLock($siteId, $pageType, $slot, function () use ($siteId, $pageType, $attributes, $slot): HeroVersion {
            $this->deactivateSlot($siteId, $pageType, $slot);
            $this->clearDraftSelectionsForActivation($siteId, $pageType, $slot);

            return HeroVersion::create(array_merge($attributes, [
                'site_id' => $siteId,
                'page_type' => $pageType,
                'slot' => $slot,
                'is_active' => true,
            ]));
        });
    }

    /**
     * Make an existing HeroVersion the active one for its
     * (site_id, page_type, slot). Shares the same advisory lock as
     * activate() so generation, upload, and manual selection serialise.
     */
    public function activateExisting(HeroVersion $version): HeroVersion
    {
        $this->assertKnownSlot($version->slot);

        return $this->withActivationLock(
            $version->site_id,
            $version->page_type,
            $version->slot,
            function () use ($version): HeroVersion {
                $this->deactivateSlot($version->site_id, $version->page_type, $version->slot);
                $this->clearDraftSelectionsForActivation($version->site_id, $version->page_type, $version->slot);

                $version->update(['is_active' => true]);

                return $version->refresh();
            },
        );
    }

    /**
     * Activate an existing row and record the snapshot/revision/cache
     * effects under the same per-slot advisory lock. Recording failure
     * rolls the flip back.
     */
    public function activateExistingAndRecord(HeroVersion $version, Site $site, ?int $userId = null): HeroVersion
    {
        $this->assertKnownSlot($version->slot);

        return $this->withActivationLock(
            $version->site_id,
            $version->page_type,
            $version->slot,
            function () use ($version, $site, $userId): HeroVersion {
                $this->deactivateSlot($version->site_id, $version->page_type, $version->slot);

                $version->update(['is_active' => true]);
                $activated = $version->refresh();
                $this->recordActivation($site, $activated, $userId);

                return $activated;
            },
        );
    }

    /**
     * Tail shared by picker + page-manager activation: mirror the hero
     * slot into preview.snapshot.hero_images, then bump admin_revision
     * (which also invalidates PublicPageCache). Intro/band skip the
     * mirror because PageRenderer reads those slots live from
     * hero_versions.
     */
    public function recordActivation(Site $site, HeroVersion $version, ?int $userId = null): void
    {
        self::mirrorIntoSnapshot($site, $version);
        app(CompositionService::class)->bumpAdminRevision($site, $userId);

        // Canonical lock order is site_drafts → selection rows (publish + discard both do that), so the
        // picker's draft-selection cleanup runs AFTER the admin bump — never before it (40P01 cycle).
        $this->clearDraftSelectionsForActivation($site->id, $version->page_type, $version->slot);
    }

    public static function mirrorIntoSnapshot(Site $site, HeroVersion $version): void
    {
        if ($version->slot !== 'hero') {
            return;
        }

        $preview = $site->latestPreview;
        if (! $preview) {
            return;
        }

        app(PreviewSnapshotWriter::class)->mutate($preview, function (&$snapshot) use ($version) {
            $snapshot['hero_images'][$version->page_type] = [
                'url' => $version->url,
                'watermark_url' => $version->watermark_url,
                'prompt' => $version->prompt,
                'model' => $version->model,
                ...$version->placement ?? [],
            ];
        });
    }

    /**
     * @return array{int, int}
     */
    public static function activationLockKey(int $siteId, string $pageType, string $slot): array
    {
        $hash = crc32($pageType."\0".$slot);
        if ($hash > 0x7FFFFFFF) {
            $hash -= 0x100000000;
        }

        return [$siteId, $hash];
    }

    private function assertKnownSlot(string $slot): void
    {
        if (! in_array($slot, ['hero', 'intro', 'band', 'band_2', 'band_3'], true)) {
            throw new \InvalidArgumentException("HeroVersionService expects slot 'hero', 'intro', 'band', 'band_2', or 'band_3', got '{$slot}'.");
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withActivationLock(int $siteId, string $pageType, string $slot, callable $callback): mixed
    {
        return DB::transaction(function () use ($siteId, $pageType, $slot, $callback) {
            $this->acquireActivationLock($siteId, $pageType, $slot);

            return $callback();
        });
    }

    private function acquireActivationLock(int $siteId, string $pageType, string $slot): void
    {
        // Postgres transaction-scoped advisory lock keyed on
        // (site_id, crc32(page_type . NUL . slot)). Released automatically on
        // commit or rollback. Serialises concurrent activate() /
        // activateExisting() calls even when no prior active row exists
        // for the key (empty-set case where ->lockForUpdate() on
        // SELECT/UPDATE can't grab a row-level lock). Also portable across
        // the INSERT path — a plain Laravel ->lockForUpdate()->update()
        // drops the lock clause in the UPDATE compiler (the Grammar only
        // emits FOR UPDATE on SELECT), so that pattern would not have
        // serialised at all.
        // pg_advisory_xact_lock(int, int) takes signed int32 args,
        // but crc32 returns uint32. Sign-extend the high bit so a
        // hash like "about" (0xB5F7DBA3 = 3_052_675_811) fits.
        // The public demo runs SQLite; skip the Postgres-only lock.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'SELECT pg_advisory_xact_lock(?, ?)',
            self::activationLockKey($siteId, $pageType, $slot),
        );
    }

    private function deactivateSlot(int $siteId, string $pageType, string $slot): void
    {
        HeroVersion::where('site_id', $siteId)
            ->where('page_type', $pageType)
            ->where('slot', $slot)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    private function clearDraftSelectionsForActivation(int $siteId, string $pageType, string $slot): void
    {
        $site = Site::query()->findOrFail($siteId);
        $selections = app(DraftAssetSelections::class);
        $selections->clearMatching($site, 'hero', $pageType, $slot);

        if ($pageType === 'home' && $slot === 'hero') {
            $selections->clearHeroVideo($site);
        }
    }
}

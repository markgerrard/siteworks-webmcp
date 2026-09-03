<?php

namespace App\Services\Site;

use App\Enums\PageStatus;
use App\Enums\ProjectItemStatus;
use App\Exceptions\Site\FirstPublishRequiredException;
use App\Exceptions\Site\PageStateException;
use App\Exceptions\Site\SitePublishException;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\HeroVideoVersion;
use App\Models\LogoConcept;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteDraftAssetSelection;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\DraftAssetSelections;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class SitePublishService
{
    public function __construct(
        protected CompositionService $composition,
        protected CompositionDefaults $defaults,
        protected PublicPageCache $pageCache,
    ) {}

    /**
     * Atomic site-wide publish.
     *   - For every page with a draft revision: pin draft, flip pointer published := draft, clear draft
     *   - For every page without a draft: pin its existing published_revision_id
     *   - Insert new site_versions row with the current draft composition + pinned page_revisions
     *   - Update site_versions_current pointer
     */
    public function publishSite(
        Site $site,
        ?string $publishNote = null,
        ?int $userId = null,
        ?ActorChannel $channel = null,
        ?PublishLockContext $locks = null,
    ): SiteVersion
    {
        $this->assertPublishablePurpose($site);

        return DB::transaction(function () use ($site, $publishNote, $userId, $channel, $locks) {
            $ctx = $locks ?? $this->lockForPublish($site);
            $site = $ctx->site;
            $draft = $ctx->draft;
            $initialSelections = $ctx->selections;

            // Only pages with status=Published are eligible for the new version.
            // Draft + Archived pages are retained in generated_pages but excluded
            // from the pinned revisions and from the nav (see composition prune
            // below). Changing a page's status therefore takes effect on the
            // next publish, not immediately — the currently-pinned SiteVersion
            // remains the source of truth for public reachability until swapped.
            $pages = GeneratedPage::where('site_id', $site->id)
                ->published()
                ->lockForUpdate()
                ->get();

            if ($pages->isEmpty()) {
                throw new SitePublishException("Cannot publish site {$site->id} — no pages with status=Published.");
            }

            $this->promoteDraftAssetSelections($site, $initialSelections, $userId);

            // Resolve composition from the locked draft; fall back to defaults
            // if this is a first-publish (no draft row yet).
            $composition = $draft?->composition ?? $this->defaults->forSite($site);
            if (! $draft) {
                $draft = SiteDraft::create([
                    'site_id' => $site->id,
                    'composition' => $composition,
                    'updated_at' => now(),
                ]);
            }

            // Prune nav of any items referencing pages not in the published set.
            // Covers admin-driven transitions (Published → Draft/Archived after
            // the nav entry was added) and any stale historical entries.
            $publishedPageIds = $pages->pluck('id')->map(fn ($i) => (int) $i)->all();
            $composition = $this->pruneNavToPublishedPages($composition, $publishedPageIds);

            // Sync the pruned composition back to the draft row in the same
            // transaction. Without this, hasPendingComposition() keeps
            // returning true after a successful publish (draft != published
            // for removed nav items) and the unpublished-changes banner
            // gets stuck on forever. Source=System so the admin_revision
            // guard isn't tripped by a publish cleaning up its own draft.
            $this->syncDraftComposition($draft, $composition);

            $pageRevisions = $this->buildPinsForPages($pages);

            $version = $this->insertVersion(
                $site,
                $composition,
                $pageRevisions,
                $userId,
                $publishNote,
                $channel?->value,
            );

            $this->flipDraftRevisionPointers($pages);

            // Flip pinned ProjectItem rows from Draft to Published.
            //
            // Without this transition, items stay at status=Draft even
            // after their revision is pinned in a SiteVersion. The
            // GenerateProjectsPageJob deletion query
            // (source=ai_generated AND status=draft) then catches them
            // on the next regen, and ProjectItemObserver::deleting()
            // refuses to hard-delete a pinned item — fatal-erroring
            // projects-page regen on every published site.
            //
            // Walk every revision pinned in this version, collect the
            // item_ids referenced inside content_data.sections[], and
            // promote any still-Draft rows.
            // Tenancy: every ProjectItem query inside promotePinnedProjectItems
            // is scoped by site_id. The ids come out of revision content_data,
            // which editor actions populate from client-supplied arrays — an id
            // belonging to another tenant that reaches a revision must never be
            // promoted, snapshotted or reverted by THIS site's publish.
            $this->promotePinnedProjectItems($site, $pageRevisions);

            // Snapshot per-item content / media hashes into the pinned
            // page revisions so the "Edited since publish" drift badge
            // in project-item-card has a baseline to compare against.
            // Pre-fix, the badge was inoperative
            // for every item because GenerateProjectsPageJob wrote
            // empty arrays to published_content_hashes / published_media_hashes
            // and nothing else populated them.
            $this->snapshotPublishedHashesIntoRevisions($site, $pageRevisions);

            $this->pointCurrentVersion($site, $version);

            // Promote any pending hero-scene draft to live. Encoding:
            //   { "enabled": true,  ...payload }  → live = payload
            //   { "enabled": false }              → live = null (scene off)
            //   null                              → no draft, leave live untouched
            if ($site->home_hero_scene_draft !== null) {
                $draftScene = $site->home_hero_scene_draft;
                $enabled = (bool) ($draftScene['enabled'] ?? false);
                $live = null;
                if ($enabled) {
                    $live = $draftScene;
                    unset($live['enabled']);
                }
                $site->forceFill([
                    'home_hero_scene' => $live,
                    'home_hero_scene_draft' => null,
                ])->save();
            }

            // Drop any public-page HTML cached against the previous version.
            // Key space includes a per-site counter we bump here; future reads
            // compute a fresh key so stale HTML can't surface.
            $this->pageCache->invalidate($site);

            // Favicon + OG render from the EFFECTIVE palette (brief +
            // composition theme overrides), so a publish that recolours the
            // site must refresh them — content-hash filenames make this a
            // cheap no-op when the palette is unchanged. Dispatched after
            // commit so the job sees the new current version.
            \App\Jobs\GenerateBrandImagesJob::dispatch($site)->afterCommit();

            return $version;
        });
    }

    /**
     * Publish a single page onto a new SiteVersion composed from the
     * current published pins. Refuses if the site has never been published.
     *
     * Sibling drafts are left untouched. Optional $delta is applied to
     * both the new version composition and site_drafts.composition.
     */
    public function publishSinglePage(Site $site, GeneratedPage $page, ?CompositionDelta $delta = null, ?int $userId = null): SiteVersion
    {
        $this->assertPublishablePurpose($site);

        return DB::transaction(function () use ($site, $page, $delta, $userId) {
            [$draft, $lockedPage, $published] = $this->lockForSinglePageMutation($site, $page);

            $pinnedId = $lockedPage->draft_revision_id ?? $lockedPage->published_revision_id;
            if (! $pinnedId) {
                throw new SitePublishException("Page {$lockedPage->id} ({$lockedPage->page_type}) has no published or draft revision; cannot publish.");
            }

            $newPin = ['page_id' => (int) $lockedPage->id, 'revision_id' => (int) $pinnedId];
            $pageRevisions = $this->mergePin($published->page_revisions ?? [], $newPin);

            $publishedComposition = $published->composition ?? [];
            $versionComposition = $delta ? $delta->apply($publishedComposition) : $publishedComposition;
            $draftComposition = $delta ? $delta->apply($draft->composition ?? []) : ($draft->composition ?? []);

            $this->syncDraftComposition($draft, $draftComposition);

            $version = $this->insertVersion($site, $versionComposition, $pageRevisions, $userId);

            $updates = [
                'status' => PageStatus::Published,
                'archived_at' => null,
            ];
            if ($lockedPage->draft_revision_id) {
                $updates['published_revision_id'] = $lockedPage->draft_revision_id;
                $updates['draft_revision_id'] = null;
            }
            $lockedPage->update($updates);

            $this->promotePinnedProjectItems($site, [$newPin]);
            $this->snapshotPublishedHashesIntoRevisions($site, [$newPin]);

            $this->pointCurrentVersion($site, $version);
            DB::afterCommit(fn () => $this->pageCache->invalidate($site));

            return $version->refresh();
        });
    }

    /**
     * Unpublish a single page: new version without its pin, status → Draft,
     * optional delta strips its composition links. Public reachability
     * follows the new pin set (removed slug 404s).
     */
    public function removePageFromVersion(Site $site, GeneratedPage $page, ?CompositionDelta $delta = null, ?int $userId = null): SiteVersion
    {
        return DB::transaction(function () use ($site, $page, $delta, $userId) {
            [$draft, $lockedPage, $published] = $this->lockForSinglePageMutation($site, $page);

            $homepageId = $published->composition['homepage_page_id'] ?? null;
            if ($homepageId !== null && (int) $homepageId === (int) $lockedPage->id) {
                throw new PageStateException("Cannot unpublish the homepage of site {$site->id}.");
            }

            $pageRevisions = $this->dropPin($published->page_revisions ?? [], (int) $lockedPage->id);
            if ($pageRevisions === []) {
                throw new PageStateException("Cannot unpublish the last remaining published page of site {$site->id}.");
            }

            $remainingPageIds = array_map(fn (array $pin) => (int) $pin['page_id'], $pageRevisions);

            $publishedComposition = $published->composition ?? [];
            $versionComposition = $delta ? $delta->remove($publishedComposition) : $publishedComposition;
            $draftComposition = $delta ? $delta->remove($draft->composition ?? []) : ($draft->composition ?? []);

            // Version: drop every nav item that is no longer pinned (ghost
            // page items resolve to href='/' on the public site).
            // Draft: only strip the removed page (+ its group children).
            // Unpublished sibling draft entries must survive.
            $versionComposition = $this->pruneNavToPublishedPages($versionComposition, $remainingPageIds);
            $draftComposition = $this->pruneNavEntriesForPage($draftComposition, (int) $lockedPage->id);

            $this->syncDraftComposition($draft, $draftComposition);

            $version = $this->insertVersion($site, $versionComposition, $pageRevisions, $userId);

            $lockedPage->update(['status' => PageStatus::Draft]);

            $this->pointCurrentVersion($site, $version);
            DB::afterCommit(fn () => $this->pageCache->invalidate($site));

            return $version->refresh();
        });
    }

    /**
     * Roll back the live site to a previously published version.
     *
     * - Throws PageStateException if $target doesn't belong to $site.
     * - No-op if $target is already the current version.
     * - Flips site_versions_current.version_id.
     * - For each pinned {page_id, revision_id}: sets published_revision_id and mirrors
     *   revision content_data into generated_pages.content_data for legacy renderer.
     *
     * @throws PageStateException
     */
    public function rollbackToVersion(Site $site, SiteVersion $target): void
    {
        if ($target->site_id !== $site->id) {
            throw new PageStateException("Version {$target->id} does not belong to site {$site->id}.");
        }

        DB::transaction(function () use ($site, $target): void {
            $current = SiteVersionCurrent::where('site_id', $site->id)->lockForUpdate()->first();

            // Idempotent: already live — nothing to do.
            if ($current && $current->version_id === $target->id) {
                return;
            }

            // Guard: assert every pinned revision still exists before any writes.
            // Prune may have removed old revisions; a missing revision would cause
            // an FK violation mid-transaction. Fail cleanly with a descriptive error.
            foreach ($target->page_revisions as $pin) {
                $pageId = $pin['page_id'] ?? null;
                $revisionId = $pin['revision_id'] ?? null;

                if (! $pageId || ! $revisionId) {
                    continue;
                }

                if (! PageRevision::where('id', $revisionId)->exists()) {
                    $page = GeneratedPage::find($pageId);
                    $pageType = $page?->page_type ?? "page {$pageId}";
                    throw new PageStateException(
                        "Cannot rollback to v{$target->version}: revision {$revisionId} for {$pageType} has been pruned."
                    );
                }
            }

            foreach ($target->page_revisions as $pin) {
                $pageId = $pin['page_id'] ?? null;
                $revisionId = $pin['revision_id'] ?? null;

                if (! $pageId || ! $revisionId) {
                    continue;
                }

                $page = GeneratedPage::find($pageId);
                if (! $page) {
                    continue;
                }

                $revision = PageRevision::find($revisionId);

                $page->update([
                    'published_revision_id' => $revisionId,
                    'content_data' => $revision?->content_data ?? $page->content_data,
                ]);
            }

            SiteVersionCurrent::updateOrCreate(
                ['site_id' => $site->id],
                ['version_id' => $target->id, 'updated_at' => now()],
            );

            // Rollback flips the pointer to a prior version; drop any cached
            // HTML keyed against the just-superseded version.
            $this->pageCache->invalidate($site);
        });
    }

    /**
     * Video-only sites feed the VideoWorks promo pipeline; a publish
     * would give them a live public presence they must never have.
     */
    private function assertPublishablePurpose(Site $site): void
    {
        if ($site->purpose !== \App\Enums\SitePurpose::Website) {
            throw new SitePublishException("Site {$site->id} is video-only and cannot be published.");
        }
    }

    /**
     * Pre-publish summary used by the publish modal.
     *
     * @return array{pending_pages: array<int, array{page_id: int, page_type: string, label: string, last_edited_at: string|null}>, composition_pending: bool, pending_asset_selections: list<array{family: string, page_type: string, slot: string, version_id: int|null, url: string|null, mode: string|null}>, next_version: int}
     */
    public function publishSummary(Site $site): array
    {
        $pages = GeneratedPage::where('site_id', $site->id)
            ->whereNull('archived_at')
            ->whereNotNull('draft_revision_id')
            ->get(['id', 'page_type', 'nav_label', 'updated_at']);

        $nextVersion = (SiteVersion::where('site_id', $site->id)->max('version') ?? 0) + 1;
        $pendingAssetSelections = $this->pendingAssetSelections($site);

        return [
            'pending_pages' => $pages->map(fn ($p) => [
                'page_id' => $p->id,
                'page_type' => $p->page_type,
                'label' => $p->nav_label ?: $p->page_type,
                'last_edited_at' => $p->updated_at?->toIso8601String(),
            ])->toArray(),
            'composition_pending' => $this->composition->hasPendingComposition($site),
            'pending_asset_selections' => $pendingAssetSelections,
            'next_version' => $nextVersion,
        ];
    }

    /**
     * @param  Collection<int, \App\Models\Site\SiteDraftAssetSelection>  $selections
     */
    private function acquireDraftAssetSelectionLocks(Site $site, Collection $selections): void
    {
        $heroKeys = $selections
            ->whereIn('family', ['hero', 'hero_video'])
            ->map(fn ($selection) => [
                'page_type' => $selection->page_type,
                'slot' => $selection->slot,
            ])
            ->unique(fn (array $key) => $key['page_type']."\0".$key['slot'])
            ->sort(fn (array $left, array $right) => [$left['page_type'], $left['slot']] <=> [$right['page_type'], $right['slot']]);

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach ($heroKeys as $key) {
                DB::statement(
                    'SELECT pg_advisory_xact_lock(?, ?)',
                    HeroVersionService::activationLockKey($site->id, $key['page_type'], $key['slot']),
                );
            }

            if ($selections->contains('family', 'logo')) {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [$site->id]);
            }
        }
    }

    /**
     * Test seam reached after all asset advisory locks and before the sites row.
     */
    protected function afterAdvisoryLocks(Site $site): void {}

    /**
     * Canonical publish lock order: asset advisory locks → sites → site_drafts → generated_pages.
     *
     * The caller owns the transaction; this method never opens its own. The
     * returned context carries the selection snapshot the advisory locks were
     * taken for — re-reading later could promote a key that was never locked.
     */
    public function lockForPublish(Site $site): PublishLockContext
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('lockForPublish() must be called inside a transaction.');
        }

        $selections = app(DraftAssetSelections::class)->all($site);
        $this->acquireDraftAssetSelectionLocks($site, $selections);
        $this->afterAdvisoryLocks($site);

        $lockedSite = Site::whereKey($site->id)->lockForUpdate()->first() ?? $site;
        $draft = SiteDraft::where('site_id', $site->id)->lockForUpdate()->first();

        GeneratedPage::where('site_id', $site->id)
            ->published()
            ->lockForUpdate()
            ->get();

        return new PublishLockContext(
            site: $lockedSite,
            draft: $draft,
            selections: $selections,
        );
    }

    /**
     * @param  Collection<int, \App\Models\Site\SiteDraftAssetSelection>  $initialSelections
     */
    private function promoteDraftAssetSelections(
        Site $site,
        Collection $initialSelections,
        ?int $userId,
    ): void {
        $lockedKeys = $initialSelections
            ->mapWithKeys(fn ($selection) => [$this->draftAssetSelectionKey(
                $selection->family,
                $selection->page_type,
                $selection->slot,
            ) => true]);

        $currentSelections = app(DraftAssetSelections::class)->all($site)
            ->filter(fn ($selection) => $lockedKeys->has($this->draftAssetSelectionKey(
                $selection->family,
                $selection->page_type,
                $selection->slot,
            )));

        foreach ($currentSelections as $selection) {
            $promoted = match ($selection->family) {
                'hero' => $this->promoteHeroSelection($site, $selection),
                'hero_video' => $this->promoteHeroVideoSelection($site, $selection),
                'logo' => $this->promoteLogoSelection($site, $selection, $userId),
                default => false,
            };

            if ($promoted) {
                app(DraftAssetSelections::class)->clearMatching(
                    $site,
                    $selection->family,
                    $selection->page_type,
                    $selection->slot,
                );
            }
        }
    }

    private function promoteHeroSelection(Site $site, SiteDraftAssetSelection $selection): bool
    {
        $version = HeroVersion::query()
            ->where('site_id', $site->id)
            ->where('page_type', $selection->page_type)
            ->where('slot', $selection->slot)
            ->find($selection->version_id);

        if (! $version) {
            return false;
        }

        HeroVersion::query()
            ->where('site_id', $site->id)
            ->where('page_type', $selection->page_type)
            ->where('slot', $selection->slot)
            ->update(['is_active' => false]);
        $version->update(['is_active' => true]);

        DB::afterCommit(function () use ($site, $version): void {
            try {
                HeroVersionService::mirrorIntoSnapshot($site, $version);
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        return true;
    }

    private function promoteHeroVideoSelection(Site $site, SiteDraftAssetSelection $selection): bool
    {
        if ($selection->mode === 'off') {
            $site->forceFill(['home_hero_video_enabled' => false])->save();

            return true;
        }

        $version = HeroVideoVersion::query()
            ->where('site_id', $site->id)
            ->find($selection->version_id);

        if (! $version) {
            return false;
        }

        HeroVideoVersion::query()
            ->where('site_id', $site->id)
            ->where('id', '!=', $version->id)
            ->update(['is_active' => false]);
        $version->update(['is_active' => true]);

        $site->forceFill([
            'home_hero_video_path' => $version->s3_key,
            'home_hero_video_provider' => $version->provider,
            'home_hero_video_tier' => $version->resolution,
            'home_hero_video_prompt' => $version->prompt,
            'home_hero_video_status' => 'ready',
            'home_hero_video_enabled' => true,
        ])->save();

        return true;
    }

    private function promoteLogoSelection(
        Site $site,
        SiteDraftAssetSelection $selection,
        ?int $userId,
    ): bool {
        $concept = LogoConcept::query()
            ->where('site_id', $site->id)
            ->find($selection->version_id);

        if (! $concept) {
            return false;
        }

        app(LogoSelectionService::class)->select($site, $concept, $userId, bumpAdmin: false);

        return true;
    }

    /**
     * @return list<array{family: string, page_type: string, slot: string, version_id: int|null, url: string|null, mode: string|null}>
     */
    private function pendingAssetSelections(Site $site): array
    {
        $selections = app(DraftAssetSelections::class)->all($site);
        $heroVersions = HeroVersion::query()
            ->where('site_id', $site->id)
            ->whereIn('id', $selections->where('family', 'hero')->pluck('version_id'))
            ->get()
            ->keyBy('id');
        $logoConcepts = LogoConcept::query()
            ->where('site_id', $site->id)
            ->whereIn('id', $selections->where('family', 'logo')->pluck('version_id'))
            ->get()
            ->keyBy('id');
        $heroVideos = HeroVideoVersion::query()
            ->where('site_id', $site->id)
            ->whereIn('id', $selections->where('family', 'hero_video')->pluck('version_id')->filter())
            ->get()
            ->keyBy('id');

        return $selections
            ->map(function ($selection) use ($heroVersions, $logoConcepts, $heroVideos): ?array {
                if ($selection->family === 'hero_video') {
                    $video = $selection->version_id === null
                        ? null
                        : $heroVideos->get($selection->version_id);

                    if ($selection->mode === 'on' && $video === null) {
                        return null;
                    }

                    return [
                        'family' => 'hero_video',
                        'page_type' => $selection->page_type,
                        'slot' => $selection->slot,
                        'version_id' => $selection->version_id === null ? null : (int) $selection->version_id,
                        'url' => $video?->url(),
                        'mode' => $selection->mode,
                    ];
                }

                $asset = match ($selection->family) {
                    'hero' => $heroVersions->get($selection->version_id),
                    'logo' => $logoConcepts->get($selection->version_id),
                    default => null,
                };

                if (! $asset) {
                    return null;
                }

                return [
                    'family' => $selection->family,
                    'page_type' => $selection->page_type,
                    'slot' => $selection->slot,
                    'version_id' => (int) $selection->version_id,
                    'url' => $selection->family === 'logo' ? $asset->url() : $asset->url,
                    'mode' => $selection->mode ?: null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function draftAssetSelectionKey(string $family, ?string $pageType, ?string $slot): string
    {
        return implode("\0", [$family, $pageType ?? '', $slot ?? '']);
    }

    /**
     * Canonical lock order: site_drafts first, then the target generated_pages
     * row. Refuses when the site has no current published version.
     *
     * @return array{0: SiteDraft, 1: GeneratedPage, 2: SiteVersion}
     */
    protected function lockForSinglePageMutation(Site $site, GeneratedPage $page): array
    {
        $draft = SiteDraft::where('site_id', $site->id)->lockForUpdate()->first();

        if (! $draft) {
            // No draft row means the lock above took nothing. Take the
            // parent sites row as a mutex so two concurrent callers cannot
            // both allocate version max+1, then re-check the draft.
            Site::whereKey($site->id)->lockForUpdate()->first();
            $draft = SiteDraft::where('site_id', $site->id)->lockForUpdate()->first();
        }

        $lockedPage = GeneratedPage::where('site_id', $site->id)
            ->whereKey($page->id)
            ->lockForUpdate()
            ->first();

        if (! $lockedPage) {
            throw new PageStateException("Page {$page->id} does not belong to site {$site->id}.");
        }

        $current = SiteVersionCurrent::where('site_id', $site->id)->first();
        $published = $current ? SiteVersion::find($current->version_id) : null;

        if (! $published) {
            throw new FirstPublishRequiredException(
                "Site {$site->id} has no published version; a full site publish is required first."
            );
        }

        if (! $draft) {
            $draft = SiteDraft::create([
                'site_id' => $site->id,
                'composition' => $published->composition ?? [],
                'updated_at' => now(),
            ]);
        }

        return [$draft, $lockedPage, $published];
    }

    /**
     * System-source draft write: persist only when the composition actually
     * changed (strict !==), without bumping admin_revision.
     *
     * @param  array<string, mixed>  $composition
     */
    protected function syncDraftComposition(SiteDraft $draft, array $composition): void
    {
        if ($draft->composition !== $composition) {
            $draft->composition = $composition;
            $draft->updated_at = now();
            $draft->save();
        }
    }

    /**
     * Pin revisions: prefer draft, fall back to published.
     *
     * @param  iterable<int, GeneratedPage>  $pages
     * @return array<int, array{page_id: int, revision_id: int}>
     */
    protected function buildPinsForPages(iterable $pages): array
    {
        $pageRevisions = [];
        foreach ($pages as $page) {
            $pinnedId = $page->draft_revision_id ?? $page->published_revision_id;
            if (! $pinnedId) {
                throw new SitePublishException("Page {$page->id} ({$page->page_type}) has no published or draft revision; cannot publish.");
            }
            $pageRevisions[] = ['page_id' => $page->id, 'revision_id' => $pinnedId];
        }

        return $pageRevisions;
    }

    /**
     * @param  iterable<int, GeneratedPage>  $pages
     */
    protected function flipDraftRevisionPointers(iterable $pages): void
    {
        foreach ($pages as $page) {
            if ($page->draft_revision_id) {
                $page->update([
                    'published_revision_id' => $page->draft_revision_id,
                    'draft_revision_id' => null,
                ]);
            }
        }
    }

    /**
     * Promote Draft ProjectItems referenced by the given pins and write
     * published_snapshot. Used by publishSite (all pins) and publishSinglePage
     * (the newly pinned page's revision only). Site-scoped: content_data
     * item_ids are not FK-enforced at write time.
     *
     * @param  array<int, array{page_id: int, revision_id: int}>  $pageRevisions
     */
    /**
     * Promote and snapshot the project items pinned by a publish.
     *
     * Every query here is scoped by site_id on purpose: the ids arrive from
     * revision content_data, which is populated from client-supplied arrays,
     * so an id belonging to another tenant must never be promoted or
     * snapshotted by this site's publish.
     *
     * @param  array<int, array{page_id: int, revision_id: int}>  $pageRevisions
     */
    protected function promotePinnedProjectItems(Site $site, array $pageRevisions): void
    {
        $pinnedItemIds = $this->collectPinnedProjectItemIds($pageRevisions);
        if (empty($pinnedItemIds)) {
            return;
        }

        ProjectItem::where('site_id', $site->id)
            ->whereIn('id', $pinnedItemIds)
            ->where('status', ProjectItemStatus::Draft->value)
            ->update(['status' => ProjectItemStatus::Published->value]);

        foreach (ProjectItem::where('site_id', $site->id)->whereIn('id', $pinnedItemIds)->get() as $item) {
            $item->update(['published_snapshot' => $item->buildPublishSnapshot()]);
        }
    }

    /**
     * @param  array<string, mixed>  $composition
     * @param  array<int, array{page_id: int, revision_id: int}>  $pageRevisions
     */
    protected function insertVersion(
        Site $site,
        array $composition,
        array $pageRevisions,
        ?int $userId,
        ?string $publishNote = null,
        ?string $actorChannel = null,
    ): SiteVersion {
        $nextVersion = (SiteVersion::where('site_id', $site->id)->max('version') ?? 0) + 1;

        return SiteVersion::create([
            'site_id' => $site->id,
            'version' => $nextVersion,
            'composition' => $composition,
            'page_revisions' => $pageRevisions,
            'published_at' => now(),
            'published_by_user_id' => $userId,
            'publish_note' => $publishNote,
            'actor_channel' => $actorChannel,
        ]);
    }

    protected function pointCurrentVersion(Site $site, SiteVersion $version): void
    {
        SiteVersionCurrent::updateOrCreate(
            ['site_id' => $site->id],
            ['version_id' => $version->id, 'updated_at' => now()],
        );
    }

    /**
     * @param  array<int, array{page_id: int, revision_id: int}>  $pins
     * @param  array{page_id: int, revision_id: int}  $newPin
     * @return array<int, array{page_id: int, revision_id: int}>
     */
    protected function mergePin(array $pins, array $newPin): array
    {
        $merged = [];
        $replaced = false;

        foreach ($pins as $pin) {
            if (! is_array($pin) || ! isset($pin['page_id'], $pin['revision_id'])) {
                continue;
            }

            if ((int) $pin['page_id'] === $newPin['page_id']) {
                $merged[] = $newPin;
                $replaced = true;

                continue;
            }

            $merged[] = [
                'page_id' => (int) $pin['page_id'],
                'revision_id' => (int) $pin['revision_id'],
            ];
        }

        if (! $replaced) {
            $merged[] = $newPin;
        }

        return $merged;
    }

    /**
     * @param  array<int, array{page_id: int, revision_id: int}>  $pins
     * @return array<int, array{page_id: int, revision_id: int}>
     */
    protected function dropPin(array $pins, int $pageId): array
    {
        $kept = [];
        foreach ($pins as $pin) {
            if (! is_array($pin) || ! isset($pin['page_id'], $pin['revision_id'])) {
                continue;
            }

            if ((int) $pin['page_id'] === $pageId) {
                continue;
            }

            $kept[] = [
                'page_id' => (int) $pin['page_id'],
                'revision_id' => (int) $pin['revision_id'],
            ];
        }

        return $kept;
    }

    /**
     * Drop any nav items that reference pages not in the given published set.
     * Group items (dropdown parents) have their children filtered; if a group
     * ends up empty it's removed entirely. Non-page items (shop, news, etc.)
     * pass through unchanged — they don't reference a GeneratedPage.
     *
     * Called at publish time so a Draft/Archived page never carries a stale
     * nav link into the next SiteVersion.
     *
     * @param  array<string, mixed>  $composition
     * @param  array<int, int>  $publishedPageIds
     * @return array<string, mixed>
     */
    protected function pruneNavToPublishedPages(array $composition, array $publishedPageIds): array
    {
        $allowed = array_flip($publishedPageIds);
        $items = $composition['nav']['items'] ?? [];

        $filtered = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = $item['type'] ?? null;

            if ($type === 'page') {
                if (isset($item['page_id']) && isset($allowed[(int) $item['page_id']])) {
                    $filtered[] = $item;
                }
                continue;
            }

            if ($type === 'group' && isset($item['children']) && is_array($item['children'])) {
                $children = array_values(array_filter(
                    $item['children'],
                    fn ($c) => is_array($c)
                        && ($c['type'] ?? null) === 'page'
                        && isset($c['page_id'])
                        && isset($allowed[(int) $c['page_id']])
                ));

                if (count($children) > 0) {
                    $item['children'] = $children;
                    $filtered[] = $item;
                }

                continue;
            }

            // Anything else (shop, news, custom links) passes through.
            $filtered[] = $item;
        }

        $composition['nav']['items'] = $filtered;

        return $composition;
    }

    /**
     * Drop nav entries that point at a single page — top-level page items
     * and matching group children. Empty groups are removed. Everything
     * else (including draft entries for other unpublished pages) stays.
     *
     * @param  array<string, mixed>  $composition
     * @return array<string, mixed>
     */
    protected function pruneNavEntriesForPage(array $composition, int $pageId): array
    {
        $items = $composition['nav']['items'] ?? [];
        if (! is_array($items)) {
            return $composition;
        }

        $filtered = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                $filtered[] = $item;

                continue;
            }

            $type = $item['type'] ?? null;

            if ($type === 'page' && isset($item['page_id']) && (int) $item['page_id'] === $pageId) {
                continue;
            }

            if ($type === 'group' && isset($item['children']) && is_array($item['children'])) {
                $item['children'] = array_values(array_filter(
                    $item['children'],
                    fn ($child) => ! is_array($child) || (int) ($child['page_id'] ?? 0) !== $pageId,
                ));

                if (count($item['children']) === 0) {
                    continue;
                }
            }

            $filtered[] = $item;
        }

        $composition['nav']['items'] = $filtered;

        return $composition;
    }

    /**
     * Snapshot the current content_hash and media_hash of each pinned
     * ProjectItem into the pinned PageRevision's section payload.
     * Mutates content_data.sections[].published_content_hashes and
     * .published_media_hashes (id => hash maps) so future renders /
     * drift checks have a baseline to compare against.
     *
     * Operates per-pinned-revision so revisions that don't reference
     * project items (home, about, contact) are skipped without writes.
     *
     * @param  array<int, array{page_id: int, revision_id: int}>  $pageRevisions
     */
    protected function snapshotPublishedHashesIntoRevisions(Site $site, array $pageRevisions): void
    {
        $revisionIds = array_column($pageRevisions, 'revision_id');
        if (empty($revisionIds)) {
            return;
        }

        foreach (PageRevision::whereIn('id', $revisionIds)->get() as $revision) {
            $sections = $revision->content_data['sections'] ?? [];
            $changed = false;

            foreach ($sections as $i => $section) {
                $itemIds = $section['item_ids'] ?? null;
                if (! is_array($itemIds) || empty($itemIds)) {
                    continue;
                }

                // site_id scope: item_ids is client-influenced content, so a
                // foreign id must not get a hash baseline written for it here.
                $items = ProjectItem::where('site_id', $site->id)
                    ->whereIn('id', $itemIds)
                    ->get(['id', 'content_hash', 'media_hash']);
                $contentMap = [];
                $mediaMap = [];
                foreach ($items as $item) {
                    $contentMap[(int) $item->id] = (string) $item->content_hash;
                    $mediaMap[(int) $item->id] = (string) $item->media_hash;
                }

                $sections[$i]['published_content_hashes'] = $contentMap;
                $sections[$i]['published_media_hashes'] = $mediaMap;
                $changed = true;
            }

            if ($changed) {
                $contentData = $revision->content_data;
                $contentData['sections'] = $sections;
                $revision->update(['content_data' => $contentData]);
            }
        }
    }

    /**
     * Collect ProjectItem ids referenced in the pinned revisions.
     * Walks each revision's content_data.sections[].item_ids[] (the
     * shape GenerateProjectsPageJob writes — gallery + case-study
     * sections). Returns a deduplicated, integer-cast list.
     *
     * @param  array<int, array{page_id: int, revision_id: int}>  $pageRevisions
     * @return array<int, int>
     */
    protected function collectPinnedProjectItemIds(array $pageRevisions): array
    {
        $revisionIds = array_column($pageRevisions, 'revision_id');
        if (empty($revisionIds)) {
            return [];
        }

        $itemIds = [];
        foreach (PageRevision::whereIn('id', $revisionIds)->get(['content_data']) as $revision) {
            foreach ($revision->content_data['sections'] ?? [] as $section) {
                foreach ($section['item_ids'] ?? [] as $id) {
                    $itemIds[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($itemIds));
    }

    public function discardAllDrafts(Site $site): void
    {
        DB::transaction(function () use ($site) {
            // Canonical lock order in this codebase is site_drafts FIRST,
            // then generated_pages — matches publishSite(), applyAdminChange,
            // and appendNavPageAtomic. Reversing it lets a concurrent
            // publish + discard deadlock (each holds one set of rows and
            // waits on the other).
            //
            // First-publish sites have no SiteDraft row yet, so we cannot
            // rely on lockForUpdate()->first() alone — first() returns null
            // when the row is absent and locks nothing. Take the parent
            // sites row first as a guaranteed mutex, THEN attempt the draft
            // lock for the canonical-order benefit on populated sites.
            Site::whereKey($site->id)->lockForUpdate()->first();
            SiteDraft::where('site_id', $site->id)->lockForUpdate()->first();

            $pages = GeneratedPage::where('site_id', $site->id)
                ->whereNotNull('draft_revision_id')
                ->lockForUpdate()
                ->get();

            foreach ($pages as $page) {
                app(PageService::class)->discardDraft($page);
            }

            $this->composition->discardComposition($site);
            app(DraftAssetSelections::class)->clear($site);

            // Throw away any pending hero-scene draft.
            if ($site->home_hero_scene_draft !== null) {
                $site->forceFill(['home_hero_scene_draft' => null])->save();
            }

            // Two-part project-item discard:
            //
            // 1. Tiles that EXISTED at last publish + got edited since:
            //    revert their fields from published_snapshot. Drops the
            //    agent's mid-edit content; the banner clears.
            //
            // 2. Tiles ADDED since last publish: hard-
            //    delete them. Without this the agent sees orphan Draft
            //    rows in the gallery editor that public preview ignores
            //    (split-brain). Cascade through SiteMedia + reset the
            //    reverse link on ImportedMedia so re-assignment works.
            $current = SiteVersionCurrent::where('site_id', $site->id)->first();
            $published = $current ? SiteVersion::find($current->version_id) : null;
            $publishedAt = $published?->published_at;

            if ($published) {
                $pinnedItemIds = $this->collectPinnedProjectItemIds($published->page_revisions);
                if (! empty($pinnedItemIds)) {
                    // site_id scope for the same reason as publishSite():
                    // the pinned ids originate from client-influenced
                    // section.item_ids, so reverting them unscoped would
                    // roll back another tenant's tile content.
                    foreach (ProjectItem::whereIn('id', $pinnedItemIds)
                        ->where('site_id', $site->id)
                        ->whereNotNull('published_snapshot')
                        ->get() as $item) {
                        if ($item->hasUnpublishedDrift()) {
                            $item->revertFromPublishSnapshot();
                        }
                    }
                }
            }

            // Step 2 — drop items that didn't exist at last publish.
            // Restrict to status=Draft so we never destroy a Published
            // tile that someone manually demoted. The Draft+post-publish
            // intersection is exactly "tiles added during this draft
            // session that haven't been published yet".
            $newDraftItems = ProjectItem::where('site_id', $site->id)
                ->where('status', \App\Enums\ProjectItemStatus::Draft->value)
                ->when($publishedAt, fn ($q) => $q->where('created_at', '>', $publishedAt))
                ->get();

            foreach ($newDraftItems as $item) {
                // Reset ImportedMedia reverse link for FB-imported tiles
                // so the photo can be re-assigned after discard. Lookup
                // via metadata.imported_media_id (set by useAsProject) — fall back
                // to no-op if absent.
                $importedMediaId = $item->metadata['imported_media_id'] ?? null;
                if ($importedMediaId) {
                    \App\Models\ImportedMedia::where('id', $importedMediaId)
                        ->where('site_id', $site->id)
                        ->update([
                            'assigned_to' => null,
                            'assigned_page_id' => null,
                        ]);
                }

                // Delete attached SiteMedia rows first to satisfy the FK
                // before observer's pinned-revision guard runs (it only
                // blocks deletion of items pinned in a CURRENT site
                // version; new draft items aren't pinned anywhere yet).
                \App\Models\SiteMedia::where('project_item_id', $item->id)->delete();
                $item->update(['image_id' => null]);
                $item->delete();
            }
        });
    }
}

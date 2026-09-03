<?php

namespace App\Services\Site;

use App\Enums\MutationSource;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use Illuminate\Support\Facades\DB;

class CompositionService
{
    public function __construct(protected CompositionDefaults $defaults) {}

    public function ensureDraftRow(Site $site, ?int $userId = null): void
    {
        SiteDraft::query()->insertOrIgnore([[
            'site_id' => $site->id,
            'composition' => json_encode($this->defaults->forSite($site)),
            'admin_revision' => 0,
            'updated_by_user_id' => $userId,
            'updated_at' => now(),
        ]]);
    }

    /**
     * Returns the site's draft, creating one seeded from defaults if absent.
     *
     * getOrCreateDraft does NOT count as a mutation on its own — fetching
     * a draft shouldn't bump admin_revision. The create path, however, DOES
     * persist composition defaults; treat that as a System-sourced write
     * so it's explicitly non-admin and never trips the auto-publish guard.
     */
    public function getOrCreateDraft(Site $site, ?int $userId = null): SiteDraft
    {
        return DB::transaction(function () use ($site, $userId): SiteDraft {
            $this->ensureDraftRow($site, $userId);

            return SiteDraft::query()
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->firstOrFail();
        });
    }

    public function updateNav(SiteDraft $draft, array $items, MutationSource $source, ?int $userId = null): void
    {
        $composition = $draft->composition;
        $composition['nav']['items'] = $items;
        $this->persistComposition($draft, $composition, $source, $userId);
    }

    /**
     * Add the stored Shop navigation entry to the draft. Published versions
     * are immutable and may only be superseded through SitePublishService.
     */
    public function ensureShopNavEntry(Site $site): bool
    {
        // Gate on "there is something to buy", not on a snapshot row existing. A snapshot
        // row can exist for a site with no products at all, so backfilling on
        // row-existence would put a Shop link on storefronts with nothing in them.
        if (! $site->hasPurchasableShop()) {
            return false;
        }

        $changed = DB::transaction(function () use ($site): bool {
            $contactPageIds = GeneratedPage::query()
                ->where('site_id', $site->id)
                ->where('page_type', 'contact')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $draft = SiteDraft::query()
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->first();
            $draftCreated = false;
            if (! $draft) {
                $draft = SiteDraft::create([
                    'site_id' => $site->id,
                    'composition' => $this->defaults->forSite($site),
                    'updated_at' => now(),
                ]);
                $draftCreated = true;
            }

            [$draftComposition, $draftChanged] = $this->compositionWithShopNavEntry(
                $draft->composition ?? [],
                $contactPageIds,
            );
            if ($draftChanged) {
                $draft->composition = $draftComposition;
                $draft->updated_at = now();
                $draft->save();
            }

            return $draftCreated || $draftChanged;
        });

        if ($changed) {
            app(PublicPageCache::class)->invalidate($site);
        }

        return $changed;
    }

    /**
     * Atomically append a page nav entry. The read-modify-write happens
     * inside a row-level lock (SELECT FOR UPDATE on site_drafts) so concurrent
     * callers don't clobber each other's appends. No-op if a nav item already
     * targets the given page_id, or if the page is the current homepage.
     *
     * Returns true if the entry was appended, false if it was skipped (dup
     * or homepage).
     *
     * Context: GenerateServicePageJob fires N parallel jobs that each need
     * to add their page to the draft nav. A getOrCreateDraft + updateNav
     * split that released the lock between read and write would let the
     * last writer win, losing the other N-1 entries — this method must
     * read and write under a single held lock.
     */
    /**
     * @param  array<int, string>|null  $beforePageTypes  When non-empty, insert the new
     *     entry IMMEDIATELY BEFORE the first nav item whose linked page_type is in
     *     this list. e.g. ['contact'] places the new entry just before the Contact
     *     link, after any service pages. Falls back to appending if no match. Used
     *     by archetype pages (projects) that should sit in a specific slot rather
     *     than trailing after Contact.
     */
    public function appendNavPageAtomic(
        Site $site,
        int $pageId,
        string $label,
        MutationSource $source,
        ?int $userId = null,
        ?array $beforePageTypes = null,
    ): bool {
        return DB::transaction(function () use ($site, $pageId, $label, $source, $userId, $beforePageTypes): bool {
            // Nav hard rule: only Published pages may have nav entries. A Draft
            // or Archived page would render as a dead link in the admin draft
            // preview AND carry through into the public nav on next publish
            // unless filtered out. Refuse at the source.
            $page = \App\Models\GeneratedPage::where('id', $pageId)
                ->where('site_id', $site->id)
                ->first();

            if (! $page || $page->status !== \App\Enums\PageStatus::Published) {
                return false;
            }

            if ($page->parent_id !== null) {
                return false;
            }

            $draft = SiteDraft::where('site_id', $site->id)->lockForUpdate()->first();
            if (! $draft) {
                $draft = SiteDraft::create([
                    'site_id' => $site->id,
                    'composition' => $this->defaults->forSite($site),
                    'updated_by_user_id' => $userId,
                    'updated_at' => now(),
                ]);
            }

            $composition = $draft->composition;
            $items = $composition['nav']['items'] ?? [];

            if ((int) ($composition['homepage_page_id'] ?? 0) === $pageId) {
                return false;
            }

            // Existence check covers both top-level page items and pages
            // nested inside group items. Without walking children, a page
            // that OrganiseNavJob grouped into a "Services" dropdown would
            // slip past the top-level scan and get re-appended at the top
            // level on the next job run.
            foreach ($items as $it) {
                $type = $it['type'] ?? null;
                if ($type === 'page' && (int) ($it['page_id'] ?? 0) === $pageId) {
                    return false;
                }
                if ($type === 'group') {
                    foreach ($it['children'] ?? [] as $child) {
                        if (($child['type'] ?? null) === 'page' && (int) ($child['page_id'] ?? 0) === $pageId) {
                            return false;
                        }
                    }
                }
            }

            $newEntry = ['type' => 'page', 'label' => $label, 'page_id' => $pageId];

            // Archetype-aware placement: when caller specified beforePageTypes,
            // splice the new entry RIGHT BEFORE the first nav item whose linked
            // page_type is in that list. e.g. beforePageTypes=['contact'] →
            // new entry lands just before Contact (after every service page,
            // before Contact). Falls through to plain append if no match.
            $insertedAt = null;
            if (! empty($beforePageTypes)) {
                $linkedPageIds = collect($items)
                    ->filter(fn ($it) => ($it['type'] ?? null) === 'page')
                    ->pluck('page_id')
                    ->filter()
                    ->all();
                $pageTypeById = \App\Models\GeneratedPage::whereIn('id', $linkedPageIds)
                    ->pluck('page_type', 'id')
                    ->all();
                foreach ($items as $idx => $it) {
                    if (($it['type'] ?? null) !== 'page') {
                        continue;
                    }
                    $linkedType = $pageTypeById[$it['page_id'] ?? 0] ?? null;
                    if ($linkedType !== null && in_array($linkedType, $beforePageTypes, true)) {
                        array_splice($items, $idx, 0, [$newEntry]);
                        $insertedAt = $idx;
                        break;
                    }
                }
            }
            if ($insertedAt === null) {
                $items[] = $newEntry;
            }
            $composition['nav']['items'] = $items;

            $this->persistComposition($draft, $composition, $source, $userId);

            return true;
        });
    }

    public function updateTheme(
        SiteDraft $draft,
        string $key,
        ?string $primaryOverride,
        ?string $accentOverride,
        MutationSource $source,
        ?int $userId = null,
    ): void {
        $composition = $draft->composition;
        // Preserve any non-colour / extended override keys that were set
        // via updateThemeOverrides — callers that only want to touch the
        // preset key + primary/accent shouldn't nuke the admin's font /
        // layout / tertiary-palette overrides along the way.
        $existing = is_array($composition['theme'] ?? null) ? $composition['theme'] : [];
        $composition['theme'] = array_merge($existing, [
            'key' => $key,
            'primary_override' => $primaryOverride,
            'accent_override' => $accentOverride,
        ]);
        $this->persistComposition($draft, $composition, $source, $userId);
    }

    /**
     * Merge per-token overrides into composition.theme. Keys set to null
     * or empty string are REMOVED (lets admin clear a single override).
     * Keys absent from $overrides stay untouched.
     *
     * Recognised override keys (each optional):
     *   primary_override, accent_override, tertiary_override,
     *   surface_override, surface_alt_override, border_override,
     *   text_override, text_muted_override,
     *   display_font_override, body_font_override,
     *   heading_scale_override, spacing_density_override, corner_style_override,
     *   container_width_override, display_scale_override
     *
     * @param  array<string, string|null>  $overrides  Partial — only keys being changed.
     */
    public function updateThemeOverrides(
        SiteDraft $draft,
        array $overrides,
        MutationSource $source,
        ?int $userId = null,
    ): void {
        $composition = $draft->composition;
        $theme = is_array($composition['theme'] ?? null) ? $composition['theme'] : [];

        foreach ($overrides as $key => $value) {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                unset($theme[$key]);
            } else {
                $theme[$key] = $value;
            }
        }

        $composition['theme'] = $theme;
        $this->persistComposition($draft, $composition, $source, $userId);
    }

    /**
     * Wipe every *_override key in composition.theme. Keeps the preset
     * `key` field intact (it identifies which base preset the brief
     * sits on; it's not itself an override) and keeps `token_overrides`
     * (operator sticky notes, not a legacy knob). Used by "Regenerate
     * design brief" and "Reset overrides only" actions in the Design panel.
     */
    public function clearAllThemeOverrides(Site $site, MutationSource $source, ?int $userId = null): void
    {
        DB::transaction(function () use ($site, $source, $userId) {
            $draft = SiteDraft::where('site_id', $site->id)->lockForUpdate()->first();
            if (! $draft) {
                return;
            }

            $composition = $draft->composition;
            $theme = is_array($composition['theme'] ?? null) ? $composition['theme'] : [];

            foreach (array_keys($theme) as $key) {
                if ($key === 'token_overrides') {
                    continue;
                }
                if (is_string($key) && str_ends_with($key, '_override')) {
                    unset($theme[$key]);
                }
            }

            $composition['theme'] = $theme;
            $this->persistComposition($draft, $composition, $source, $userId);
        });
    }

    public function updateFooter(SiteDraft $draft, array $footer, MutationSource $source, ?int $userId = null): void
    {
        $composition = $draft->composition;
        $composition['footer'] = $footer;
        $this->persistComposition($draft, $composition, $source, $userId);
    }

    public function setHomepage(SiteDraft $draft, int $pageId, MutationSource $source, ?int $userId = null): void
    {
        $composition = $draft->composition;
        $composition['homepage_page_id'] = $pageId;
        $this->persistComposition($draft, $composition, $source, $userId);
    }

    /**
     * Bump admin_revision without changing composition. Used by callers
     * (e.g. per-page status changes) whose intent is admin-authored but
     * whose mutation target isn't the composition JSON itself.
     *
     * The admin_revision guard in the auto-publish listener needs to see
     * every admin intent, not just composition edits.
     */
    public function bumpAdminRevision(
        Site $site,
        ?int $userId = null,
        bool $invalidatePublicCache = true,
    ): void
    {
        DB::transaction(function () use ($site, $userId) {
            $draft = SiteDraft::where('site_id', $site->id)->lockForUpdate()->first();
            if (! $draft) {
                // No draft → create one at defaults so the admin_revision has
                // somewhere to live. Subsequent admin mutations share it.
                $draft = SiteDraft::create([
                    'site_id' => $site->id,
                    'composition' => $this->defaults->forSite($site),
                    'updated_by_user_id' => $userId,
                    'updated_at' => now(),
                ]);
            }
            $draft->admin_revision = (int) $draft->admin_revision + 1;
            $draft->updated_by_user_id = $userId;
            $draft->updated_at = now();
            $draft->save();
        });

        // Admin-sourced mutations (watermark toggle, contact form flag, nav
        // labels, per-page status changes, etc.) change profile/preview state
        // that the public renderer reads live. Without this, PublicPageCache
        // keeps serving the pre-toggle HTML until TTL or next publish.
        if ($invalidatePublicCache) {
            app(PublicPageCache::class)->invalidate($site);
        }
    }

    /**
     * Run an admin-sourced mutation atomically with the admin_revision bump.
     *
     * The caller passes a closure that performs the actual mutation (e.g.
     * $gp->update(['status' => 'draft'])). This method wraps:
     *   1. lockForUpdate on site_drafts (so concurrent admin writes serialise)
     *   2. the caller's mutation
     *   3. admin_revision bump
     * ...in a single DB transaction. That closes the race window where
     * AutoPublishCoordinator's finalize could read admin_revision between
     * the mutation and the bump, see it unchanged, and auto-publish the
     * admin's in-flight change.
     */
    public function applyAdminChange(
        Site $site,
        \Closure $mutation,
        ?int $userId = null,
        bool $invalidatePublicCache = true,
    ): void {
        DB::transaction(function () use ($site, $mutation, $userId): void {
            $this->ensureDraftRow($site, $userId);
            $draft = SiteDraft::query()
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->firstOrFail();

            $mutation();

            $draft->admin_revision = (int) $draft->admin_revision + 1;
            $draft->updated_by_user_id = $userId;
            $draft->updated_at = now();
            $draft->save();
        });

        // Admin-sourced mutations change profile/content state the public
        // renderer reads live. Invalidate so PublicPageCache doesn't keep
        // serving stale HTML. Mirrors the same call in bumpAdminRevision.
        if ($invalidatePublicCache) {
            app(PublicPageCache::class)->invalidate($site);
        }
    }

    /**
     * Reserve sort_order slots for N service pages by pre-inserting
     * placeholder generated_pages rows with status=Draft + empty content.
     *
     * Closes the cross-dispatch race where two concurrent addServicePages
     * calls could both read the same max(sort_order) and hand out
     * colliding slots. A placeholder persists the
     * reservation across transaction boundaries so subsequent callers
     * see the higher max.
     *
     * Lock order matches the rest of the codebase: site_drafts FIRST,
     * then generated_pages.
     *
     * Placeholders use status=Draft so they're filtered out of
     * publishSite's pinning until the generation job flips them to
     * Published on successful completion. They're invisible to the
     * public renderer in the meantime.
     *
     * @param  array<int, array{service: string, slug: string, nav_label: string}>  $requests
     * @return array<string, int>  slug => reserved sort_order (only for slugs actually reserved)
     */
    public function reserveServicePageSlots(Site $site, array $requests): array
    {
        if (empty($requests)) {
            return [];
        }

        return DB::transaction(function () use ($site, $requests): array {
            // Serialise concurrent addServicePages by locking the draft row
            // (or creating one). Canonical lock order: drafts → pages.
            $draft = SiteDraft::where('site_id', $site->id)->lockForUpdate()->first();
            if (! $draft) {
                $draft = SiteDraft::create([
                    'site_id' => $site->id,
                    'composition' => $this->defaults->forSite($site),
                    'updated_at' => now(),
                ]);
            }

            // Lock existing pages so the max read is consistent with the
            // INSERTs that follow; also blocks concurrent publishes until
            // reservation commits.
            $existingTypes = \App\Models\GeneratedPage::where('site_id', $site->id)
                ->lockForUpdate()
                ->pluck('page_type')
                ->all();

            $max = (int) (\App\Models\GeneratedPage::where('site_id', $site->id)
                ->where('sort_order', '<', 99)
                ->max('sort_order') ?? 1);

            $reserved = [];
            $nextSort = $max;
            foreach ($requests as $req) {
                $slug = $req['slug'];
                if (in_array($slug, $existingTypes, true)) {
                    // Already exists (real page or a prior placeholder) — skip.
                    continue;
                }

                $nextSort++;

                \App\Models\GeneratedPage::create([
                    'site_id' => $site->id,
                    'page_type' => $slug,
                    'kind' => \App\Enums\PageKind::Service,
                    'origin' => \App\Enums\PageOrigin::Pipeline,
                    'nav_label' => $req['nav_label'],
                    'content_data' => [],
                    'sort_order' => $nextSort,
                    'version' => 1,
                    'status' => \App\Enums\PageStatus::Draft,
                    // Per-service dedicated hero: GenerateServicePageJob
                    // generates a unique image for each service page rather
                    // than reusing the shared hero. The shared-service-hero
                    // still exists as a fallback (activated by the column
                    // default) for any per-page generation that fails.
                    'hero_source' => 'dedicated',
                ]);

                $existingTypes[] = $slug; // prevent dup within this batch
                $reserved[$slug] = $nextSort;
            }

            return $reserved;
        });
    }

    public function hasPendingComposition(Site $site): bool
    {
        $draft = SiteDraft::where('site_id', $site->id)->first();
        if (! $draft) {
            return false;
        }

        $current = SiteVersionCurrent::where('site_id', $site->id)->first();
        if (! $current) {
            return true; // never published — pending by definition
        }

        $publishedComposition = SiteVersion::find($current->version_id)?->composition ?? [];

        return $draft->composition !== $publishedComposition;
    }

    public function discardComposition(Site $site): void
    {
        $current = SiteVersionCurrent::where('site_id', $site->id)->first();

        // For never-published sites: reset draft to defaults so discardAllDrafts
        // gives a clean slate. Without this, page drafts get cleared but composition
        // edits stick around — breaking the "atomic discard" invariant.
        $resetTo = $current
            ? (SiteVersion::find($current->version_id)?->composition ?? [])
            : $this->defaults->forSite($site);

        // Use save() on the instance so the array cast is applied consistently,
        // preserving key order that matches the published composition.
        $draft = SiteDraft::where('site_id', $site->id)->first();
        if ($draft) {
            $draft->composition = $resetTo;
            $draft->updated_at = now();
            $draft->save();
        }
    }

    /**
     * Single persist path for every composition write. Bumps admin_revision
     * iff the mutation source represents explicit admin intent.
     */
    protected function persistComposition(
        SiteDraft $draft,
        array $composition,
        MutationSource $source,
        ?int $userId,
    ): void {
        $draft->composition = $composition;
        $draft->updated_by_user_id = $userId;
        $draft->updated_at = now();

        if ($source->isAdminIntent()) {
            $draft->admin_revision = (int) ($draft->admin_revision ?? 0) + 1;
        }

        $draft->save();
    }

    /**
     * @param  array<string, mixed>  $composition
     * @param  list<int>  $contactPageIds
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function compositionWithShopNavEntry(array $composition, array $contactPageIds): array
    {
        $items = $composition['nav']['items'] ?? [];
        foreach ($items as $item) {
            if (($item['type'] ?? null) === 'shop') {
                return [$composition, false];
            }
        }

        $shopEntry = ['type' => 'shop', 'label' => 'Shop'];
        $contactIndex = null;
        foreach ($items as $index => $item) {
            $isContactPage = ($item['type'] ?? null) === 'page'
                && in_array((int) ($item['page_id'] ?? 0), $contactPageIds, true);
            if ($isContactPage || ($item['type'] ?? null) === 'contact') {
                $contactIndex = $index;
                break;
            }
        }

        if ($contactIndex === null) {
            $items[] = $shopEntry;
        } else {
            array_splice($items, $contactIndex, 0, [$shopEntry]);
        }

        $composition['nav']['items'] = $items;

        return [$composition, true];
    }
}

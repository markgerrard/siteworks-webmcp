<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\CompositionService;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\SitePublishService;
use Livewire\Attributes\On;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;
    #[Locked]
    public int $siteId;
    public bool $pending = false;
    public int $pendingCount = 0;
    public ?string $lastEditedAt = null;
    public ?string $lastEditedBy = null;
    public ?string $pendingHeroVideoMode = null;
    public ?string $errorMessage = null;

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        abort_unless($this->findAuthorizedSite(), 403);
        $this->refresh();
    }

    /**
     * Recompute pending state. Called on mount + after every action that
     * might change the draft (publish, discard), and on the Livewire event
     * `composition-dirty` so other page-manager widgets can nudge us.
     */
    #[On('composition-dirty')]
    #[On('composition-published')]
    public function refresh(): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        $compositionDelta = app(CompositionService::class)->hasPendingComposition($site);

        $pagesWithDrafts = GeneratedPage::where('site_id', $site->id)
            ->whereNotNull('draft_revision_id')
            ->count();

        // Pending also includes status changes that alter the SET of pages
        // the next publish would pin. Compare current Published page IDs
        // against what's pinned in the live version — any difference means
        // the admin archived/drafted a page (or promoted one from Draft
        // back to Published) since last publish.
        $publishedIds = GeneratedPage::where('site_id', $site->id)
            ->published()
            ->pluck('id')->sort()->values()->all();
        $current = SiteVersionCurrent::where('site_id', $site->id)->first();
        $published = $current ? SiteVersion::find($current->version_id) : null;
        $pinnedIds = $published
            ? collect($published->page_revisions)->pluck('page_id')->map(fn ($i) => (int) $i)->sort()->values()->all()
            : [];
        $pageSetDelta = $publishedIds !== $pinnedIds;

        // Project-item content drift — agent can edit a pinned tile's
        // title / description / category / image without touching the
        // page's draft_revision_id. Compare each pinned ProjectItem's
        // current values against its published_snapshot (captured on
        // last publish). Snapshot-based comparison rather than timestamp
        // because Discard reverts fields to the snapshot — only an
        // actual content delta should keep the banner flagged.
        $projectItemDrift = false;
        $projectItemDriftCount = 0;
        $newDraftItemsCount = 0;
        if ($published) {
            $pinnedItemIds = [];
            foreach ($published->page_revisions as $pin) {
                $rev = \App\Models\Site\PageRevision::find($pin['revision_id'] ?? null);
                if (! $rev) {
                    continue;
                }
                foreach ($rev->content_data['sections'] ?? [] as $section) {
                    if (in_array($section['type'] ?? '', ['project_gallery', 'case_study_highlights'], true)) {
                        foreach ($section['item_ids'] ?? [] as $id) {
                            $pinnedItemIds[] = (int) $id;
                        }
                    }
                }
            }
            $pinnedItemIds = array_values(array_unique($pinnedItemIds));
            if (! empty($pinnedItemIds)) {
                foreach (\App\Models\ProjectItem::whereIn('id', $pinnedItemIds)
                    ->whereNotNull('published_snapshot')
                    ->get() as $item) {
                    if ($item->hasUnpublishedDrift()) {
                        $projectItemDriftCount++;
                    }
                }
                $projectItemDrift = $projectItemDriftCount > 0;
            }

            // Count Draft tiles added since last publish — these will be
            // hard-deleted by Discard and need to count toward pending.
            if ($published->published_at) {
                $newDraftItemsCount = \App\Models\ProjectItem::where('site_id', $site->id)
                    ->where('status', \App\Enums\ProjectItemStatus::Draft->value)
                    ->where('created_at', '>', $published->published_at)
                    ->count();
            }
        }

        $heroSceneDraftPending = $site->home_hero_scene_draft !== null;
        $pendingAssetSelections = app(DraftAssetSelections::class)->all($site);
        $pendingAssetSelectionCount = $pendingAssetSelections->count();
        $this->pendingHeroVideoMode = $pendingAssetSelections
            ->firstWhere('family', 'hero_video')
            ?->mode;

        $this->pending = $compositionDelta || $pagesWithDrafts > 0 || $pageSetDelta
            || $projectItemDrift || $newDraftItemsCount > 0 || $heroSceneDraftPending
            || $pendingAssetSelectionCount > 0;

        if (! $this->pending) {
            $this->pendingCount = 0;
            $this->lastEditedAt = null;
            $this->lastEditedBy = null;
            $this->pendingHeroVideoMode = null;

            return;
        }

        // Draft row may be absent: first publish of a site creates the
        // SiteVersion without necessarily creating a SiteDraft (the legacy
        // initial-publish path never did). Null-safe-access everywhere
        // so the banner survives that state.
        $draft = SiteDraft::where('site_id', $site->id)->first();

        $count = 0;
        $draftComposition = $draft?->composition ?? [];

        if ($published) {
            $draftItems = $draftComposition['nav']['items'] ?? [];
            $publishedItems = $published->composition['nav']['items'] ?? [];
            $count += abs(count($draftItems) - count($publishedItems));

            foreach (['theme', 'footer', 'homepage_page_id'] as $k) {
                if (($draftComposition[$k] ?? null) !== ($published->composition[$k] ?? null)) {
                    $count++;
                }
            }
        } else {
            $count += count($draftComposition['nav']['items'] ?? []);
        }

        if ($pageSetDelta) {
            $count += count(array_diff($publishedIds, $pinnedIds))
                + count(array_diff($pinnedIds, $publishedIds));
        }

        $count += $pagesWithDrafts;
        $count += $projectItemDriftCount;
        $count += $newDraftItemsCount;
        $count += $pendingAssetSelectionCount;

        if ($heroSceneDraftPending) {
            $count++;
        }

        $this->pendingCount = max(1, $count);
        $this->lastEditedAt = $draft?->updated_at?->diffForHumans();
        $this->lastEditedBy = $draft?->updated_by_user_id
            ? (\App\Models\User::find($draft->updated_by_user_id)?->name ?? 'someone')
            : null;
    }

    public function publish(): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        try {
            app(SitePublishService::class)->publishSite(
                $site,
                publishNote: 'Manual publish from unpublished-changes banner',
                userId: auth()->id(),
            );
            $this->errorMessage = null;
            $this->dispatch('composition-published');
            session()->flash('page-mgr-msg', 'Published successfully.');
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();
        }

        $this->refresh();
    }

    public function discard(): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        app(SitePublishService::class)->discardAllDrafts($site);
        session()->flash('page-mgr-msg', 'Unpublished changes discarded.');
        $this->dispatch('composition-published'); // tell other widgets to reload
        $this->refresh();
    }
}; ?>

{{-- wire:poll.30s is the safety net (covers side-by-side tabs / long-idle
     sessions). The inline script below is the fast path: when the admin
     tab regains focus (e.g. after inline-editing on the public-host tab),
     refresh immediately so the banner reflects the new draft_revision_id
     without the 30-second wait. Debounced so focus + visibilitychange
     firing back-to-back only trigger one recompute. --}}
<div wire:poll.30s="refresh" x-data x-init="
    window._bannerRefresh = window._bannerRefresh || (() => {
        if (window._bannerRefreshT) return;
        window._bannerRefreshT = setTimeout(() => {
            window._bannerRefreshT = null;
            Livewire.dispatch('composition-dirty');
        }, 250);
    });
    if (!window._bannerRefreshWired) {
        window._bannerRefreshWired = true;
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') window._bannerRefresh();
        });
        window.addEventListener('focus', () => window._bannerRefresh());
    }
">
    @if ($pending)
        <div class="rounded-lg border border-amber-300 bg-amber-50 dark:bg-amber-950/30 dark:border-amber-700/50 p-4 mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
            <div class="flex items-start sm:items-center gap-3 min-w-0">
                <div class="shrink-0 mt-0.5 sm:mt-0">
                    <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <div class="min-w-0">
                    <div class="text-sm font-semibold text-amber-900 dark:text-amber-100">
                        {{ $pendingCount }} unpublished {{ \Illuminate\Support\Str::plural('change', $pendingCount) }}
                    </div>
                    @if ($lastEditedAt)
                        <div class="text-xs text-amber-800/80 dark:text-amber-200/70 mt-0.5">
                            Last edited {{ $lastEditedAt }}@if ($lastEditedBy) by {{ $lastEditedBy }}@endif
                        </div>
                    @endif
                    @if ($pendingHeroVideoMode === 'on')
                        <div class="text-xs text-amber-800/80 dark:text-amber-200/70 mt-0.5">
                            this publish switches the home hero to video
                        </div>
                    @elseif ($pendingHeroVideoMode === 'off')
                        <div class="text-xs text-amber-800/80 dark:text-amber-200/70 mt-0.5">
                            this publish switches the home hero to image
                        </div>
                    @endif
                    @if ($errorMessage)
                        <div class="text-xs text-red-700 dark:text-red-300 mt-1">{{ $errorMessage }}</div>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <x-confirm-button
                    name="discard-draft"
                    size="sm"
                    triggerVariant="ghost"
                    triggerLabel="Discard draft"
                    title="Discard all unpublished changes?"
                    description="Your draft edits will be lost and cannot be recovered."
                    confirmLabel="Discard"
                    confirmVariant="danger"
                    wire:click="discard"
                />
                <flux:button size="sm" variant="primary"
                             wire:click="publish"
                             wire:loading.attr="disabled"
                             wire:target="publish">
                    <span wire:loading.remove wire:target="publish">Publish now</span>
                    <span wire:loading wire:target="publish">Publishing…</span>
                </flux:button>
            </div>
        </div>
    @endif
</div>

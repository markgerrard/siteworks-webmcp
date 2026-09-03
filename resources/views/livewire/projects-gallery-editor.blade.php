<?php

use App\Enums\ProjectItemSource;
use App\Enums\ProjectItemStatus;
use App\Enums\ProjectItemType;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;

    /**
     * Identifiers the client must not rewrite. Unlocked, $wire.set() could
     * point $pageId at another tenant's page while the site check still
     * passed against the caller's own $siteId.
     */
    #[Locked]
    public int $siteId;

    #[Locked]
    public int $pageId;

    // Toggle for surfacing archived tiles + the Unarchive button on each.
    public bool $showArchived = false;

    // Add-tile modal state
    public bool $showAddModal = false;
    public string $newTitle = '';
    public string $newDescription = '';
    public string $newCategory = '';
    public string $newImageMode = 'none'; // 'upload' | 'none'

    public function mount(int $siteId, int $pageId): void
    {
        $this->siteId = $siteId;
        $this->pageId = $pageId;
        $this->assertAuthorizedSiteAccess();
    }

    public function items()
    {
        $q = ProjectItem::where('site_id', $this->siteId)
            ->where('page_id', $this->pageId)
            ->where('type', ProjectItemType::Gallery->value)
            ->orderBy('sort_order');

        if (! $this->showArchived) {
            $q->where('status', '!=', ProjectItemStatus::Archived->value);
        }

        return $q->get();
    }

    public function archivedCount(): int
    {
        return ProjectItem::where('site_id', $this->siteId)
            ->where('page_id', $this->pageId)
            ->where('type', ProjectItemType::Gallery->value)
            ->where('status', ProjectItemStatus::Archived->value)
            ->count();
    }

    public function toggleShowArchived(): void
    {
        $this->showArchived = ! $this->showArchived;
    }

    /**
     * Refresh trigger from project-item-card after archive/unarchive — also
     * the wire:poll hook used while categorisation jobs are in flight.
     * Listed methods just touch the component so Livewire re-renders;
     * items() re-queries automatically on the next render pass.
     */
    public function refreshList(): void
    {
        // No-op — exists so wire:poll has a target.
    }

    /**
     * Are any FB-imported items still being categorised? Drives wire:poll
     * so the card re-renders when CategoriseImportedPhotoJob lands.
     */
    public function pendingCategorisation(): bool
    {
        return ProjectItem::where('site_id', $this->siteId)
            ->where('page_id', $this->pageId)
            ->where('source', ProjectItemSource::FacebookImport)
            ->where('created_at', '>', now()->subMinutes(2))
            ->where(function ($q) {
                $q->whereNull('metadata')
                    ->orWhere('metadata->ai_categorised', '!=', true)
                    ->orWhereNull('metadata->ai_categorised');
            })
            ->exists();
    }

    public function openAddModal(): void
    {
        $this->assertAuthorizedSiteAccess();
        $this->showAddModal = true;
    }

    public function closeAddModal(): void
    {
        $this->reset(['showAddModal', 'newTitle', 'newDescription', 'newCategory']);
        $this->newImageMode = 'none';
    }

    public function addTile(): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        // The new row's page_id comes from client-visible state — resolve
        // it through the authorised site so a tile can never be planted on
        // another tenant's page.
        $page = $this->authorizedPage($site);
        if (! $page) {
            return;
        }

        $this->validate([
            'newTitle' => 'required|string|max:120',
            'newDescription' => 'required|string|max:280',
            'newCategory' => ['required', 'string', 'in:'.(empty($site->project_categories) ? '__none__' : implode(',', $site->project_categories))],
            'newImageMode' => 'required|in:upload,none',
        ]);

        $nextSort = (int) ProjectItem::where('site_id', $site->id)
            ->where('page_id', $page->id)
            ->where('type', ProjectItemType::Gallery->value)
            ->max('sort_order');

        $item = ProjectItem::create([
            'site_id' => $site->id,
            'page_id' => $page->id,
            'type' => ProjectItemType::Gallery,
            'sort_order' => $nextSort + 1,
            'status' => ProjectItemStatus::Draft,
            'source' => ProjectItemSource::AgentAdded,
            'category' => $this->newCategory,
            'title' => $this->newTitle,
            'description' => $this->newDescription,
            'image_job_state' => null,
            'metadata' => null,
        ]);

        // Upload mode: the agent will use the per-card "Replace with upload"
        // control after the card renders. Null mode leaves placeholder tile.

        $this->closeAddModal();
    }

    /**
     * Resolve $pageId inside the authorised site. Never resolve it
     * globally — it is client-visible state, so a global find() lets a
     * tampered id reach another tenant's page.
     */
    private function authorizedPage(Site $site): ?GeneratedPage
    {
        return $site->generatedPages()->whereKey($this->pageId)->first();
    }

    /**
     * Keep only ids that really are gallery tiles on this site (and on the
     * authorised page, when it resolves), preserving the client's order.
     *
     * The scoped UPDATE below already refuses to move a foreign row, but the
     * RAW array used to be written into the revision's section.item_ids —
     * and SitePublishService then promoted / snapshotted every id it found
     * there. That laundered a foreign id into a cross-tenant WRITE. Unknown
     * ids are dropped, never reordered into the persisted array.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function ownedItemIds(Site $site, ?GeneratedPage $page, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $owned = ProjectItem::where('site_id', $site->id)
            ->when($page, fn ($q) => $q->where('page_id', $page->id))
            ->where('type', ProjectItemType::Gallery->value)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_filter($ids, fn (int $id) => in_array($id, $owned, true)));
    }

    /**
     * Same order-preserving filter, scoped to the site only — used for ids
     * already pinned in the revision (which may be archived or of another
     * section's type but must still belong to this tenant).
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function siteOwnedIds(Site $site, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $owned = ProjectItem::where('site_id', $site->id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_filter($ids, fn (int $id) => in_array($id, $owned, true)));
    }

    public function reorder(array $itemIds): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $page = $this->authorizedPage($site);
        $requested = array_values(array_unique(array_map('intval', $itemIds)));
        $intIds = $this->ownedItemIds($site, $page, $requested);

        foreach ($intIds as $index => $id) {
            ProjectItem::where('id', $id)
                ->where('site_id', $site->id)
                ->update(['sort_order' => $index]);
        }

        // PageRenderer reads the rendered order from
        // section.item_ids in the page revision's content_data, NOT
        // from ProjectItem.sort_order. Without writing the new order
        // back to the revision the front-end keeps showing the old
        // order forever — banner says "pending change", click Publish,
        // nothing changes on the public page.
        if ($page) {
            $current = $page->draftRevision
                ?? $page->publishedRevision
                ?? $page->revisions()->latest('id')->first();
            $content = $current?->content_data ?? $page->content_data ?? [];
            $sections = $content['sections'] ?? [];
            $changed = false;
            foreach ($sections as $i => $section) {
                if (($section['type'] ?? '') !== 'project_gallery') {
                    continue;
                }
                $existing = array_map(fn ($id) => (int) $id, $section['item_ids'] ?? []);
                // Preserve any pinned IDs not surfaced in the editor (e.g. archived)
                // by appending them after the new ordered set — but only ones
                // that belong to this site, so a previously laundered foreign
                // id is scrubbed out rather than carried forward.
                $missing = array_values(array_diff($existing, $intIds));
                $missing = $this->siteOwnedIds($site, $missing);
                $newOrder = array_merge($intIds, $missing);
                if ($newOrder !== $existing) {
                    $sections[$i]['item_ids'] = $newOrder;
                    $changed = true;
                }
            }
            if ($changed) {
                $content['sections'] = $sections;
                app(\App\Services\Site\PageService::class)->replaceContent(
                    $page,
                    $content,
                    aiGenerated: false,
                    userId: auth()->id(),
                );
            }
        }

        // Reorder is a content change agents can publish or discard —
        // nudge the unpublished-changes banner so the new order shows
        // up as a pending change.
        $this->dispatch('composition-dirty');
    }
};
?>

<div data-livewire-component="projects-gallery-editor" class="space-y-4"
     @if ($this->pendingCategorisation()) wire:poll.5s="refreshList" @endif>
    <div class="flex items-center justify-between">
        <h3 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Gallery</h3>
        <div class="flex items-center gap-2">
            @php $archivedCount = $this->archivedCount(); @endphp
            @if ($archivedCount > 0)
                <button type="button"
                        wire:click="toggleShowArchived"
                        class="rounded-md border border-zinc-200 dark:border-neutral-700 px-2.5 py-1.5 text-xs font-medium text-zinc-600 dark:text-zinc-300 hover:bg-zinc-50 dark:hover:bg-neutral-800">
                    {{ $showArchived ? 'Hide archived' : 'Show archived' }} ({{ $archivedCount }})
                </button>
            @endif
            <button type="button"
                    wire:click="openAddModal"
                    class="rounded-md bg-zinc-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-zinc-700 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300">
                + Add tile
            </button>
        </div>
    </div>

    {{-- Drag-and-drop reorder via SortableJS — exposed on window in
         resources/js/app.js. Skipped while Show archived is on so agents
         don't accidentally re-sort archived tiles into the live order
         (sort_order on archived rows is preserved but irrelevant since
         the renderer filters them out). --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4"
         @if (! $showArchived)
         x-data
         x-init="
            const grid = $el;
            new Sortable(grid, {
                animation: 180,
                handle: '.tile-drag-handle',
                ghostClass: 'opacity-40',
                draggable: '[data-item-id]',
                onEnd: () => {
                    const orderedIds = [...grid.querySelectorAll('[data-item-id]')]
                        .map(el => el.dataset.itemId);
                    $wire.call('reorder', orderedIds);
                },
            });
         "
         @endif>
        @forelse ($this->items() as $item)
            <livewire:project-item-card :item-id="$item->id" :key="'card-'.$item->id" lazy.bundle />
        @empty
            <p class="col-span-full text-sm text-zinc-500 dark:text-zinc-400">
                No gallery tiles yet.
            </p>
        @endforelse
    </div>

    @if ($showAddModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl dark:bg-neutral-900">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Add tile</h3>

                <div class="space-y-3">
                    <label class="block text-sm">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Title</span>
                        <input type="text"
                               wire:model="newTitle"
                               maxlength="120"
                               class="mt-1 w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 dark:border-neutral-700 dark:bg-neutral-900">
                    </label>
                    @error('newTitle')<p class="text-xs text-red-600">{{ $message }}</p>@enderror

                    <label class="block text-sm">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Description</span>
                        <textarea wire:model="newDescription"
                                  rows="2"
                                  maxlength="280"
                                  class="mt-1 w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                    </label>
                    @error('newDescription')<p class="text-xs text-red-600">{{ $message }}</p>@enderror

                    <label class="block text-sm">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Category</span>
                        <select wire:model="newCategory"
                                class="mt-1 w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">Choose…</option>
                            @foreach (\App\Models\Site::find($siteId)?->project_categories ?? [] as $c)
                                <option value="{{ $c }}">{{ $c }}</option>
                            @endforeach
                        </select>
                    </label>
                    @error('newCategory')<p class="text-xs text-red-600">{{ $message }}</p>@enderror

                    <div>
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Image</span>
                        <div class="mt-1 flex flex-col gap-1 text-sm">
                            <label class="inline-flex items-center gap-2">
                                <input type="radio" wire:model="newImageMode" value="upload">
                                <span>I'll upload after</span>
                            </label>
                            <label class="inline-flex items-center gap-2">
                                <input type="radio" wire:model="newImageMode" value="none">
                                <span>Add without image</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button"
                            wire:click="closeAddModal"
                            class="rounded-md border border-zinc-200 px-3 py-1.5 text-sm hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
                        Cancel
                    </button>
                    <button type="button"
                            wire:click="addTile"
                            class="rounded-md bg-zinc-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-zinc-700 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300">
                        Add
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

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

    public bool $showAddModal = false;
    public string $newTitle = '';
    public string $newDescription = '';
    public string $newCategory = '';
    public string $newImageMode = 'none';

    public const MAX_CASE_STUDIES = 3;

    public function mount(int $siteId, int $pageId): void
    {
        $this->siteId = $siteId;
        $this->pageId = $pageId;
        $this->assertAuthorizedSiteAccess();
    }

    public function items()
    {
        return ProjectItem::where('site_id', $this->siteId)
            ->where('page_id', $this->pageId)
            ->where('type', ProjectItemType::CaseStudy->value)
            ->where('status', '!=', ProjectItemStatus::Archived->value)
            ->orderBy('sort_order')
            ->get();
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
     * Keep only ids that really are case studies on this site (and on the
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
            ->where('type', ProjectItemType::CaseStudy->value)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_filter($ids, fn (int $id) => in_array($id, $owned, true)));
    }

    /**
     * Same order-preserving filter, scoped to the site only — used for ids
     * already pinned in the revision (which may be archived but must still
     * belong to this tenant).
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
                ->where('type', ProjectItemType::CaseStudy->value)
                ->update(['sort_order' => $index]);
        }

        // PageRenderer renders by section.item_ids order, not
        // ProjectItem.sort_order — write the new order back to the
        // case_study_highlights section via PageService::replaceContent
        // so the public page picks it up on next render.
        if ($page) {
            $current = $page->draftRevision
                ?? $page->publishedRevision
                ?? $page->revisions()->latest('id')->first();
            $content = $current?->content_data ?? $page->content_data ?? [];
            $sections = $content['sections'] ?? [];
            $changed = false;
            foreach ($sections as $i => $section) {
                if (($section['type'] ?? '') !== 'case_study_highlights') {
                    continue;
                }
                $existing = array_map(fn ($id) => (int) $id, $section['item_ids'] ?? []);
                // Only carry forward pinned ids that belong to this site, so
                // a previously laundered foreign id is scrubbed out.
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

        $this->dispatch('composition-dirty');
    }

    public function addCaseStudy(): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        // The new row's page_id comes from client-visible state — resolve
        // it through the authorised site so a case study can never be
        // planted on another tenant's page.
        $page = $this->authorizedPage($site);
        if (! $page) {
            return;
        }

        $activeCount = ProjectItem::where('site_id', $site->id)
            ->where('page_id', $page->id)
            ->where('type', ProjectItemType::CaseStudy->value)
            ->where('status', '!=', ProjectItemStatus::Archived->value)
            ->count();

        if ($activeCount >= self::MAX_CASE_STUDIES) {
            $this->addError('newTitle', 'Max '.self::MAX_CASE_STUDIES.' case studies per page.');

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
            ->where('type', ProjectItemType::CaseStudy->value)
            ->max('sort_order');

        $item = ProjectItem::create([
            'site_id' => $site->id,
            'page_id' => $page->id,
            'type' => ProjectItemType::CaseStudy,
            'sort_order' => $nextSort + 1,
            'status' => ProjectItemStatus::Draft,
            'source' => ProjectItemSource::AgentAdded,
            'category' => $this->newCategory,
            'title' => $this->newTitle,
            'description' => $this->newDescription,
            'metrics' => [],
            'image_job_state' => null,
            'metadata' => null,
        ]);

        $this->closeAddModal();
    }
};
?>

<div class="space-y-4" data-livewire-component="case-study-editor">
    <div class="flex items-center justify-between">
        <h3 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Case studies</h3>
        <button type="button"
                wire:click="openAddModal"
                class="rounded-md bg-zinc-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-zinc-700 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300">
            + Add case study
        </button>
    </div>

    {{-- Drag-and-drop reorder via SortableJS — same wiring as
         projects-gallery-editor. Case studies render in a vertical
         stack rather than a grid, but Sortable doesn't care about
         layout direction. --}}
    <div class="space-y-4"
         x-data
         x-init="
            const stack = $el;
            new Sortable(stack, {
                animation: 180,
                handle: '.tile-drag-handle',
                ghostClass: 'opacity-40',
                draggable: '[data-item-id]',
                onEnd: () => {
                    const orderedIds = [...stack.querySelectorAll('[data-item-id]')]
                        .map(el => el.dataset.itemId);
                    $wire.call('reorder', orderedIds);
                },
            });
         ">
        @forelse ($this->items() as $item)
            <livewire:project-item-card :item-id="$item->id" :key="'cs-'.$item->id" lazy.bundle />
        @empty
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                No case studies yet.
            </p>
        @endforelse
    </div>

    @if ($showAddModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl dark:bg-neutral-900">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Add case study</h3>

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
                                  rows="3"
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
                            wire:click="addCaseStudy"
                            class="rounded-md bg-zinc-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-zinc-700 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300">
                        Add
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

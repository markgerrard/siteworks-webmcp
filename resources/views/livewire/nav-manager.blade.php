<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Livewire\Concerns\DemoUnavailable;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;
    use DemoUnavailable;
    #[Locked]
    public int $siteId;

    public bool $enabled = false;

    public array $items = [];

    public string $newGroupName = '';

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $site = $this->findAuthorizedSite();
        $preview = $site?->latestPreview;
        $snapshot = $preview?->snapshot ?? [];
        $navigation = $snapshot['navigation'] ?? [];

        if (! empty($navigation) && isset($navigation['enabled'])) {
            $this->enabled = (bool) $navigation['enabled'];
            $this->items = $navigation['items'] ?? [];
            $this->appendOrphanPages($snapshot);
        } else {
            $this->buildDefaultItems($snapshot);
        }
    }

    /** Auto-add any pages that exist in the snapshot but aren't represented in
     *  the saved nav (top-level or inside any group). Keeps Contact at the
     *  end. Called after loading saved items so newly-generated pages don't
     *  silently disappear from the nav manager. */
    private function appendOrphanPages(array $snapshot): void
    {
        $allPages = array_keys($snapshot['pages'] ?? []);
        $navLabels = $snapshot['nav_labels'] ?? [];

        $represented = [];
        foreach ($this->items as $item) {
            if (! empty($item['page'])) {
                $represented[] = $item['page'];
            }
            foreach ($item['children'] ?? [] as $child) {
                if (! empty($child['page'])) {
                    $represented[] = $child['page'];
                }
            }
        }

        $missing = array_values(array_diff($allPages, $represented, ['home']));
        if (empty($missing)) {
            return;
        }

        // Extract contact (if present) so we can re-anchor it at the end.
        $contactIndex = null;
        foreach ($this->items as $i => $item) {
            if (($item['page'] ?? null) === 'contact') {
                $contactIndex = $i;
                break;
            }
        }
        $contactItem = $contactIndex !== null ? $this->items[$contactIndex] : null;
        if ($contactIndex !== null) {
            array_splice($this->items, $contactIndex, 1);
        }

        foreach ($missing as $page) {
            if ($page === 'contact') {
                continue;
            }
            $default = ucwords(str_replace('-', ' ', $page));
            $this->items[] = [
                'page' => $page,
                'nav_label' => $navLabels[$page] ?? $default,
                'footer_label' => $navLabels[$page] ?? $default,
            ];
        }

        if ($contactItem) {
            $this->items[] = $contactItem;
        } elseif (in_array('contact', $allPages, true)) {
            $this->items[] = [
                'page' => 'contact',
                'nav_label' => $navLabels['contact'] ?? 'Contact',
                'footer_label' => $navLabels['contact'] ?? 'Contact Us',
            ];
        }
    }

    /** Build default navigation items from existing pages in the snapshot. */
    private function buildDefaultItems(array $snapshot): void
    {
        $allPages = $snapshot['pages'] ?? [];
        $navLabels = $snapshot['nav_labels'] ?? [];
        // 'projects' is its own archetype (portfolio page), not a service —
        // exclude it from the service-page bucket so it can land in its
        // own slot between services and contact.
        $archetypePages = ['home', 'about', 'contact', 'projects'];
        $allPageKeys = array_keys($allPages);
        $servicePageKeys = array_values(array_diff($allPageKeys, $archetypePages));

        $items = [];

        // About first (if exists)
        if (in_array('about', $allPageKeys)) {
            $items[] = [
                'page' => 'about',
                'nav_label' => $navLabels['about'] ?? 'About',
                'footer_label' => $navLabels['about'] ?? 'About Us',
            ];
        }

        // Service pages as individual items (user can group them later)
        foreach ($servicePageKeys as $page) {
            $defaultLabel = ucwords(str_replace('-', ' ', $page));
            $items[] = [
                'page' => $page,
                'nav_label' => $navLabels[$page] ?? $defaultLabel,
                'footer_label' => $navLabels[$page] ?? $defaultLabel,
            ];
        }

        // Projects (portfolio) page slot — between services and contact when
        // present. Admin can drag elsewhere via the custom-nav UI.
        if (in_array('projects', $allPageKeys)) {
            $items[] = [
                'page' => 'projects',
                'nav_label' => $navLabels['projects'] ?? 'Our Work',
                'footer_label' => $navLabels['projects'] ?? 'Our Work',
            ];
        }

        // Contact last
        if (in_array('contact', $allPageKeys)) {
            $items[] = [
                'page' => 'contact',
                'nav_label' => $navLabels['contact'] ?? 'Contact',
                'footer_label' => $navLabels['contact'] ?? 'Contact Us',
            ];
        }

        $this->items = $items;
    }

    /**
     * Enable or disable custom navigation and persist everywhere.
     *
     * Routes through save() (not just saveToSnapshot) so the toggle
     * also bumps admin_revision and surfaces the unpublished-changes
     * banner — previously toggling to disabled was an invisible
     * mutation since the "Save Navigation" button is only rendered
     * inside the $enabled block.
     */
    public function toggle(): void
    {
        $this->enabled = ! $this->enabled;
        $this->save();
    }

    /** Update a label field on a nav item. */
    public function updateLabel(int $index, string $field, string $value): void
    {
        if (! in_array($field, ['nav_label', 'footer_label'], true)) {
            return;
        }

        if (isset($this->items[$index])) {
            $this->items[$index][$field] = $value;
        }
    }

    /** Create a new group item. */
    public function createGroup(): void
    {
        $name = trim($this->newGroupName);
        if ($name === '') {
            return;
        }

        // Insert before contact (which is always last)
        $contactIndex = null;
        foreach ($this->items as $i => $item) {
            if (($item['page'] ?? '') === 'contact') {
                $contactIndex = $i;
                break;
            }
        }

        $group = [
            'page' => '_group_'.str()->slug($name),
            'type' => 'group',
            'nav_label' => $name,
            'children' => [],
        ];

        if ($contactIndex !== null) {
            array_splice($this->items, $contactIndex, 0, [$group]);
        } else {
            $this->items[] = $group;
        }

        $this->newGroupName = '';
    }

    /** Move a top-level page item into a group's children. */
    public function moveToGroup(int $itemIndex, int $groupIndex): void
    {
        if (! isset($this->items[$itemIndex]) || ! isset($this->items[$groupIndex])) {
            return;
        }

        $item = $this->items[$itemIndex];

        // Don't move groups into groups, or contact into groups
        if (($item['type'] ?? '') === 'group' || ($item['page'] ?? '') === 'contact') {
            return;
        }

        // Target must be a group
        if (($this->items[$groupIndex]['type'] ?? '') !== 'group') {
            return;
        }

        // Remove from top level
        array_splice($this->items, $itemIndex, 1);

        // Recalculate group index after removal
        $newGroupIndex = $itemIndex < $groupIndex ? $groupIndex - 1 : $groupIndex;

        // Add to group children
        $this->items[$newGroupIndex]['children'][] = [
            'page' => $item['page'],
            'nav_label' => $item['nav_label'],
            'footer_label' => $item['footer_label'] ?? $item['nav_label'],
        ];
    }

    /** Move a child out of a group back to top level (inserted before the group). */
    public function removeFromGroup(int $groupIndex, int $childIndex): void
    {
        if (! isset($this->items[$groupIndex]['children'][$childIndex])) {
            return;
        }

        $child = $this->items[$groupIndex]['children'][$childIndex];
        array_splice($this->items[$groupIndex]['children'], $childIndex, 1);

        // If group is now empty, remove it
        if (empty($this->items[$groupIndex]['children'])) {
            array_splice($this->items, $groupIndex, 1);
            // Insert the freed child before where the group was
            array_splice($this->items, $groupIndex, 0, [$child]);
        } else {
            // Insert before the group
            array_splice($this->items, $groupIndex, 0, [$child]);
        }
    }

    /**
     * Remove a group, freeing any pages it held back to top level.
     *
     * Until this existed, the only way a group could disappear was as a side
     * effect of its LAST child leaving via removeFromGroup(), so a group
     * created and never filled could not be removed from the UI at all.
     * Empty groups are then dropped silently by translateItemsToComposition(),
     * which meant the client kept seeing a group the public site never had.
     *
     * Children are promoted, never deleted: a group holds real pages, and
     * dropping them here would remove pages from the nav as a side effect of
     * tidying it -- a worse bug than the one this fixes. Removing a page from
     * the nav is a separate action with its own confirmation story.
     */
    public function removeGroup(int $index): void
    {
        $group = $this->items[$index] ?? null;

        if (($group['type'] ?? null) !== 'group') {
            return;
        }

        $children = $group['children'] ?? [];

        array_splice($this->items, $index, 1, $children);
    }

    /** Reorder top-level items (contact is locked last). */
    public function reorderItems(int $from, int $to): void
    {
        if ($from === $to || ! isset($this->items[$from]) || ! isset($this->items[$to])) {
            return;
        }

        // Don't move contact
        if (($this->items[$from]['page'] ?? '') === 'contact') {
            return;
        }

        // Don't drop onto contact's position
        $contactIndex = null;
        foreach ($this->items as $i => $item) {
            if (($item['page'] ?? '') === 'contact') {
                $contactIndex = $i;
                break;
            }
        }
        if ($to === $contactIndex) {
            return;
        }

        $moved = array_splice($this->items, $from, 1);
        array_splice($this->items, $to, 0, $moved);
    }

    public function reorderChildren(int $groupIndex, int $from, int $to): void
    {
        if (! isset($this->items[$groupIndex]['children'][$from]) || ! isset($this->items[$groupIndex]['children'][$to])) {
            return;
        }
        $children = $this->items[$groupIndex]['children'];
        $moved = array_splice($children, $from, 1);
        array_splice($children, $to, 0, $moved);
        $this->items[$groupIndex]['children'] = $children;
    }

    /** Persist navigation config to the preview snapshot AND composition.nav. */
    public function save(): void
    {
        $this->saveToSnapshot();
        $this->saveToComposition();
        $this->dispatch('composition-dirty');
        session()->flash('nav-msg', 'Navigation settings saved.');
    }

    /**
     * Admin-triggered rebuild of the nav via OrganiseNavJob. Dispatches with
     * adminTriggered=true per Contract 2 so the job wraps its composition
     * write in applyAdminChange (bumps admin_revision + surfaces the
     * unpublished-changes banner). Respects the monthly credit gate.
     */
    public function rebuildNav(): void
    {
        $this->demoUnavailable('nav rebuild');
    }

    protected function demoNoticeChannel(): string
    {
        return 'nav-msg';
    }

    /** Write navigation data into the snapshot with a lock. */
    private function saveToSnapshot(): void
    {
        $site = $this->findAuthorizedSite();
        $preview = $site?->latestPreview;
        if (! $preview) {
            return;
        }

        app(\App\Services\PreviewSnapshotWriter::class)->mutate($preview, function (&$snapshot) {
            $snapshot['navigation'] = [
                'enabled' => $this->enabled,
                'items' => $this->items,
            ];
        });
    }

    /**
     * Mirror nav items into composition.nav.items (versioned-renderer shape).
     *
     * nav-manager uses a legacy shape where pages are referenced by
     * page_type string (e.g. 'about'); the versioned renderer expects
     * page_id (int). Translate here, look up page_id from GeneratedPage,
     * and drop any item whose page can't be resolved. Writes via
     * CompositionService so admin_revision bumps and the unpublished-
     * changes banner surfaces.
     *
     * When custom nav is disabled, reset composition.nav.items to the
     * system defaults (all published pages, auto-ordered). Without this,
     * toggling off leaves the previously-saved custom items in
     * composition and the public site keeps rendering them.
     */
    private function saveToComposition(): void
    {
        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        $cs = app(\App\Services\Site\CompositionService::class);
        $draft = $cs->getOrCreateDraft($site);

        if (! $this->enabled) {
            $defaults = app(\App\Services\Site\CompositionDefaults::class)->forSite($site);
            $cs->updateNav(
                $draft,
                $defaults['nav']['items'] ?? [],
                \App\Enums\MutationSource::Admin,
                auth()->id(),
            );

            return;
        }

        // page_type → page_id lookup for O(1) translation during render.
        $pageIds = \App\Models\GeneratedPage::where('site_id', $site->id)
            ->pluck('id', 'page_type')
            ->all();

        $cs->updateNav(
            $draft,
            $this->translateItemsToComposition($this->items, $pageIds),
            \App\Enums\MutationSource::Admin,
            auth()->id(),
        );
    }

    /**
     * Map nav-manager's { page, nav_label, footer_label, children } shape
     * into composition's { type, label, page_id, footer_label, children }
     * shape. Skips items referencing pages that don't exist (stale
     * entries, pages deleted since nav was configured).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, int>  $pageIds
     * @return array<int, array<string, mixed>>
     */
    private function translateItemsToComposition(array $items, array $pageIds): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = $item['type'] ?? null;

            // Group: translate children recursively; drop empty groups.
            if ($type === 'group') {
                $children = $this->translateItemsToComposition($item['children'] ?? [], $pageIds);
                if (empty($children)) {
                    continue;
                }
                $out[] = [
                    'type' => 'group',
                    'label' => $item['nav_label'] ?? ($item['label'] ?? ''),
                    'footer_label' => $item['footer_label'] ?? null,
                    'children' => $children,
                ];
                continue;
            }

            // Shop / news / other non-page types pass through with a label.
            if ($type !== null && $type !== 'page') {
                $out[] = [
                    'type' => $type,
                    'label' => $item['nav_label'] ?? ($item['label'] ?? ucfirst($type)),
                ];
                continue;
            }

            // Page item: resolve page_type → page_id; drop if unknown.
            $pageType = $item['page'] ?? null;
            if (! is_string($pageType) || ! isset($pageIds[$pageType])) {
                continue;
            }
            $out[] = [
                'type' => 'page',
                'label' => $item['nav_label'] ?? ucwords(str_replace('-', ' ', $pageType)),
                'footer_label' => $item['footer_label'] ?? null,
                'page_id' => (int) $pageIds[$pageType],
            ];
        }

        return $out;
    }

    /** Collect groups for the "move to group" dropdown. */
    public function with(): array
    {
        $groups = [];
        foreach ($this->items as $i => $item) {
            if (($item['type'] ?? '') === 'group') {
                $groups[$i] = $item['nav_label'];
            }
        }

        return [
            'groups' => $groups,
        ];
    }
};
?>

<div>
    @if (session('nav-msg'))
        <flux:callout variant="success" icon="check-circle" class="mb-4">
            {{ session('nav-msg') }}
        </flux:callout>
    @endif

    @if (session('nav-mgr-err'))
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-4">
            {{ session('nav-mgr-err') }}
        </flux:callout>
    @endif

    {{-- Rebuild navigation from the current pages. --}}
    <div class="flex items-start justify-between gap-4 p-3 rounded-lg border border-zinc-200 dark:border-neutral-700 mb-4">
        <div>
            <div class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Rebuild navigation</div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                Regenerate the nav structure from scratch using the current pages. Useful after a batch of page changes.
            </p>
        </div>
        @unless ($demo)
        <x-confirm-button
            name="rebuild-nav"
            icon="arrow-path"
            size="sm"
            triggerVariant="primary"
            triggerLabel="Rebuild"
            title="Rebuild navigation?"
            description="All manual nav edits will be overwritten by a fresh structure generated from the current pages."
            confirmLabel="Rebuild"
            confirmVariant="danger"
            wire:click="rebuildNav"
        />
        @endunless
    </div>

    {{-- Enable/disable toggle --}}
    <div class="flex items-center justify-between p-3 rounded-lg border border-zinc-200 dark:border-neutral-700 mb-4">
        <div>
            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Custom Navigation</span>
            <span class="text-xs text-zinc-400 ml-2">{{ $enabled ? 'Grouped navigation enabled' : 'Using automatic navigation' }}</span>
        </div>
        <button type="button"
                wire:click="toggle"
                class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors cursor-pointer {{ $enabled ? 'bg-amber-500' : 'bg-zinc-300' }}">
            <span class="inline-block h-4 w-4 rounded-full bg-white transition-transform {{ $enabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
        </button>
    </div>

    @if (! $enabled)
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            Using automatic navigation. When there are more than 5 pages, service pages will automatically group under a "Services" dropdown.
            Enable custom navigation to manually arrange items and create groups.
        </p>
    @else
        {{-- Navigation items editor --}}
        <div class="space-y-2 mb-4"
             x-data="{ dragFrom: null, dragOver: null }">
            @foreach ($items as $index => $item)
                @php
                    $isGroup = ($item['type'] ?? '') === 'group';
                    $isContact = ($item['page'] ?? '') === 'contact';
                @endphp
                <div class="rounded-lg border border-zinc-200 dark:border-neutral-700 {{ $isGroup ? 'bg-zinc-50 dark:bg-neutral-800' : '' }}"
                     @if (! $isContact)
                         x-on:dragover.prevent="$event.dataTransfer.dropEffect = 'move'; dragOver = {{ $index }}"
                         x-on:drop.prevent="if (dragFrom !== null && dragFrom !== {{ $index }}) { $wire.reorderItems(dragFrom, {{ $index }}); } dragFrom = null; dragOver = null;"
                         x-bind:class="dragOver === {{ $index }} ? 'ring-2 ring-zinc-400' : ''"
                     @endif
                >
                    <div class="flex items-center gap-3 p-3">
                        {{-- Drag handle --}}
                        @if (! $isContact)
                            <span draggable="true"
                                  x-on:dragstart="$event.dataTransfer.effectAllowed = 'move'; dragFrom = {{ $index }}"
                                  x-on:dragend="dragFrom = null; dragOver = null;"
                                  class="cursor-grab active:cursor-grabbing text-zinc-300 hover:text-zinc-500 dark:text-zinc-600 dark:hover:text-zinc-400">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
                            </span>
                        @else
                            <span class="w-4"></span>
                        @endif

                        @if ($isGroup)
                            {{-- Group header --}}
                            <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                            <input type="text"
                                   value="{{ $item['nav_label'] }}"
                                   wire:change="updateLabel({{ $index }}, 'nav_label', $event.target.value)"
                                   class="text-sm font-medium bg-transparent border-b border-dashed border-zinc-300 dark:border-neutral-600 focus:border-zinc-500 focus:outline-none px-1 py-0.5 w-32">
                            <span class="text-xs text-zinc-400">group</span>
                            <span class="text-xs text-zinc-400 ml-auto">{{ count($item['children'] ?? []) }} items</span>
                            {{-- Until this button existed a group could only disappear by
                                 emptying it one child at a time, so a group created by
                                 mistake and never filled was permanent. --}}
                            <button type="button"
                                    wire:click="removeGroup({{ $index }})"
                                    @if (! empty($item['children']))
                                        wire:confirm="Remove the &quot;{{ $item['nav_label'] }}&quot; group? The {{ count($item['children']) }} page(s) inside it move back to the top level of the menu -- nothing is deleted."
                                    @endif
                                    title="Remove group"
                                    aria-label="Remove group"
                                    class="text-zinc-400 hover:text-red-600 dark:hover:text-red-400 cursor-pointer">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        @else
                            {{-- Regular page item --}}
                            <div class="flex-1 grid grid-cols-1 sm:grid-cols-3 gap-2 items-center">
                                <div>
                                    <label class="text-[10px] uppercase text-zinc-400 block">Nav Label</label>
                                    <input type="text"
                                           value="{{ $item['nav_label'] }}"
                                           wire:change="updateLabel({{ $index }}, 'nav_label', $event.target.value)"
                                           class="text-sm bg-transparent border-b border-dashed border-zinc-300 dark:border-neutral-600 focus:border-zinc-500 focus:outline-none px-1 py-0.5 w-full">
                                </div>
                                <div>
                                    <label class="text-[10px] uppercase text-zinc-400 block">Footer Label</label>
                                    <input type="text"
                                           value="{{ $item['footer_label'] ?? $item['nav_label'] }}"
                                           wire:change="updateLabel({{ $index }}, 'footer_label', $event.target.value)"
                                           class="text-sm bg-transparent border-b border-dashed border-zinc-300 dark:border-neutral-600 focus:border-zinc-500 focus:outline-none px-1 py-0.5 w-full">
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-zinc-400 font-mono">{{ $item['page'] }}</span>
                                    @if (! $isContact && ! empty($groups))
                                        <select wire:change="moveToGroup({{ $index }}, $event.target.value); $event.target.value = ''"
                                                class="text-xs bg-white dark:bg-neutral-900 border border-zinc-200 dark:border-neutral-700 rounded pl-1 pr-6 py-0.5 ml-auto">
                                            <option value="">Move to group...</option>
                                            @foreach ($groups as $gi => $groupName)
                                                <option value="{{ $gi }}">{{ $groupName }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Group children --}}
                    @if ($isGroup && ! empty($item['children']))
                        <div class="border-t border-zinc-200 dark:border-neutral-700 ml-8 mr-3 mb-2"
                             x-data="{ childDragFrom: null, childDragOver: null }">
                            @foreach ($item['children'] as $ci => $child)
                                <div class="flex items-center gap-3 py-2 px-3 transition-all"
                                     x-on:dragover.prevent="$event.dataTransfer.dropEffect = 'move'; childDragOver = {{ $ci }}"
                                     x-on:drop.prevent="if (childDragFrom !== null && childDragFrom !== {{ $ci }}) { $wire.reorderChildren({{ $index }}, childDragFrom, {{ $ci }}); } childDragFrom = null; childDragOver = null;"
                                     x-bind:class="childDragOver === {{ $ci }} ? 'ring-1 ring-zinc-400 rounded' : ''">
                                    <span draggable="true"
                                          x-on:dragstart="$event.dataTransfer.effectAllowed = 'move'; childDragFrom = {{ $ci }}"
                                          x-on:dragend="childDragFrom = null; childDragOver = null;"
                                          class="cursor-grab active:cursor-grabbing text-zinc-300 hover:text-zinc-500 dark:text-zinc-600 dark:hover:text-zinc-400">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M7 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
                                    </span>
                                    <div class="flex-1 grid grid-cols-1 sm:grid-cols-3 gap-2 items-center">
                                        <div>
                                            <input type="text"
                                                   value="{{ $child['nav_label'] }}"
                                                   wire:change="$set('items.{{ $index }}.children.{{ $ci }}.nav_label', $event.target.value)"
                                                   class="text-sm bg-transparent border-b border-dashed border-zinc-300 dark:border-neutral-600 focus:border-zinc-500 focus:outline-none px-1 py-0.5 w-full">
                                        </div>
                                        <div>
                                            <input type="text"
                                                   value="{{ $child['footer_label'] ?? $child['nav_label'] }}"
                                                   wire:change="$set('items.{{ $index }}.children.{{ $ci }}.footer_label', $event.target.value)"
                                                   class="text-sm bg-transparent border-b border-dashed border-zinc-300 dark:border-neutral-600 focus:border-zinc-500 focus:outline-none px-1 py-0.5 w-full">
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs text-zinc-400 font-mono">{{ $child['page'] }}</span>
                                            <button type="button"
                                                    wire:click="removeFromGroup({{ $index }}, {{ $ci }})"
                                                    class="text-xs text-amber-600 dark:text-amber-400 hover:text-amber-800 dark:hover:text-amber-300 underline underline-offset-2 cursor-pointer ml-auto">
                                                Remove
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Add group --}}
        <div class="flex items-center gap-2 mb-4">
            <input type="text"
                   wire:model="newGroupName"
                   wire:keydown.enter="createGroup"
                   placeholder="New group name (e.g. Services)"
                   class="text-sm rounded-md border border-zinc-200 bg-white dark:bg-neutral-900 dark:border-neutral-700 px-3 py-1.5 w-64">
            <flux:button size="sm" variant="ghost" wire:click="createGroup" icon="plus">
                Add Group
            </flux:button>
        </div>

        {{-- Save button --}}
        <div class="flex justify-end">
            <flux:button variant="primary" size="sm" wire:click="save" icon="check">
                Save Navigation
            </flux:button>
        </div>
    @endif
</div>

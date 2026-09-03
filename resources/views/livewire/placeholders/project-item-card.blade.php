{{-- Lazy placeholder for project-item-card. MUST carry data-item-id: the
     gallery/case-study SortableJS reorder reads [data-item-id] from DOM
     order, and a placeholder without it would drop unmounted cards from
     the reordered item_ids written back to the page revision. --}}
<div data-item-id="{{ $itemId }}"
     class="relative rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 animate-pulse">
    <div class="aspect-[4/3] w-full rounded-lg bg-zinc-100 dark:bg-neutral-800"></div>
    <div class="mt-3 h-4 w-2/3 rounded bg-zinc-100 dark:bg-neutral-800"></div>
    <div class="mt-2 h-3 w-1/3 rounded bg-zinc-100 dark:bg-neutral-800"></div>
</div>

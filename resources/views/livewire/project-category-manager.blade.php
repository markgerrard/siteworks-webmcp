<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\ProjectItem;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;

    /**
     * Identifier the client must not rewrite. Unlocked, $wire.set() pointed
     * it at another tenant's site and categories() read that site's
     * vocabulary straight back out.
     */
    #[Locked]
    public int $siteId;

    public string $newCategory = '';
    public ?string $duplicateSuggestion = null;
    public ?string $blockedDeleteCategory = null;
    public int $blockedDeleteCount = 0;

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        // Fail-closed. The old bare findAuthorizedSite() discarded its
        // result and mounted anyway, despite a comment claiming it aborted.
        $this->assertAuthorizedSiteAccess();
    }

    /**
     * Protected #[Computed]: read via $this->categories in the view only.
     * As a public method it was a remotely callable Livewire action with no
     * authorization at all, returning any site's category vocabulary.
     *
     * @return array<int, string>
     */
    #[Computed]
    protected function categories(): array
    {
        return $this->findAuthorizedSite()?->project_categories ?? [];
    }

    public function addCategory(): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $candidate = trim($this->newCategory);

        if ($candidate === '') {
            return;
        }

        $existing = $site->project_categories ?? [];

        // Case-insensitive + crude plural/singular fuzzy match.
        $normalised = strtolower(rtrim($candidate, 's'));
        foreach ($existing as $e) {
            if (strtolower(rtrim($e, 's')) === $normalised) {
                $this->duplicateSuggestion = $e;

                return;
            }
        }

        $this->duplicateSuggestion = null;
        $site->update(['project_categories' => [...$existing, $candidate]]);
        $this->newCategory = '';
    }

    public function renameCategory(string $from, string $to): void
    {
        $to = trim($to);
        if ($to === '' || $from === $to) {
            return;
        }

        $site = $this->assertAuthorizedSiteAccess();

        DB::transaction(function () use ($site, $from, $to) {
            $vocab = $site->project_categories ?? [];
            $vocab = array_values(array_map(fn ($c) => $c === $from ? $to : $c, $vocab));
            $site->update(['project_categories' => $vocab]);

            ProjectItem::where('site_id', $site->id)
                ->where('category', $from)
                ->update(['category' => $to]);
        });
    }

    public function deleteCategory(string $category): void
    {
        $site = $this->assertAuthorizedSiteAccess();

        $referencedCount = ProjectItem::where('site_id', $site->id)
            ->where('category', $category)
            ->count();

        if ($referencedCount > 0) {
            $this->blockedDeleteCategory = $category;
            $this->blockedDeleteCount = $referencedCount;

            return;
        }

        $vocab = array_values(array_filter(
            $site->project_categories ?? [],
            fn ($c) => $c !== $category
        ));
        $site->update(['project_categories' => $vocab]);
        $this->blockedDeleteCategory = null;
        $this->blockedDeleteCount = 0;
    }

    public function clearDuplicateSuggestion(): void
    {
        $this->duplicateSuggestion = null;
    }

    public function clearDeleteBlock(): void
    {
        $this->blockedDeleteCategory = null;
        $this->blockedDeleteCount = 0;
    }
};
?>

<div class="space-y-3" data-livewire-component="project-category-manager">
    <div class="flex flex-wrap gap-2">
        @forelse ($this->categories as $category)
            <span class="inline-flex items-center gap-2 rounded-full border border-zinc-200 bg-white px-3 py-1 text-sm font-medium text-zinc-700 shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-zinc-200">
                {{ $category }}
                <button type="button"
                        class="text-xs text-zinc-400 hover:text-red-600 dark:text-zinc-500 dark:hover:text-red-400"
                        wire:click="deleteCategory(@js($category))"
                        title="Delete category">
                    ×
                </button>
            </span>
        @empty
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                No categories yet. Add one below.
            </p>
        @endforelse
    </div>

    <div class="flex items-center gap-2">
        <input type="text"
               wire:model.live.debounce.300ms="newCategory"
               wire:keydown.enter="addCategory"
               placeholder="Add category (e.g. Residential)"
               class="max-w-xs rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-sm shadow-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-zinc-100">
        <button type="button"
                wire:click="addCategory"
                class="rounded-md bg-zinc-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-zinc-700 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-300">
            Add
        </button>
    </div>

    @if ($duplicateSuggestion)
        <div class="flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm dark:border-amber-700 dark:bg-amber-900/20">
            <span class="text-amber-900 dark:text-amber-200">
                Did you mean <strong>{{ $duplicateSuggestion }}</strong>?
            </span>
            <button type="button"
                    wire:click="clearDuplicateSuggestion"
                    class="ml-auto text-xs text-amber-700 hover:text-amber-900 dark:text-amber-300 dark:hover:text-amber-100">
                Dismiss
            </button>
        </div>
    @endif

    @if ($blockedDeleteCategory)
        <div class="flex items-center gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm dark:border-red-700 dark:bg-red-900/20">
            <span class="text-red-900 dark:text-red-200">
                <strong>{{ $blockedDeleteCategory }}</strong> is used by
                {{ $blockedDeleteCount }} {{ \Illuminate\Support\Str::plural('item', $blockedDeleteCount) }}.
                Recategorise or archive them first.
            </span>
            <button type="button"
                    wire:click="clearDeleteBlock"
                    class="ml-auto text-xs text-red-700 hover:text-red-900 dark:text-red-300 dark:hover:text-red-100">
                Dismiss
            </button>
        </div>
    @endif
</div>

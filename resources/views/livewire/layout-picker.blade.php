<?php

use App\Enums\PreviewLayout;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\Site;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;
    #[Locked]
    public int $siteId;

    public string $layout = 'one_page';

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $site = $this->findAuthorizedSite();
        $this->layout = $site?->preview_layout?->value ?? 'one_page';
    }

    public function setLayout(string $layout): void
    {
        if (! in_array($layout, ['one_page', 'multi_page'], true)) {
            return;
        }

        $site = $this->findAuthorizedSite();
        if (! $site) {
            return;
        }

        $site->update(['preview_layout' => PreviewLayout::from($layout)]);
        $this->layout = $layout;

        // Stamp the latest preview snapshot so the existing slug URL
        // immediately reflects the new layout without a pipeline rebuild.
        $preview = $site->latestPreview;
        if ($preview) {
            app(\App\Services\PreviewSnapshotWriter::class)->mutate($preview, function (&$snapshot) use ($layout) {
                $snapshot['layout'] = $layout;
            });
        }

        // sites.preview_layout is read live by the versioned renderer
        // (PublicSiteController). Bump admin_revision + dispatch so the
        // banner notes the admin intent.
        app(\App\Services\Site\CompositionService::class)
            ->bumpAdminRevision($site, auth()->id());
        $this->dispatch('composition-dirty');

        session()->flash('layout-msg', 'Preview layout set to '.($layout === 'one_page'
            ? 'Single-page scroll'
            : 'Separate pages').'. Refresh the preview to see it.');
    }
};
?>

<div>
    @if (session('layout-msg'))
        <flux:callout variant="success" icon="check-circle" class="mb-4">
            {{ session('layout-msg') }}
        </flux:callout>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <button
            type="button"
            wire:click="setLayout('one_page')"
            class="text-left rounded-xl border-2 p-5 transition-all hover:shadow-md cursor-pointer
                   {{ $layout === 'one_page' ? 'border-amber-500 ring-2 ring-amber-300 bg-amber-50/40 dark:border-amber-400 dark:ring-amber-600 dark:bg-amber-900/20' : 'border-zinc-200 hover:border-zinc-400 dark:border-neutral-700 dark:hover:border-neutral-500' }}"
        >
            <div class="flex items-center justify-between mb-3">
                <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Single-page scroll</span>
                @if ($layout === 'one_page')
                    <span class="text-[10px] font-bold uppercase text-amber-600 dark:text-amber-400">Selected</span>
                @endif
            </div>
            <div class="flex flex-col gap-1">
                <div class="h-2 rounded bg-zinc-300 dark:bg-neutral-600"></div>
                <div class="h-8 rounded bg-zinc-200 dark:bg-neutral-700"></div>
                <div class="h-1.5 rounded bg-zinc-300 dark:bg-neutral-600"></div>
                <div class="h-6 rounded bg-zinc-200 dark:bg-neutral-700"></div>
                <div class="h-1.5 rounded bg-zinc-300 dark:bg-neutral-600"></div>
                <div class="h-6 rounded bg-zinc-200 dark:bg-neutral-700"></div>
            </div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-3">All three pages stacked on one scrollable URL. Anchor links in the nav scroll between sections.</p>
        </button>

        <button
            type="button"
            wire:click="setLayout('multi_page')"
            class="text-left rounded-xl border-2 p-5 transition-all hover:shadow-md cursor-pointer
                   {{ $layout === 'multi_page' ? 'border-amber-500 ring-2 ring-amber-300 bg-amber-50/40 dark:border-amber-400 dark:ring-amber-600 dark:bg-amber-900/20' : 'border-zinc-200 hover:border-zinc-400 dark:border-neutral-700 dark:hover:border-neutral-500' }}"
        >
            <div class="flex items-center justify-between mb-3">
                <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Separate pages</span>
                @if ($layout === 'multi_page')
                    <span class="text-[10px] font-bold uppercase text-amber-600 dark:text-amber-400">Selected</span>
                @endif
            </div>
            <div class="flex gap-1.5">
                <div class="flex-1 rounded border border-zinc-300 dark:border-neutral-600 p-1 flex flex-col gap-1">
                    <div class="h-1.5 rounded bg-zinc-300 dark:bg-neutral-600"></div>
                    <div class="h-6 rounded bg-zinc-200 dark:bg-neutral-700"></div>
                </div>
                <div class="flex-1 rounded border border-zinc-300 dark:border-neutral-600 p-1 flex flex-col gap-1">
                    <div class="h-1.5 rounded bg-zinc-300 dark:bg-neutral-600"></div>
                    <div class="h-6 rounded bg-zinc-200 dark:bg-neutral-700"></div>
                </div>
                <div class="flex-1 rounded border border-zinc-300 dark:border-neutral-600 p-1 flex flex-col gap-1">
                    <div class="h-1.5 rounded bg-zinc-300 dark:bg-neutral-600"></div>
                    <div class="h-6 rounded bg-zinc-200 dark:bg-neutral-700"></div>
                </div>
            </div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-3">Each page is its own URL (/preview/&lt;slug&gt;/&lt;page&gt;). Nav links actually navigate between pages.</p>
        </button>
    </div>
</div>

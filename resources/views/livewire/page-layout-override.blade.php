<?php

use App\Enums\PageStatus;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\PublicPageCache;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;

    #[Locked]
    public int $siteId;

    #[Locked]
    public int $pageId;

    #[Locked]
    public ?string $kind = null;

    #[Locked]
    public ?string $current = null;

    /** @var array<string, array{label: string, description: string|null}> */
    #[Locked]
    public array $options = [];

    /** @var list<string> */
    #[Locked]
    public array $warnings = [];

    #[Locked]
    public bool $detailFollowMismatch = false;

    public function mount(int $siteId, int $pageId): void
    {
        $this->siteId = $siteId;
        $this->pageId = $pageId;
        $site = $this->assertAuthorizedSiteAccess();
        $this->hydrateState($site, $this->resolveOwnedPage($site));
    }

    public function setOverride(?string $key): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $page = $this->resolveOwnedPage($site);
        $registry = app(PageLayoutRegistry::class);
        $kind = $this->pageIsUnavailable($page) ? null : $registry->layoutKindForPage($page);

        if ($kind === null) {
            $this->hydrateState($site, $page);

            throw ValidationException::withMessages(['layout' => 'This page type has no layout presets.']);
        }

        if ($key !== null && ! array_key_exists($key, $registry->optionsFor($site, $kind))) {
            throw ValidationException::withMessages(['layout' => 'The selected layout is not available for this site.']);
        }

        $page->update(['layout_preset_key' => $key]);
        $this->current = $key;
        $this->refreshWarnings($site, $page, $kind);
        app(PublicPageCache::class)->invalidate($site);
    }

    protected function resolveOwnedPage(Site $site): GeneratedPage
    {
        $page = GeneratedPage::query()
            ->where('site_id', $site->id)
            ->find($this->pageId);

        abort_unless($page !== null, 404);

        return $page;
    }

    protected function pageIsUnavailable(GeneratedPage $page): bool
    {
        return $page->status === PageStatus::Archived || $page->archived_at !== null;
    }

    protected function hydrateState(Site $site, GeneratedPage $page): void
    {
        $registry = app(PageLayoutRegistry::class);

        if ($this->pageIsUnavailable($page)) {
            $this->kind = null;
            $this->current = $page->layout_preset_key;
            $this->options = [];
            $this->warnings = [];
            $this->detailFollowMismatch = false;

            return;
        }

        $this->kind = $registry->layoutKindForPage($page);
        $this->current = $page->layout_preset_key;
        $this->options = $this->kind !== null ? $registry->optionsFor($site, $this->kind) : [];
        $this->refreshWarnings($site, $page, $this->kind);
    }

    protected function refreshWarnings(Site $site, GeneratedPage $page, ?string $kind): void
    {
        $registry = app(PageLayoutRegistry::class);
        $this->detailFollowMismatch = $kind === 'projects'
            && $this->current !== null
            && ! array_key_exists($this->current, $registry->optionsFor($site, 'project_detail'));

        if ($kind === null) {
            $this->warnings = [];

            return;
        }

        $recipe = $registry->resolveForPage($site, $page, $kind);
        if ($recipe === null) {
            $this->warnings = [];

            return;
        }

        $sections = $page->content_data['sections'] ?? [];
        if (! is_array($sections)) {
            $sections = [];
        }

        $this->warnings = array_merge(
            $registry->recipeWarnings($recipe, $kind),
            $registry->adjacencyWarnings($sections, $recipe, $kind),
        );
    }
}; ?>

<div data-livewire-component="page-layout-override" data-page-id="{{ $pageId }}">
    @if ($kind === null)
        <p class="text-xs text-zinc-500">No layout presets for this page type.</p>
    @else
        <div class="rounded-lg border border-zinc-200 dark:border-neutral-700 p-4">
            <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-3">Page Layout</h4>
            <div class="flex flex-col gap-1 text-sm">
                <label class="inline-flex items-start gap-2">
                    <input type="radio" name="page_layout_override_{{ $pageId }}" value=""
                           wire:click="setOverride(null)"
                           @checked($current === null)
                           class="mt-0.5 text-zinc-900">
                    <span class="block">Inherit site default</span>
                </label>
                @foreach ($options as $key => $opt)
                    <label class="inline-flex items-start gap-2">
                        <input type="radio" name="page_layout_override_{{ $pageId }}" value="{{ $key }}"
                               wire:click="setOverride(@js($key))"
                               @checked($current === $key)
                               class="mt-0.5 text-zinc-900">
                        <span>
                            <span class="block">{{ $opt['label'] }}</span>
                            @if ($opt['description'])
                                <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $opt['description'] }}</span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>
            @error('layout')
                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
            @enderror
            @foreach ($warnings as $w)
                <p class="mt-1 text-xs text-amber-600">{{ $w }}</p>
            @endforeach
            @if ($kind === 'projects')
                @if ($detailFollowMismatch)
                    <p class="mt-1 text-xs text-amber-600">This layout has no project detail recipe — detail pages will fall back to the classic layout.</p>
                @endif
                <p class="mt-1 text-xs text-zinc-500">Applies immediately to the live site. Project detail pages follow this choice.</p>
            @else
                <p class="mt-1 text-xs text-zinc-500">Applies immediately to the live site. Overrides the site-wide {{ $kind }} layout for this page only.</p>
            @endif
        </div>
    @endif
</div>

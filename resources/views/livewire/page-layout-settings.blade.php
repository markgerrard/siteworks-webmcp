<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\PublicPageCache;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;

    /** @var array<string, string> */
    private const COLUMN_MAP = [
        'service' => 'services_layout',
        'about' => 'about_layout',
        'home' => 'home_layout',
    ];

    #[Locked]
    public int $siteId;

    #[Locked]
    public string $kind;

    public string $currentLayout = 'classic';

    /** @var array<string, array{label: string, description: string|null}> */
    public array $options = [];

    /** @var list<string> */
    #[Locked]
    public array $warnings = [];

    public function mount(int $siteId, string $kind): void
    {
        if (! array_key_exists($kind, self::COLUMN_MAP)) {
            abort(404);
        }

        $this->siteId = $siteId;
        $this->kind = $kind;
        $site = $this->assertAuthorizedSiteAccess();
        $this->currentLayout = $this->layoutKey($site);
        $this->options = app(PageLayoutRegistry::class)->optionsFor($site, $kind);
        $this->refreshWarnings($site);
    }

    public function setLayout(string $layout): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $options = app(PageLayoutRegistry::class)->optionsFor($site, $this->kind);

        if (! array_key_exists($layout, $options)) {
            throw ValidationException::withMessages([
                'layout' => 'The selected layout is not available for this site.',
            ]);
        }

        $site->update([self::COLUMN_MAP[$this->kind] => $layout]);
        $this->currentLayout = $layout;
        $this->options = $options;
        $this->refreshWarnings($site);

        app(PublicPageCache::class)->invalidate($site);
    }

    private function layoutKey(Site $site): string
    {
        $key = $site->{self::COLUMN_MAP[$this->kind]} ?? 'classic';
        if ($key instanceof \BackedEnum) {
            $key = $key->value;
        }

        return is_string($key) && $key !== '' ? $key : 'classic';
    }

    private function refreshWarnings(Site $site): void
    {
        $registry = app(PageLayoutRegistry::class);
        $recipe = $registry->resolve($site, $this->kind);
        if ($recipe === null) {
            $this->warnings = [];

            return;
        }

        $warnings = $registry->recipeWarnings($recipe, $this->kind);
        foreach ($this->pagesForKind($site, $registry) as $page) {
            $sections = $page->content_data['sections'] ?? [];
            if (! is_array($sections)) {
                $sections = [];
            }
            $warnings = array_merge($warnings, $registry->adjacencyWarnings($sections, $recipe, $this->kind));
        }

        $this->warnings = array_values(array_unique($warnings));
    }

    /**
     * @return Collection<int, GeneratedPage>
     */
    private function pagesForKind(Site $site, PageLayoutRegistry $registry): Collection
    {
        $pageTypes = $registry->pageTypesForKind($site, $this->kind);
        if ($pageTypes === []) {
            return collect();
        }

        return GeneratedPage::query()
            ->where('site_id', $site->id)
            ->whereNull('archived_at')
            ->whereIn('page_type', $pageTypes)
            ->get(['id', 'page_type', 'kind', 'content_data']);
    }
}; ?>

<div data-livewire-component="page-layout-settings" data-page-kind="{{ $kind }}">
    <div class="flex flex-col gap-1 text-sm">
        @foreach ($options as $key => $opt)
            <label class="inline-flex items-start gap-2">
                <input type="radio" name="page_layout_{{ $kind }}" value="{{ $key }}"
                       wire:click="setLayout(@js($key))"
                       @checked($currentLayout === $key)
                       class="mt-0.5 text-zinc-900">
                <span>
                    <span class="block">{{ $opt['label'] }}</span>
                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $opt['description'] }}</span>
                </span>
            </label>
        @endforeach
    </div>
    @foreach ($warnings as $w)
        <p class="mt-1 text-xs text-amber-600">{{ $w }}</p>
    @endforeach
</div>

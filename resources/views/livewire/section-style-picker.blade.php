<?php

use App\Exceptions\Site\StaleRevisionException;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\PageService;
use App\Services\Site\SectionSchema;
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
    public int $sectionIndex;

    #[Locked]
    public ?int $baseRevisionId = null;

    #[Locked]
    public ?string $sectionType = null;

    /** @var list<string> */
    public array $options = [];

    public ?string $current = null;

    /** @var array<string, mixed> */
    public array $effectiveOptions = [];

    public function mount(int $siteId, int $pageId, int $sectionIndex): void
    {
        $this->siteId = $siteId;
        $this->pageId = $pageId;
        $this->sectionIndex = $sectionIndex;

        $site = $this->assertAuthorizedSiteAccess();
        $page = GeneratedPage::where('site_id', $site->id)->whereNull('archived_at')->find($pageId);
        abort_unless($page !== null, 404);
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $site = $this->assertAuthorizedSiteAccess();
        $page = GeneratedPage::where('site_id', $site->id)->whereNull('archived_at')->find($this->pageId);
        abort_unless($page !== null, 404);

        $this->hydrateFrom($site, $page);

        return $this->view();
    }

    public function setVariant(?string $variant): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $page = GeneratedPage::where('site_id', $site->id)->whereNull('archived_at')->find($this->pageId);
        abort_unless($page !== null, 404);

        $content = $this->editableContent($page);
        $sections = is_array($content['sections'] ?? null) ? $content['sections'] : [];
        $section = $sections[$this->sectionIndex] ?? null;
        if (! is_array($section)) {
            throw ValidationException::withMessages([
                'variant' => 'This page changed — reload to continue.',
            ]);
        }

        $type = is_string($section['type'] ?? null) ? $section['type'] : '';
        if ($this->sectionType !== null && $type !== $this->sectionType) {
            throw ValidationException::withMessages([
                'variant' => 'This page changed — reload to continue.',
            ]);
        }

        $errors = app(SectionSchema::class)->validateField($type, 'variant', $variant);
        if ($errors !== []) {
            throw ValidationException::withMessages(['variant' => $errors]);
        }

        try {
            app(PageService::class)->editField(
                $page,
                "sections.{$this->sectionIndex}.variant",
                $variant,
                userId: auth()->id(),
                expectedBaseRevisionId: $this->baseRevisionId,
            );
        } catch (StaleRevisionException) {
            throw ValidationException::withMessages([
                'variant' => 'This page changed — reload to continue.',
            ]);
        }
    }

    private function hydrateFrom(Site $site, GeneratedPage $page): void
    {
        $content = $this->editableContent($page);
        $sections = is_array($content['sections'] ?? null) ? $content['sections'] : [];
        $section = is_array($sections[$this->sectionIndex] ?? null) ? $sections[$this->sectionIndex] : [];
        $type = is_string($section['type'] ?? null) ? $section['type'] : '';

        $this->options = $type !== '' ? app(SectionSchema::class)->variantOptionsFor($type) : [];
        $this->current = is_string($section['variant'] ?? null) ? $section['variant'] : null;
        $this->baseRevisionId = $page->draft_revision_id ?? $page->published_revision_id;
        $this->sectionType = $type !== '' ? $type : null;
        $this->effectiveOptions = $this->effectiveOptionsFor($site, $page, $type);
    }

    /**
     * Recipe-owned option knobs when the recipe names this family.
     * Surface is renderer metadata, not a picker option.
     *
     * @return array<string, mixed>
     */
    private function effectiveOptionsFor(Site $site, GeneratedPage $page, string $type): array
    {
        if ($type === '') {
            return [];
        }

        $registry = app(PageLayoutRegistry::class);
        $kind = $registry->layoutKindForPage($page);
        $recipe = $kind ? $registry->resolveForPage($site, $page, $kind) : null;
        if (! is_array($recipe)) {
            return [];
        }

        $variants = is_array($recipe['variants'] ?? null) ? $recipe['variants'] : [];
        if (! array_key_exists($type, $variants)) {
            return [];
        }

        return is_array($recipe['options'] ?? null) ? $recipe['options'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function editableContent(GeneratedPage $page): array
    {
        $rid = $page->draft_revision_id ?? $page->published_revision_id;

        return $rid ? (PageRevision::find($rid)?->content_data ?? []) : [];
    }
}; ?>

<div data-livewire-component="section-style-picker" data-page-id="{{ $pageId }}" data-section-index="{{ $sectionIndex }}">
    @if ($baseRevisionId === null)
        <p class="text-xs text-zinc-500 dark:text-zinc-400">No presets — this page has no editable revision yet.</p>
    @elseif ($options !== [])
        <div class="flex flex-col gap-1 text-sm">
            <label class="inline-flex items-start gap-2">
                <input type="radio" name="section_style_{{ $pageId }}_{{ $sectionIndex }}" value=""
                       wire:click="setVariant(null)"
                       @checked($current === null)
                       class="mt-0.5 text-zinc-900">
                <span>Inherit (site preset)</span>
            </label>
            @foreach ($options as $variant)
                <label class="inline-flex items-start gap-2">
                    <input type="radio" name="section_style_{{ $pageId }}_{{ $sectionIndex }}" value="{{ $variant }}"
                           wire:click="setVariant(@js($variant))"
                           @checked($current === $variant)
                           class="mt-0.5 text-zinc-900">
                    <span>{{ $variant }}</span>
                </label>
            @endforeach
        </div>
        @if ($effectiveOptions !== [])
            <dl class="mt-2 space-y-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                @foreach ($effectiveOptions as $key => $value)
                    <div>
                        <dt class="inline font-medium">{{ $key }}</dt>
                        <dd class="inline">: {{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Section style is saved to draft — publish to go live.</p>
    @endif
</div>

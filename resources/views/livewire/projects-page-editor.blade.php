<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Services\Site\PageService;
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
    public ?int $pageId = null;

    public bool $heroEnabled = true;

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        // Resolve the authorised site so policy checks fire; unauthorised callers
        // get a 403 via the concern before any UI renders.
        $site = $this->findAuthorizedSite();
        $page = $site?->generatedPages()
            ->where('page_type', 'projects')
            ->first();
        $this->pageId = $page?->id;

        // Resolve current hero_enabled state from the projects_hero section.
        // Default true when missing.
        $sections = $page?->content_data['sections'] ?? [];
        foreach ($sections as $s) {
            if (($s['type'] ?? null) === 'projects_hero') {
                $this->heroEnabled = (bool) ($s['hero_enabled'] ?? true);
                break;
            }
        }
    }

    public function toggleHero(bool $enabled): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        // Resolve through the authorised site's own pages — a global
        // find() on the client-visible $pageId reaches other tenants.
        $page = $site->generatedPages()->whereKey($this->pageId)->first();
        if (! $page) {
            return;
        }

        $content = $page->content_data;
        $sections = $content['sections'] ?? [];
        foreach ($sections as $i => $s) {
            if (($s['type'] ?? null) === 'projects_hero') {
                $sections[$i]['hero_enabled'] = $enabled;
                break;
            }
        }
        $content['sections'] = $sections;

        // Persist via PageService so it lands on a draft revision (admin
        // must publish to push live), matching how other section edits work.
        app(PageService::class)->replaceContent(
            $page,
            $content,
            aiGenerated: false,
            aiModelVersion: null,
        );

        $this->heroEnabled = $enabled;
    }
};
?>

<div data-livewire-component="projects-page-editor" class="space-y-8">
    @if ($pageId === null)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-900/20">
            <p class="text-sm text-amber-900 dark:text-amber-200">
                No projects page exists yet for this site. Use the "Add page" flow
                to create one, or enable projects-page generation in the site settings.
            </p>
        </div>
    @else
        {{-- Hero on/off toggle + hero copy regen.
             Image + placement controls live in page-manager's standard
             hero block above this component. --}}
        <div class="space-y-3 p-3 rounded-lg border border-zinc-200 dark:border-neutral-700">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Hero image on this page</span>
                    <span class="block text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                        {{ $heroEnabled
                            ? 'Hero image visible (text alignment + crop set in the hero block above).'
                            : 'Coloured band only — title + subtitle render against the site’s primary colour with no image.' }}
                    </span>
                </div>
                <button type="button"
                        wire:click="toggleHero({{ $heroEnabled ? 'false' : 'true' }})"
                        class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors cursor-pointer {{ $heroEnabled ? 'bg-accent' : 'bg-zinc-300' }}">
                    <span class="inline-block h-4 w-4 rounded-full bg-white transition-transform {{ $heroEnabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
                </button>
            </div>
        </div>

        <livewire:projects-page-settings :site-id="$siteId" :key="'settings-'.$siteId" />

        {{-- Gallery + case-study editors lifted out — they now live
             under their own pills in page-manager (Gallery / Case Studies).
             Mounting them here would duplicate the UI. --}}
    @endif
</div>

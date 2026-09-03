<?php

use App\Enums\ProjectsLayout;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\Site;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;

    /**
     * Identifier the client must not rewrite. Unlocked, $wire.set() pointed
     * it at another tenant's site and the view's Site::find() rendered that
     * site's projects flags and category vocabulary back to the caller.
     */
    #[Locked]
    public int $siteId;

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        // Fail-closed. The old bare findAuthorizedSite() discarded its
        // result and mounted anyway, despite a comment claiming it aborted.
        $this->assertAuthorizedSiteAccess();
    }

    /**
     * The view must never resolve $siteId globally — pass the authorised
     * Site down instead.
     *
     * @return array{site: Site|null}
     */
    public function with(): array
    {
        return ['site' => $this->findAuthorizedSite()];
    }

    public function setProjectsPageEnabled(string $mode): void
    {
        $site = $this->assertAuthorizedSiteAccess();

        $value = match ($mode) {
            'auto' => null,
            'force_on' => true,
            'force_off' => false,
            default => null,
        };

        $site->update(['projects_page_enabled' => $value]);
    }

    public function setHonestFraming(string $mode): void
    {
        if (! auth()->user()?->isAdmin()) {
            abort(403);
        }

        $site = $this->assertAuthorizedSiteAccess();

        $value = match ($mode) {
            'follow_config' => null,
            'force_on' => true,
            'force_off' => false,
            default => null,
        };

        $site->update(['honest_project_framing' => $value]);
    }

    public function setProjectsLayout(string $layout): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $site->update(['projects_layout' => ProjectsLayout::from($layout)]);

        // PageRenderer reads projects_layout live, but the public surface
        // memoises the rendered HTML in PublicPageCache. Invalidate so the
        // toggle is visible on the next request without a stale-cache wait.
        app(\App\Services\Site\PublicPageCache::class)->invalidate($site);
    }
};
?>

<div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700"
     data-livewire-component="projects-page-settings">

    {{-- $site comes from with() — already resolved through the authorised
         site. Never re-resolve $siteId globally here. --}}
    <h3 class="text-base font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Projects page settings</h3>

    @if (! $site)
        <p class="text-sm text-zinc-500 dark:text-zinc-400">Site unavailable.</p>
    @else

    {{-- 3-column grid on desktop, stacked on mobile. Sections were
         flowing vertically and eating screen height; horizontal layout
         keeps the eye on the row of decisions instead of scrolling. --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- Toggle 1: include projects page --}}
    <div>
        <div class="text-sm font-medium text-zinc-700 dark:text-zinc-200 mb-2">Include projects page</div>
        @php
            $current = $site->projects_page_enabled;
            $currentMode = $current === null ? 'auto' : ($current ? 'force_on' : 'force_off');
        @endphp
        <div class="flex flex-col gap-1 text-sm">
            <label class="inline-flex items-center gap-2">
                <input type="radio" name="projects_page_enabled" value="auto"
                       wire:click="setProjectsPageEnabled('auto')"
                       @checked($currentMode === 'auto')
                       class="text-zinc-900">
                <span>Auto — follow the profile hint</span>
            </label>
            <label class="inline-flex items-center gap-2">
                <input type="radio" name="projects_page_enabled" value="force_on"
                       wire:click="setProjectsPageEnabled('force_on')"
                       @checked($currentMode === 'force_on')
                       class="text-zinc-900">
                <span>Force on</span>
            </label>
            <label class="inline-flex items-center gap-2">
                <input type="radio" name="projects_page_enabled" value="force_off"
                       wire:click="setProjectsPageEnabled('force_off')"
                       @checked($currentMode === 'force_off')
                       class="text-zinc-900">
                <span>Hide projects page from site</span>
            </label>
        </div>
    </div>

    {{-- Toggle 2: Preview framing mode (admin-only) --}}
    @if (auth()->user()?->isAdmin())
        <div>
            <div class="text-sm font-medium text-zinc-700 dark:text-zinc-200 mb-1">Preview framing mode</div>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-2">
                Current config default: <strong>{{ config('site.honest_project_framing', false) ? 'on' : 'off' }}</strong>
            </p>
            @php
                $framing = $site->honest_project_framing;
                $framingMode = $framing === null ? 'follow_config' : ($framing ? 'force_on' : 'force_off');
            @endphp
            <div class="flex flex-col gap-1 text-sm">
                <label class="inline-flex items-center gap-2">
                    <input type="radio" name="honest_project_framing" value="follow_config"
                           wire:click="setHonestFraming('follow_config')"
                           @checked($framingMode === 'follow_config')>
                    <span>Follow config default</span>
                </label>
                <label class="inline-flex items-center gap-2">
                    <input type="radio" name="honest_project_framing" value="force_on"
                           wire:click="setHonestFraming('force_on')"
                           @checked($framingMode === 'force_on')>
                    <span>Force on (honest framing)</span>
                </label>
                <label class="inline-flex items-center gap-2">
                    <input type="radio" name="honest_project_framing" value="force_off"
                           wire:click="setHonestFraming('force_off')"
                           @checked($framingMode === 'force_off')>
                    <span>Force off (marketing framing)</span>
                </label>
            </div>
        </div>
    @endif

    {{-- Toggle 3: Page layout (grid vs long-form case studies) --}}
    <div>
        <div class="text-sm font-medium text-zinc-700 dark:text-zinc-200 mb-2">Page layout</div>
        @php $currentLayout = $site->projects_layout?->value ?? 'grid'; @endphp
        <div class="flex flex-col gap-1 text-sm">
            @foreach (ProjectsLayout::cases() as $opt)
                <label class="inline-flex items-start gap-2">
                    <input type="radio" name="projects_layout" value="{{ $opt->value }}"
                           wire:click="setProjectsLayout('{{ $opt->value }}')"
                           @checked($currentLayout === $opt->value)
                           class="mt-0.5 text-zinc-900">
                    <span>
                        <span class="block">{{ $opt->label() }}</span>
                        <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $opt->description() }}</span>
                    </span>
                </label>
            @endforeach
        </div>
    </div>

    {{-- Category vocabulary --}}
    <div>
        <h4 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 mb-2">Category vocabulary</h4>
        <livewire:project-category-manager :site-id="$siteId" :key="'cats-'.$siteId" />
    </div>

    </div>{{-- /grid --}}
    @endif
</div>

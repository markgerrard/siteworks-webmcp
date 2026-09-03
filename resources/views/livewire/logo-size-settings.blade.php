<?php

use App\Enums\LogoSize;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;

    #[Locked]
    public int $siteId;

    public string $currentSize = 'standard';

    public ?string $currentOverlaySize = null;

    #[Locked]
    public bool $hasOverlayLogo = false;

    public int $logoMargin = 0;

    public ?int $overlayLogoMargin = null;

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $site = $this->assertAuthorizedSiteAccess();
        $this->currentSize = $site->logo_size?->value ?? LogoSize::Standard->value;
        $this->currentOverlaySize = $site->overlay_logo_size?->value;
        $this->hasOverlayLogo = $site->overlay_logo_concept_id !== null;
        $this->logoMargin = (int) ($site->logo_margin ?? 0);
        $this->overlayLogoMargin = $site->overlay_logo_margin;
    }

    public function setLogoSize(string $size): void
    {
        $site = $this->assertAuthorizedSiteAccess();

        $logoSize = LogoSize::from($size);
        $site->update(['logo_size' => $logoSize]);
        $this->currentSize = $logoSize->value;

        // Nav reads logo_size live, but the public surface memoises
        // rendered HTML in PublicPageCache. Invalidate so the size
        // flip is visible on the next request.
        app(\App\Services\Site\PublicPageCache::class)->invalidate($site);
    }

    public function setOverlayLogoSize(?string $size): void
    {
        $site = $this->assertAuthorizedSiteAccess();

        $overlayLogoSize = ($size === null || $size === '')
            ? null
            : LogoSize::from($size);

        $site->update(['overlay_logo_size' => $overlayLogoSize]);
        $this->currentOverlaySize = $overlayLogoSize?->value;

        app(\App\Services\Site\PublicPageCache::class)->invalidate($site);
    }

    public function setLogoMargin(int $px): void
    {
        $site = $this->assertAuthorizedSiteAccess();

        // 12px cap: 2x12 inside the smallest 40px logo box still leaves a
        // visible mark; higher values can erase compact logos.
        $px = max(0, min(12, $px));
        $site->update(['logo_margin' => $px]);
        $this->logoMargin = $px;

        app(\App\Services\Site\PublicPageCache::class)->invalidate($site);
    }

    public function setOverlayLogoMargin(?int $px): void
    {
        $site = $this->assertAuthorizedSiteAccess();

        $px = $px === null ? null : max(0, min(12, $px));
        $site->update(['overlay_logo_margin' => $px]);
        $this->overlayLogoMargin = $px;

        app(\App\Services\Site\PublicPageCache::class)->invalidate($site);
    }
}; ?>

<div data-livewire-component="logo-size-settings">
    <div class="flex flex-col gap-1 text-sm">
        @foreach (LogoSize::cases() as $opt)
            <label class="inline-flex items-start gap-2">
                <input type="radio" name="logo_size" value="{{ $opt->value }}"
                       wire:click="setLogoSize('{{ $opt->value }}')"
                       @checked($currentSize === $opt->value)
                       class="mt-0.5 text-zinc-900">
                <span>
                    <span class="block">{{ $opt->label() }}</span>
                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $opt->description() }}</span>
                </span>
            </label>
        @endforeach
    </div>

    <div class="mt-3 pt-3 border-t border-zinc-200 dark:border-neutral-700">
        @if ($hasOverlayLogo)
            <p class="mb-2 text-sm">Floating logo size</p>
            <div class="flex flex-col gap-1 text-sm">
                <label class="inline-flex items-start gap-2">
                    <input type="radio" name="overlay_logo_size" value=""
                           wire:click="setOverlayLogoSize(null)"
                           @checked($currentOverlaySize === null)
                           class="mt-0.5 text-zinc-900">
                    <span class="block">Same as logo</span>
                </label>
                @foreach (LogoSize::cases() as $opt)
                    <label class="inline-flex items-start gap-2">
                        <input type="radio" name="overlay_logo_size" value="{{ $opt->value }}"
                               wire:click="setOverlayLogoSize('{{ $opt->value }}')"
                               @checked($currentOverlaySize === $opt->value)
                               class="mt-0.5 text-zinc-900">
                        <span>
                            <span class="block">{{ $opt->label() }}</span>
                            <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $opt->description() }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        @else
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Set a logo to use on the overlay header first</p>
        @endif
    </div>

    {{-- Breathing room for tight-crop logo files: vertical padding (px)
         inside the logo's height box — header heights are untouched. --}}
    <div class="mt-3 pt-3 border-t border-zinc-200 dark:border-neutral-700">
        <label class="flex items-center gap-3 text-sm">
            <span class="whitespace-nowrap">Top &amp; bottom margin</span>
            <input type="number" min="0" max="12" step="1"
                   value="{{ $logoMargin }}"
                   wire:change="setLogoMargin($event.target.valueAsNumber)"
                   class="w-16 rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                   aria-label="Logo top and bottom margin in pixels">
            <span class="text-xs text-zinc-500 dark:text-zinc-400">px (0 = flush, max 12; for tight-crop logos try 4&ndash;8)</span>
        </label>
        <label class="mt-2 flex items-center gap-3 text-sm">
            <span class="whitespace-nowrap">Floating logo margin</span>
            <input type="number" min="0" max="12" step="1"
                   value="{{ $overlayLogoMargin }}"
                   wire:change="setOverlayLogoMargin($event.target.value === '' ? null : $event.target.valueAsNumber)"
                   class="w-16 rounded border border-zinc-300 dark:border-neutral-600 bg-transparent px-2 py-1 text-sm"
                   aria-label="Floating logo top and bottom margin in pixels">
            <span class="text-xs text-zinc-500 dark:text-zinc-400">px (blank = same as logo)</span>
        </label>
    </div>
</div>

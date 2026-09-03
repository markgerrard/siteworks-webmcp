<?php

use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Services\Shop\ShopIndexBlockSettings;
use App\Support\Shop\ProductBlockSource;
use App\Support\Shop\ShopIndexBlocks;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    use AuthorizesSiteAccess;

    #[Locked]
    public int $siteId;

    #[Locked]
    public string $settingsRevision = '';

    /** @var list<array<string, mixed>> */
    public array $blocks = [];

    public string $newType = 'featured_products';

    public string $newHeading = '';

    public string $newSource = 'newest';

    public int $newLimit = 4;

    public string $newLayout = 'grid';

    public string $newTrustSources = 'both';

    public string $newReviewsLabel = 'reviews';

    public int $newMinReviews = 3;

    public string $newExternalLabel = '';

    public string $newExternalUrl = '';

    public string $newExternalRating = '';

    public string $newExternalCount = '';

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $this->abortUnlessShopEnabled();
        $this->hydrateFromSite();
    }

    public function addBlock(): void
    {
        $site = $this->abortUnlessShopEnabled();

        if ($this->newType === 'trust_strip') {
            $this->validate([
                'newHeading' => 'required|string|max:60',
                'newTrustSources' => 'required|in:site,product,both',
                'newLayout' => 'required|in:strip,carousel',
                'newReviewsLabel' => 'required|string|max:30',
                'newMinReviews' => 'integer|min:1|max:1000',
                'newExternalLabel' => 'nullable|string|max:30',
                'newExternalUrl' => 'nullable|required_with:newExternalLabel|url:http,https',
                'newExternalRating' => 'nullable|required_with:newExternalLabel|numeric|min:0|max:5|decimal:0,1',
                'newExternalCount' => 'nullable|required_with:newExternalLabel|integer|min:0',
            ]);

            $candidate = $this->blocks;
            $candidate[] = [
                'type' => 'trust_strip',
                'sources' => $this->newTrustSources,
                'layout' => $this->newLayout,
                'heading' => trim($this->newHeading),
                'reviews_label' => trim($this->newReviewsLabel),
                'min_reviews' => $this->newMinReviews,
                'external' => $this->newExternalLabel === '' ? null : [
                    'label' => trim($this->newExternalLabel),
                    'url' => $this->newExternalUrl,
                    'rating' => (float) $this->newExternalRating,
                    'count' => (int) $this->newExternalCount,
                ],
            ];

            $this->persist($site, $candidate, 'newHeading');
            $this->resetNewBlock();
            $this->hydrateFromSite();

            return;
        }

        $this->validate([
            'newHeading' => 'required|string|max:80',
            'newSource' => 'required|string',
            'newLimit' => 'integer|min:4|max:12',
            'newLayout' => 'required|in:grid,carousel',
        ]);

        if (! ProductBlockSource::isValid($this->newSource)) {
            throw ValidationException::withMessages(['newSource' => 'Block source is invalid.']);
        }

        $candidate = $this->blocks;
        $candidate[] = [
            'source' => $this->newSource,
            'limit' => $this->newLimit,
            'layout' => $this->newLayout,
            'heading' => trim($this->newHeading),
        ];

        $this->persist($site, $candidate, 'newHeading');
        $this->resetNewBlock();
        $this->hydrateFromSite();
    }

    public function removeBlock(int $index): void
    {
        $site = $this->abortUnlessShopEnabled();
        if (! isset($this->blocks[$index])) {
            return;
        }
        unset($this->blocks[$index]);
        $this->persist($site, array_values($this->blocks), 'blocks');
        $this->hydrateFromSite();
    }

    public function moveBlock(int $from, int $to): void
    {
        $site = $this->abortUnlessShopEnabled();
        if (! isset($this->blocks[$from]) || $to < 0 || $to >= count($this->blocks)) {
            return;
        }
        $row = $this->blocks[$from];
        unset($this->blocks[$from]);
        $blocks = array_values($this->blocks);
        array_splice($blocks, $to, 0, [$row]);
        $this->persist($site, $blocks, 'blocks');
        $this->hydrateFromSite();
    }

    public function updateBlock(int $index): void
    {
        $site = $this->abortUnlessShopEnabled();
        if (! isset($this->blocks[$index])) {
            return;
        }
        $isTrustStrip = ($this->blocks[$index]['type'] ?? 'featured_products') === 'trust_strip';
        $this->validate($isTrustStrip ? [
            "blocks.{$index}.heading" => 'required|string|max:60',
            "blocks.{$index}.sources" => 'required|in:site,product,both',
            "blocks.{$index}.layout" => 'required|in:strip,carousel',
            "blocks.{$index}.reviews_label" => 'required|string|max:30',
            "blocks.{$index}.min_reviews" => 'integer|min:1|max:1000',
            "blocks.{$index}.external.label" => 'nullable|string|max:30',
            "blocks.{$index}.external.url" => 'nullable|required_with:blocks.'.$index.'.external.label|url:http,https',
            "blocks.{$index}.external.rating" => 'nullable|required_with:blocks.'.$index.'.external.label|numeric|min:0|max:5|decimal:0,1',
            "blocks.{$index}.external.count" => 'nullable|required_with:blocks.'.$index.'.external.label|integer|min:0',
        ] : [
            "blocks.{$index}.heading" => 'required|string|max:80',
            "blocks.{$index}.source" => 'required|string',
            "blocks.{$index}.limit" => 'integer|min:4|max:12',
            "blocks.{$index}.layout" => 'required|in:grid,carousel',
        ]);
        $this->persist($site, $this->blocks, "blocks.{$index}.heading");
        $this->hydrateFromSite();
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function persist(\App\Models\Site $site, array $blocks, string $errorField): void
    {
        try {
            app(ShopIndexBlockSettings::class)->save($site, $blocks, $this->settingsRevision);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$errorField => $exception->getMessage()]);
        }
    }

    private function hydrateFromSite(): void
    {
        $site = $this->assertAuthorizedSiteAccess();
        $this->blocks = ShopIndexBlocks::normalize($site->shop_index_blocks);
        foreach ($this->blocks as &$block) {
            if (($block['type'] ?? null) === 'trust_strip' && $block['external'] === null) {
                $block['external'] = ['label' => '', 'url' => '', 'rating' => '', 'count' => ''];
            }
        }
        unset($block);
        $this->settingsRevision = ShopIndexBlockSettings::revision($site);
    }

    private function resetNewBlock(): void
    {
        $this->newType = 'featured_products';
        $this->newHeading = '';
        $this->newSource = 'newest';
        $this->newLimit = 4;
        $this->newLayout = 'grid';
        $this->newTrustSources = 'both';
        $this->newReviewsLabel = 'reviews';
        $this->newMinReviews = 3;
        $this->newExternalLabel = '';
        $this->newExternalUrl = '';
        $this->newExternalRating = '';
        $this->newExternalCount = '';
    }
}; ?>

<div class="space-y-6 rounded-xl border border-zinc-200 p-4 dark:border-neutral-700">
    <div>
        <h3 class="font-semibold">{{ __('Shop index blocks') }}</h3>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Optional product rows and trust strips above the shop index grid.') }}</p>
    </div>

    <div class="space-y-3">
        @foreach ($blocks as $index => $block)
            <div wire:key="block-{{ $index }}-{{ $block['type'] ?? 'featured_products' }}" class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-neutral-700">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                <flux:input wire:model="blocks.{{ $index }}.heading" label="Heading" />
                @if (($block['type'] ?? 'featured_products') === 'trust_strip')
                    <flux:select wire:model="blocks.{{ $index }}.sources" label="Sources">
                        <flux:select.option value="site">{{ __('Site') }}</flux:select.option>
                        <flux:select.option value="product">{{ __('Product') }}</flux:select.option>
                        <flux:select.option value="both">{{ __('Both') }}</flux:select.option>
                    </flux:select>
                    <flux:input wire:model="blocks.{{ $index }}.reviews_label" label="Reviews label" />
                    <flux:input type="number" wire:model="blocks.{{ $index }}.min_reviews" label="Minimum" min="1" max="1000" />
                @else
                <flux:input wire:model="blocks.{{ $index }}.source" label="Source" />
                <flux:input type="number" wire:model="blocks.{{ $index }}.limit" label="Limit" min="4" max="12" />
                @endif
                <flux:select wire:model="blocks.{{ $index }}.layout" label="Layout">
                    @if (($block['type'] ?? 'featured_products') === 'trust_strip')
                    <flux:select.option value="strip">{{ __('Strip') }}</flux:select.option>
                    @else
                    <flux:select.option value="grid">{{ __('Grid') }}</flux:select.option>
                    @endif
                    <flux:select.option value="carousel">{{ __('Carousel') }}</flux:select.option>
                </flux:select>
                <flux:button wire:click="updateBlock({{ $index }})" wire:target="updateBlock">{{ __('Update') }}</flux:button>
                @if ($index > 0)
                    <flux:button wire:click="moveBlock({{ $index }}, {{ $index - 1 }})" wire:target="moveBlock">{{ __('Up') }}</flux:button>
                @endif
                @if ($index < count($blocks) - 1)
                    <flux:button wire:click="moveBlock({{ $index }}, {{ $index + 1 }})" wire:target="moveBlock">{{ __('Down') }}</flux:button>
                @endif
                <flux:button wire:click="removeBlock({{ $index }})" wire:target="removeBlock" wire:confirm="Remove this block?">{{ __('Remove') }}</flux:button>
                </div>
                @if (($block['type'] ?? 'featured_products') === 'trust_strip')
                    <div class="grid gap-2 sm:grid-cols-4">
                        <flux:input wire:model="blocks.{{ $index }}.external.label" label="External label" />
                        <flux:input type="url" wire:model="blocks.{{ $index }}.external.url" label="External URL" />
                        <flux:input type="number" step="0.1" wire:model="blocks.{{ $index }}.external.rating" label="External rating" min="0" max="5" />
                        <flux:input type="number" wire:model="blocks.{{ $index }}.external.count" label="External count" min="0" />
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="space-y-2">
      <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
        <flux:select wire:model.live="newType" label="Block type">
            <flux:select.option value="featured_products">{{ __('Product row') }}</flux:select.option>
            <flux:select.option value="trust_strip">{{ __('Trust strip') }}</flux:select.option>
        </flux:select>
        <flux:input wire:model="newHeading" label="New heading" placeholder="Products" />
        @if ($newType === 'trust_strip')
        <flux:select wire:model="newTrustSources" label="Sources">
            <flux:select.option value="site">{{ __('Site') }}</flux:select.option>
            <flux:select.option value="product">{{ __('Product') }}</flux:select.option>
            <flux:select.option value="both">{{ __('Both') }}</flux:select.option>
        </flux:select>
        <flux:input wire:model="newReviewsLabel" label="Reviews label" />
        <flux:input type="number" wire:model="newMinReviews" label="Minimum" min="1" max="1000" />
        @else
        <flux:input wire:model="newSource" label="Source" placeholder="newest" />
        <flux:input type="number" wire:model="newLimit" label="Limit" min="4" max="12" />
        @endif
        <flux:select wire:model="newLayout" label="Layout">
            @if ($newType === 'trust_strip')
            <flux:select.option value="strip">{{ __('Strip') }}</flux:select.option>
            @else
            <flux:select.option value="grid">{{ __('Grid') }}</flux:select.option>
            @endif
            <flux:select.option value="carousel">{{ __('Carousel') }}</flux:select.option>
        </flux:select>
        <flux:button variant="primary" wire:click="addBlock" wire:target="addBlock">{{ __('Add block') }}</flux:button>
      </div>
      @if ($newType === 'trust_strip')
        <div class="grid gap-2 sm:grid-cols-4">
            <flux:input wire:model="newExternalLabel" label="External label" />
            <flux:input type="url" wire:model="newExternalUrl" label="External URL" />
            <flux:input type="number" step="0.1" wire:model="newExternalRating" label="External rating" min="0" max="5" />
            <flux:input type="number" wire:model="newExternalCount" label="External count" min="0" />
        </div>
      @endif
    </div>
    <flux:error name="newHeading" />
    <flux:error name="newSource" />
</div>

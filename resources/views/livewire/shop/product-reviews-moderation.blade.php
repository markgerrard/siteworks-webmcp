<?php

use App\Enums\Shop\ProductReviewSource;
use App\Enums\Shop\ProductReviewStatus;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\Shop\Product;
use App\Models\Shop\ProductReview;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use AuthorizesSiteAccess, WithPagination;

    #[Locked]
    public int $siteId;

    public string $statusFilter = 'pending';

    public string $ratingFilter = '';

    public string $productFilter = '';

    public string $sourceFilter = '';

    /** @var list<int> */
    public array $selectedIds = [];

    public ?string $statusMessage = null;

    public int $manualProductId = 0;

    public int $manualRating = 5;

    public string $manualTitle = '';

    public string $manualBody = '';

    public string $manualAuthorName = '';

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        $this->abortUnlessShopEnabled();
    }

    public function updatedStatusFilter(): void
    {
        if (! in_array($this->statusFilter, ['pending', 'published', 'hidden', 'all'], true)) {
            $this->statusFilter = 'pending';
        }
        $this->statusMessage = null;
        $this->resetPage();
    }

    public function updatedRatingFilter(): void
    {
        $this->resetPage();
    }

    public function updatedProductFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSourceFilter(): void
    {
        if ($this->sourceFilter !== '' && ProductReviewSource::tryFrom($this->sourceFilter) === null) {
            $this->sourceFilter = '';
        }
        $this->resetPage();
    }

    public function approve(int $reviewId): void
    {
        $this->setStatus($reviewId, ProductReviewStatus::Published);
    }

    public function hide(int $reviewId): void
    {
        $this->setStatus($reviewId, ProductReviewStatus::Hidden);
    }

    public function deleteReview(int $reviewId): void
    {
        $site = $this->abortUnlessShopEnabled();
        $review = ProductReview::query()->where('site_id', $site->id)->find($reviewId);
        abort_unless($review, 404);
        $review->delete();
        $this->statusMessage = 'Review deleted.';
        $this->selectedIds = array_values(array_filter($this->selectedIds, fn (int $id): bool => $id !== $reviewId));
    }

    public function bulkApprove(): void
    {
        $this->bulkSetStatus(ProductReviewStatus::Published);
    }

    public function bulkHide(): void
    {
        $this->bulkSetStatus(ProductReviewStatus::Hidden);
    }

    public function addManualReview(): void
    {
        $site = $this->abortUnlessShopEnabled();
        $this->validate([
            'manualProductId' => ['required', 'integer', Rule::exists('shop_products', 'id')->where('site_id', $site->id)],
            'manualRating' => ['required', 'integer', 'min:1', 'max:5'],
            'manualTitle' => ['required', 'string', 'max:80'],
            'manualBody' => ['required', 'string', 'max:2000'],
            'manualAuthorName' => ['required', 'string', 'max:60'],
        ]);

        ProductReview::create([
            'site_id' => $site->id,
            'product_id' => $this->manualProductId,
            'rating' => $this->manualRating,
            'title' => $this->manualTitle,
            'body' => $this->manualBody,
            'author_name' => $this->manualAuthorName,
            'status' => ProductReviewStatus::Published,
            'source' => ProductReviewSource::Manual,
        ]);

        $this->manualTitle = '';
        $this->manualBody = '';
        $this->manualAuthorName = '';
        $this->statusMessage = 'Manual review published.';
    }

    private function setStatus(int $reviewId, ProductReviewStatus $status): void
    {
        $site = $this->abortUnlessShopEnabled();
        $review = ProductReview::query()->where('site_id', $site->id)->find($reviewId);
        abort_unless($review, 404);
        $review->update(['status' => $status]);
        $this->statusMessage = 'Review '.$status->value.'.';
    }

    private function bulkSetStatus(ProductReviewStatus $status): void
    {
        $site = $this->abortUnlessShopEnabled();
        $ids = array_values(array_unique(array_map(intval(...), $this->selectedIds)));
        if ($ids === []) {
            return;
        }

        $changed = ProductReview::query()
            ->where('site_id', $site->id)
            ->whereIn('id', $ids)
            ->update(['status' => $status->value]);

        if ($changed > 0) {
            RebuildShopSnapshot::dispatch($site->id)->afterCommit();
        }

        $this->selectedIds = [];
        $this->statusMessage = 'Selected reviews '.$status->value.'.';
    }

    #[Computed]
    protected function reviews()
    {
        $site = $this->abortUnlessShopEnabled();
        $status = ProductReviewStatus::tryFrom($this->statusFilter);

        return ProductReview::query()
            ->where('site_id', $site->id)
            ->with('product:id,name,slug')
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $status?->value ?? ProductReviewStatus::Pending->value))
            ->when($this->ratingFilter !== '', fn ($q) => $q->where('rating', (int) $this->ratingFilter))
            ->when($this->productFilter !== '', fn ($q) => $q->where('product_id', (int) $this->productFilter))
            ->when($this->sourceFilter !== '', fn ($q) => $q->where('source', $this->sourceFilter))
            ->latest()
            ->paginate(20);
    }

    #[Computed]
    protected function products()
    {
        $site = $this->abortUnlessShopEnabled();

        return Product::query()->where('site_id', $site->id)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    protected function groupedReviews(): array
    {
        return $this->reviews->getCollection()->groupBy(fn (ProductReview $review): string => $review->source->value)->all();
    }
}; ?>

<div data-livewire-component="shop.product-reviews-moderation" class="space-y-6">
    @if ($statusMessage)
        <p class="text-sm">{{ $statusMessage }}</p>
    @endif

    <form wire:submit="addManualReview" class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-neutral-700">
        <h3 class="font-semibold">{{ __('Add a manual review') }}</h3>
        <div class="grid gap-3 md:grid-cols-2">
            <flux:select wire:model="manualProductId" label="Product">
                <flux:select.option value="0">{{ __('Choose a product') }}</flux:select.option>
                @foreach ($this->products as $product)
                    <flux:select.option value="{{ $product->id }}">{{ $product->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="manualRating" label="Rating">
                @for ($rating = 5; $rating >= 1; $rating--)
                    <flux:select.option value="{{ $rating }}">{{ $rating }}</flux:select.option>
                @endfor
            </flux:select>
            <flux:input wire:model="manualAuthorName" label="Author" maxlength="60" />
            <flux:input wire:model="manualTitle" label="Title" maxlength="80" />
        </div>
        <flux:textarea wire:model="manualBody" label="Review" maxlength="2000" />
        <flux:button variant="primary" type="submit">{{ __('Publish review') }}</flux:button>
        <flux:error name="manualProductId" />
        <flux:error name="manualTitle" />
        <flux:error name="manualBody" />
        <flux:error name="manualAuthorName" />
    </form>

    <div class="grid gap-3 md:grid-cols-4">
        <flux:select wire:model.live="statusFilter" label="Status">
            <flux:select.option value="pending">Pending</flux:select.option>
            <flux:select.option value="published">Published</flux:select.option>
            <flux:select.option value="hidden">Hidden</flux:select.option>
            <flux:select.option value="all">All</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="ratingFilter" label="Rating">
            <flux:select.option value="">Any</flux:select.option>
            @for ($rating = 5; $rating >= 1; $rating--)
                <flux:select.option value="{{ $rating }}">{{ $rating }}</flux:select.option>
            @endfor
        </flux:select>
        <flux:select wire:model.live="productFilter" label="Product">
            <flux:select.option value="">Any</flux:select.option>
            @foreach ($this->products as $product)
                <flux:select.option value="{{ $product->id }}">{{ $product->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="sourceFilter" label="Source">
            <flux:select.option value="">Any</flux:select.option>
            @foreach (\App\Enums\Shop\ProductReviewSource::cases() as $source)
                <flux:select.option value="{{ $source->value }}">{{ ucfirst($source->value) }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="flex gap-2">
        <flux:button wire:click="bulkApprove" variant="primary">{{ __('Approve selected') }}</flux:button>
        <flux:button wire:click="bulkHide">{{ __('Hide selected') }}</flux:button>
    </div>

    @forelse ($this->groupedReviews as $source => $group)
        <section class="space-y-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">{{ ucfirst($source) }}</h3>
            @foreach ($group as $review)
                <article wire:key="review-{{ $review->id }}" class="rounded-xl border border-zinc-200 p-4 dark:border-neutral-700">
                    <label class="mb-2 flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="selectedIds" value="{{ $review->id }}">
                        <span>{{ $review->product?->name }} · {{ $review->rating }} / 5 · {{ $review->status->value }}</span>
                    </label>
                    <h4 class="font-medium">{{ $review->title }}</h4>
                    <p class="text-sm text-zinc-600 dark:text-neutral-400">{{ $review->author_name }}</p>
                    <p class="mt-2">{{ $review->body }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <flux:button size="sm" wire:click="approve({{ $review->id }})">{{ __('Approve') }}</flux:button>
                        <flux:button size="sm" wire:click="hide({{ $review->id }})">{{ __('Hide') }}</flux:button>
                        <flux:button size="sm" variant="danger" wire:click="deleteReview({{ $review->id }})">{{ __('Delete') }}</flux:button>
                    </div>
                </article>
            @endforeach
        </section>
    @empty
        <p class="text-sm text-zinc-500">{{ __('No reviews match these filters.') }}</p>
    @endforelse

    {{ $this->reviews->links() }}
</div>

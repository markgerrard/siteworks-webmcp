<?php

use App\Enums\Shop\ProductStatus;
use App\Exceptions\Shop\ProductNotPublishableException;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Services\Shop\ShopDraftWriter;
use App\Support\Shop\ProductReviewNotes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesSiteAccess, WithPagination;

    private const PER_PAGE = 50;

    #[Locked]
    public int $siteId;

    #[Locked]
    public string $editRoute = 'client.portal.shop.products.edit';

    #[Locked]
    public string $exportRoute = 'client.portal.shop.products.export';

    public string $search = '';

    public string $view = 'all';

    public string $tag = '';

    public string $sortBy = 'updated';

    public string $sortDirection = 'desc';

    /**
     * @var list<int|string>
     */
    public array $selectedIds = [];

    /**
     * Products the last publish action refused, keyed by product id, with the
     * reason. A refusal never stops the rest of a batch: the others publish and
     * the refused rows stay draft, each with its message in the row.
     *
     * @var array<int|string, string>
     */
    public array $publishBlocked = [];

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
        abort_unless($this->findAuthorizedSite(), 403);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingView(): void
    {
        $this->resetPage();
    }

    public function updatingTag(): void
    {
        $this->resetPage();
    }

    /**
     * An agent write to the catalogue is visible here without a page reload: the
     * re-render recomputes the rows and the view counts. A refused row's message
     * stands only while its reason does: once a price has been set, from the
     * editor or by an agent, the row is publishable and the message goes too.
     */
    #[On('shop-catalogue-changed')]
    public function refreshCatalogue(): void
    {
        if ($this->publishBlocked === []) {
            return;
        }

        $stillBlocked = Product::query()
            ->where('site_id', $this->siteId)
            ->whereIn('id', array_map(intval(...), array_keys($this->publishBlocked)))
            ->get()
            ->filter(fn (Product $product): bool => in_array('price_missing', ProductReviewNotes::normalize($product->review_notes), true))
            ->pluck('id')
            ->map(intval(...))
            ->all();

        $this->publishBlocked = array_filter(
            $this->publishBlocked,
            fn (string $message, int|string $id): bool => in_array((int) $id, $stillBlocked, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * A catalogue change made here is announced on the same event an agent write
     * announces, so the agent bridge on this page re-reads the catalogue revision
     * and its next write carries the current base instead of the page-load one.
     */
    private function announceCatalogueChange(bool $changed): void
    {
        if ($changed) {
            $this->dispatch('shop-catalogue-changed');
        }
    }

    #[On('shop-filter-drafts')]
    public function showDrafts(): void
    {
        $this->view = 'draft';
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if (! in_array($column, ['name', 'price', 'stock', 'updated'], true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortBy = $column;
        $this->sortDirection = $column === 'updated' ? 'desc' : 'asc';
    }

    public function openProduct(int $id): void
    {
        $this->redirect(route($this->editRoute, [
            'site' => $this->siteId,
            'product' => $id,
        ]));
    }

    public function publishProduct(int $id): void
    {
        $this->publishBlocked = [];
        $this->announceCatalogueChange($this->setProductStatus($id, ProductStatus::Published));
    }

    public function unpublishProduct(int $id): void
    {
        $this->announceCatalogueChange($this->setProductStatus($id, ProductStatus::Draft));
    }

    public function deleteProduct(int $id): void
    {
        $this->assertCanDelete();
        $this->announceCatalogueChange($this->setProductStatus($id, ProductStatus::Archived));
        $this->selectedIds = array_values(array_filter(
            $this->selectedIds,
            fn (int|string $selected): bool => (int) $selected !== $id,
        ));
    }

    public function bulkPublish(): void
    {
        $this->publishBlocked = [];
        $changed = false;
        foreach ($this->selectedSiteProductIds() as $id) {
            $changed = $this->setProductStatus($id, ProductStatus::Published) || $changed;
        }
        $this->selectedIds = [];
        $this->announceCatalogueChange($changed);
    }

    public function bulkUnpublish(): void
    {
        $changed = false;
        foreach ($this->selectedSiteProductIds() as $id) {
            $changed = $this->setProductStatus($id, ProductStatus::Draft) || $changed;
        }
        $this->selectedIds = [];
        $this->announceCatalogueChange($changed);
    }

    public function bulkDelete(): void
    {
        $this->assertCanDelete();
        $changed = false;
        foreach ($this->selectedSiteProductIds() as $id) {
            $changed = $this->setProductStatus($id, ProductStatus::Archived) || $changed;
        }
        $this->selectedIds = [];
        $this->announceCatalogueChange($changed);
    }

    public function toggleSelectAll(): void
    {
        $ids = $this->currentPageProductIds();
        $selected = $this->selectedProductIds();
        $allSelected = $ids !== [] && array_diff($ids, $selected) === [];
        $this->selectedIds = $allSelected ? [] : array_map(strval(...), $ids);
    }

    /**
     * @return list<int>
     */
    private function selectedProductIds(): array
    {
        return array_values(array_unique(array_map(intval(...), $this->selectedIds)));
    }

    /**
     * @return list<int>
     */
    private function selectedSiteProductIds(): array
    {
        $ids = $this->selectedProductIds();
        if ($ids === []) {
            return [];
        }

        $found = Product::query()
            ->where('site_id', $this->siteId)
            ->whereIn('id', $ids)
            ->count();

        abort_unless($found === count($ids), 404);

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function currentPageProductIds(): array
    {
        $q = $this->catalogueQuery();
        $this->applyView($q);
        $this->applySearch($q);
        $this->applySort($q);

        return $q->paginate(self::PER_PAGE)->pluck('id')->all();
    }

    private function assertCanDelete(): void
    {
        abort_unless(auth()->user()?->can('delete', $this->abortUnlessShopEnabled()), 403);
    }

    /** @return bool True when the status was written; false when the writer refused it. */
    private function setProductStatus(int $id, ProductStatus $status): bool
    {
        $site = $this->abortUnlessShopEnabled();
        $product = Product::query()->where('site_id', $this->siteId)->findOrFail($id);
        try {
            $written = app(ShopDraftWriter::class)->setStatusFromEditor($site, $product, $status, auth()->id());
        } catch (ProductNotPublishableException $exception) {
            $this->publishBlocked[$id] = $exception->getMessage();

            return false;
        }
        ($written['deferred'])();

        return true;
    }

    public function addProduct(): void
    {
        $site = $this->abortUnlessShopEnabled();
        $name = 'Untitled product';
        $written = app(ShopDraftWriter::class)->createDraft($site, [
            'name' => $name,
            'variants' => [[
                'sku' => 'SKU-'.strtoupper(Str::random(8)),
                'label' => 'Default',
                'price_cents' => 0,
            ]],
        ], auth()->id());
        ($written['deferred'])();

        $this->redirect(route($this->editRoute, [
            'site' => $this->siteId,
            'product' => $written['product']->id,
        ]));
    }

    public function with(): array
    {
        // The first variant stands in for the product in the SKU column, so its
        // identity must not depend on how the database happens to return rows.
        $q = $this->catalogueQuery()->with([
            'variants' => fn ($variants) => $variants->orderBy('id'),
            'variants.stock',
            'images',
        ]);
        $this->applyView($q);
        $this->applyTag($q);
        $this->applySearch($q);
        $this->applySort($q);

        $site = Site::query()->find($this->siteId);

        $canDelete = $site !== null && (auth()->user()?->can('delete', $site) ?? false);
        // Export is a read of the caller's own catalogue — the same SitePolicy `view`
        // ability that gates seeing the products list at all, not the staff-only
        // `delete` ability. A client exporting their own site's products is not a
        // more privileged action than viewing them.
        $canExport = $site !== null && (auth()->user()?->can('view', $site) ?? false);

        $products = $q->paginate(self::PER_PAGE);
        $products->getCollection()->each(function (Product $product): void {
            $product->variants->each(fn (ProductVariant $variant) => $variant->setRelation('product', $product));
        });

        return [
            'products' => $products,
            'viewCounts' => $this->viewCounts(),
            'shopCurrency' => $site?->shop_currency ?? 'GBP',
            'publicHost' => $site?->publicHost(),
            'canDelete' => $canDelete,
            'canExport' => $canExport,
            'tagVocabulary' => \App\Support\Shop\ProductTagVocabulary::normalize($site?->product_tags),
        ];
    }

    /**
     * @return Builder<Product>
     */
    private function catalogueQuery(): Builder
    {
        return Product::query()
            ->where('site_id', $this->siteId)
            ->where('status', '!=', ProductStatus::Archived);
    }

    /**
     * @param  Builder<Product>  $q
     */
    private function applyView(Builder $q): void
    {
        match ($this->view) {
            'published' => $q->where('status', ProductStatus::Published),
            'draft' => $q->where('status', ProductStatus::Draft),
            'out_of_stock' => $this->applyOutOfStock($q),
            'made_to_order' => $this->applyMadeToOrder($q),
            default => null,
        };
    }

    /**
     * Stock gates a listing only where adding reserves it (cart mode). A quote or
     * enquiry shop lists a product with none on hand as made to order, so the
     * tabs here count the way the storefront lists.
     */
    private function stockGates(): bool
    {
        return $this->stockGates ??= Site::query()->whereKey($this->siteId)->first()?->shopMode() === 'cart';
    }

    private ?bool $stockGates = null;

    /**
     * @param  Builder<Product>  $q
     * @return Builder<Product>
     */
    private function applyOutOfStock(Builder $q): Builder
    {
        return $this->stockGates()
            ? $q->whereIn('id', $this->outOfStockProductIds())
            : $q->whereRaw('1 = 0');
    }

    /**
     * @param  Builder<Product>  $q
     * @return Builder<Product>
     */
    private function applyMadeToOrder(Builder $q): Builder
    {
        if ($this->stockGates()) {
            return $q->where('price_from', true);
        }

        return $q->where(fn (Builder $w) => $w
            ->where('price_from', true)
            ->orWhereIn('id', $this->outOfStockProductIds()));
    }

    /**
     * @param  Builder<Product>  $q
     */
    private function applyTag(Builder $q): void
    {
        if ($this->tag === '') {
            return;
        }

        $q->whereJsonContains('tags', $this->tag);
    }

    /**
     * @param  Builder<Product>  $q
     */
    private function applySearch(Builder $q): void
    {
        if ($this->search === '') {
            return;
        }

        // Case-insensitive on every database the list runs against: lower() on both sides
        // instead of a dialect-specific operator.
        $term = '%'.mb_strtolower($this->search).'%';
        $q->where(function (Builder $inner) use ($term): void {
            $inner->whereRaw('lower(name) like ?', [$term])
                ->orWhereHas('variants', fn (Builder $variants) => $variants->whereRaw('lower(sku) like ?', [$term]));
        });
    }

    /**
     * @param  Builder<Product>  $q
     */
    private function applySort(Builder $q): void
    {
        $dir = $this->sortDirection === 'asc' ? 'asc' : 'desc';
        $products = (new Product)->getTable();
        $variants = (new ProductVariant)->getTable();
        $stock = (new VariantStock)->getTable();

        match ($this->sortBy) {
            'name' => $q->orderBy($products.'.name', $dir),
            'price' => $q->orderByRaw("(select min(price_cents) from {$variants} where product_id = {$products}.id) {$dir} nulls last"),
            'stock' => $q->orderByRaw("(select sum({$stock}.on_hand) from {$stock} inner join {$variants} on {$variants}.id = {$stock}.variant_id where {$variants}.product_id = {$products}.id) {$dir} nulls last"),
            default => $q->orderBy($products.'.updated_at', $dir),
        };
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function outOfStockProductIds()
    {
        $variants = (new ProductVariant)->getTable();
        $stock = (new VariantStock)->getTable();

        return ProductVariant::query()
            ->from($variants)
            ->join($stock, $stock.'.variant_id', '=', $variants.'.id')
            ->select($variants.'.product_id')
            ->groupBy($variants.'.product_id')
            ->havingRaw('SUM('.$stock.'.on_hand) = 0');
    }

    /**
     * @return array{all: int, published: int, draft: int, out_of_stock: int, made_to_order: int}
     */
    private function viewCounts(): array
    {
        $base = $this->catalogueQuery();

        return [
            'all' => (clone $base)->count(),
            'published' => (clone $base)->where('status', ProductStatus::Published)->count(),
            'draft' => (clone $base)->where('status', ProductStatus::Draft)->count(),
            'out_of_stock' => $this->applyOutOfStock(clone $base)->count(),
            'made_to_order' => $this->applyMadeToOrder(clone $base)->count(),
        ];
    }
}; ?>

<div class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <flux:button variant="primary" icon="plus" wire:click="addProduct" wire:target="addProduct">
            Add product
        </flux:button>
        <div class="flex flex-wrap items-center gap-2">
            @if ($canExport)
                <flux:dropdown position="bottom" align="start">
                    <flux:button variant="ghost" icon:trailing="chevron-down">Export</flux:button>
                    <flux:menu>
                        <flux:menu.item :href="route($exportRoute, ['site' => $siteId, 'format' => 'csv'])">Export CSV</flux:menu.item>
                        <flux:menu.item :href="route($exportRoute, ['site' => $siteId, 'format' => 'md'])">Export Markdown</flux:menu.item>
                        <flux:menu.item :href="route($exportRoute, ['site' => $siteId, 'format' => 'json'])">Export JSON</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            @endif
            <flux:tooltip content="Coming soon">
                <span class="inline-flex">
                    <flux:button variant="ghost" disabled>Import</flux:button>
                </span>
            </flux:tooltip>
        </div>
    </div>

    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <flux:radio.group wire:model.live="view" variant="segmented">
            <flux:radio value="all">All {{ $viewCounts['all'] }}</flux:radio>
            <flux:radio value="published">Published {{ $viewCounts['published'] }}</flux:radio>
            <flux:radio value="draft">Draft {{ $viewCounts['draft'] }}</flux:radio>
            <flux:radio value="out_of_stock">Out of stock {{ $viewCounts['out_of_stock'] }}</flux:radio>
            <flux:radio value="made_to_order">Made to order {{ $viewCounts['made_to_order'] }}</flux:radio>
        </flux:radio.group>
        @if ($tagVocabulary !== [])
            <flux:select wire:model.live="tag" label="Tag">
                <flux:select.option value="">{{ __('All tags') }}</flux:select.option>
                @foreach ($tagVocabulary as $tagOption)
                    <flux:select.option value="{{ $tagOption['slug'] }}">{{ $tagOption['label'] }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
        <flux:input type="search" wire:model.live.debounce.300ms="search" placeholder="Search products" class="lg:max-w-sm" />
    </div>

    <p wire:loading class="text-sm text-zinc-500 dark:text-zinc-400">Loading…</p>

    @if ($products->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-neutral-200 p-12 dark:border-neutral-700">
            <flux:heading size="lg">No products yet — add your first product or import a CSV.</flux:heading>
            <div class="mt-4">
                <flux:tooltip content="Coming soon">
                    <span class="inline-flex">
                        <flux:button variant="ghost" disabled>Import</flux:button>
                    </span>
                </flux:tooltip>
            </div>
        </div>
    @else
        @if ($publishBlocked !== [])
            <flux:callout variant="warning" icon="exclamation-triangle">
                {{ count($publishBlocked) === 1 ? '1 product was not published' : count($publishBlocked).' products were not published' }}: set a price before publishing. The rest of the selection went ahead.
            </flux:callout>
        @endif
        <flux:table>
            <flux:table.columns>
                <flux:table.column>
                    <flux:checkbox wire:click="toggleSelectAll" :checked="count($selectedIds) > 0 && count($selectedIds) === $products->count()" />
                </flux:table.column>
                <flux:table.column />
                <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection" wire:click="sort('name')" :aria-sort="$sortBy === 'name' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none'">Product</flux:table.column>
                <flux:table.column>SKU</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'price'" :direction="$sortDirection" wire:click="sort('price')" align="end" :aria-sort="$sortBy === 'price' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none'">Price</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'stock'" :direction="$sortDirection" wire:click="sort('stock')" align="end" :aria-sort="$sortBy === 'stock' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none'">On hand</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'updated'" :direction="$sortDirection" wire:click="sort('updated')" :aria-sort="$sortBy === 'updated' ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none'">Updated</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column />
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($products as $product)
                    @php
                        $firstImage = $product->images->first();
                        $variantCount = $product->variants->count();
                        $firstVariant = $product->variants->first();
                        $chip = $variantCount === 1 ? (string) $firstVariant?->shopperFacingLabel() : '';
                        $minCents = $product->variants->min('price_cents');
                        $tracked = $product->variants->filter(fn ($variant) => $variant->stock !== null);
                        $onHand = $tracked->isEmpty() ? null : (int) $tracked->sum(fn ($variant) => (int) $variant->stock->on_hand);
                        $storefrontPath = \App\Support\Shop\ShopUrls::product($product->slug);
                        $storefrontUrl = $publicHost ? 'https://'.$publicHost.$storefrontPath : $storefrontPath;
                    @endphp
                    <flux:table.row wire:key="product-{{ $product->id }}" tabindex="0" wire:keydown.enter="openProduct({{ $product->id }})">
                        <flux:table.cell>
                            <flux:checkbox wire:model.live="selectedIds" value="{{ $product->id }}" />
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($firstImage)
                                <img src="{{ $firstImage->url('thumb') }}" alt="{{ $firstImage->alt ?: $product->name }}" class="size-12 rounded object-cover" width="48" height="48" />
                            @else
                                <div class="size-12 rounded bg-zinc-100 dark:bg-zinc-800" aria-hidden="true"></div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <a href="{{ route($editRoute, ['site' => $siteId, 'product' => $product->id]) }}" class="font-semibold text-zinc-900 hover:underline dark:text-zinc-100">{{ $product->name }}</a>
                            @if ($variantCount > 1)
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $variantCount }} variants</div>
                            @elseif ($chip !== '')
                                <flux:badge size="sm" color="zinc" class="mt-0.5">{{ $chip }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-zinc-500 dark:text-zinc-400">{{ $firstVariant?->sku ?? '—' }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">
                            @if ($minCents === null)
                                —
                            @else
                                {{ \App\Support\ShopMoney::display((int) $minCents, $shopCurrency, (bool) $product->price_from) }}
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $onHand === null ? '—' : $onHand }}</flux:table.cell>
                        <flux:table.cell class="text-sm text-zinc-500 dark:text-zinc-400">{{ $product->updated_at?->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:badge size="sm" :color="$product->status->value === 'published' ? 'green' : 'zinc'">
                                    {{ ucfirst($product->status->value) }}
                                </flux:badge>
                                @if ($product->is_ai_seeded && ! $product->is_ai_reviewed)
                                    <flux:badge size="sm" color="amber">AI seed — review</flux:badge>
                                @endif
                                @if (\App\Support\Shop\ProductReviewNotes::normalize($product->review_notes) !== [])
                                    <flux:badge size="sm" color="amber" title="{{ \App\Support\Shop\ProductReviewNotes::joined($product->review_notes) }}">Needs review</flux:badge>
                                @endif
                            </div>
                            @if (($publishBlocked[$product->id] ?? null) !== null)
                                <p class="mt-1 text-xs text-amber-700 dark:text-amber-300" data-publish-blocked="{{ $product->id }}">{{ $publishBlocked[$product->id] }}</p>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" aria-label="More actions for {{ $product->name }}" />
                                    <flux:menu>
                                        <flux:menu.item :href="$storefrontUrl" icon="arrow-top-right-on-square">View on storefront</flux:menu.item>
                                        @if ($product->status->value === 'published')
                                            <flux:menu.item as="button" wire:click="unpublishProduct({{ $product->id }})">Unpublish</flux:menu.item>
                                        @else
                                            <flux:menu.item as="button" wire:click="publishProduct({{ $product->id }})">Publish</flux:menu.item>
                                        @endif
                                        @if ($canDelete)
                                            <flux:menu.item as="button" variant="danger" wire:click="deleteProduct({{ $product->id }})" wire:confirm="Delete {{ $product->name }}?">Delete</flux:menu.item>
                                        @endif
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm tabular-nums text-zinc-500 dark:text-zinc-400">
                {{ $products->firstItem() }}–{{ $products->lastItem() }} of {{ $products->total() }}
            </p>
            <div>{{ $products->links() }}</div>
        </div>
    @endif

    @if (count($selectedIds) > 0)
        <div class="sticky bottom-4 z-10 flex w-full flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white p-3 shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ count($selectedIds) }} selected</span>
            <div class="flex flex-wrap items-center gap-2">
                <flux:button size="sm" wire:click="bulkPublish">Publish</flux:button>
                <flux:button size="sm" wire:click="bulkUnpublish">Unpublish</flux:button>
                @if ($canDelete)
                    <flux:button size="sm" variant="danger" wire:click="bulkDelete" wire:confirm="Delete {{ count($selectedIds) }} products?">Delete</flux:button>
                @endif
            </div>
        </div>
    @endif
</div>

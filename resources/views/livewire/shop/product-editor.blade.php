<?php

use App\Enums\Shop\InventoryReason;
use App\Enums\Shop\OrderStatus;
use App\Enums\Shop\ProductStatus;
use App\Exceptions\Shop\ProductRevisionConflictException;
use App\Livewire\Concerns\AuthorizesSiteAccess;
use App\Models\Shop\Category;
use App\Models\Shop\Order;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ProductVariantImage;
use App\Models\Shop\TaxClass;
use App\Models\Shop\VariantStock;
use App\Models\Site;
use App\Models\SiteMedia;
use App\Services\Shop\CustomerInputDefinition;
use App\Services\Shop\ShopDraftWriter;
use App\Services\Shop\StockService;
use App\Support\Shop\ProductFacts;
use App\Support\Shop\ShopUrls;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    use AuthorizesSiteAccess;

    #[Locked]
    public int $siteId;

    #[Locked]
    public int $productId;

    #[Locked]
    public string $listRoute = 'sites.shop.products';

    #[Locked]
    public string $ordersRoute = 'sites.shop.orders';

    public string $name = '';

    public string $description = '';

    public ?int $primaryCategoryId = null;

    /** @var list<int|string> */
    public array $extraCategoryIds = [];

    public ?int $taxClassId = null;

    public string $status = 'draft';

    public string $slug = '';

    public int $revision = 0;

    public bool $priceFrom = false;

    public bool $isAiReviewed = false;

    public bool $hasUnsavedChanges = false;

    /** @var list<string> */
    public array $selectedTags = [];

    public bool $addingVariant = false;

    public string $newVariantSku = '';

    public string $newVariantLabel = '';

    public int $newVariantPriceCents = 0;

    /** mixed: Livewire must not coerce '12.5'→12 / 'abc'→0 before validation. */
    public mixed $newVariantWeightGrams = null;

    /** @var array<int|string, int|string|null> */
    public array $variantWeights = [];

    public ?int $editingVariantId = null;

    public string $editingVariantLabel = '';

    public string $editingVariantSku = '';

    public int $editingVariantPriceCents = 0;

    public int $editingVariantOnHand = 0;

    /** mixed: Livewire must not coerce '12.5'→12 / 'abc'→0 before validation. */
    public mixed $editingVariantWeightGrams = null;

    /** @var array<string, array{text?: string, pairs?: list<array{label: string, value: string}>}> */
    public array $factValues = [];

    /** @var list<array<string, mixed>> */
    public array $customerInputs = [];

    public function mount(
        int $siteId,
        int $productId,
        string $listRoute = 'sites.shop.products',
        string $ordersRoute = 'sites.shop.orders',
    ): void {
        $this->siteId = $siteId;
        $this->productId = $productId;
        $this->listRoute = $listRoute;
        $this->ordersRoute = $ordersRoute;
        $this->abortUnlessShopEnabled();
        $this->hydrateFromProduct();
    }

    public function updated(string $name): void
    {
        if (str_starts_with($name, 'newVariant') || str_starts_with($name, 'editingVariant')) {
            return;
        }

        $this->hasUnsavedChanges = true;
    }

    public function discard(): void
    {
        $this->abortUnlessShopEnabled();
        $this->hydrateFromProduct();
        $this->hasUnsavedChanges = false;
        $this->resetErrorBag();
    }

    /**
     * Rename never rewrites slug (T9). An explicit slug field is the only way to change the URL.
     */
    public function save(): void
    {
        $site = $this->abortUnlessShopEnabled();
        $product = Product::where('site_id', $this->siteId)->findOrFail($this->productId);
        $this->validate([
            'name' => 'required|string|max:255',
            'slug' => [
                'required',
                'string',
                'max:128',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                \Illuminate\Validation\Rule::unique('shop_products', 'slug')
                    ->where('site_id', $this->siteId)
                    ->ignore($this->productId),
                \Illuminate\Validation\Rule::notIn(ShopUrls::RESERVED_SLUGS),
            ],
            'status' => 'required|in:draft,published',
            'taxClassId' => 'nullable|integer',
            'priceFrom' => 'boolean',
            'isAiReviewed' => 'boolean',
            'selectedTags' => 'array|max:5',
            'selectedTags.*' => 'string',
            'variantWeights.*' => $this->weightGramsRules(),
        ]);

        $staff = $this->canManageStaffFields();
        $payload = [
            'name' => $this->name,
            'description' => $this->description,
            'tax_class_id' => $staff ? $this->taxClassId : $product->tax_class_id,
            'primary_category_id' => $this->primaryCategoryId,
            'extra_category_ids' => array_map(intval(...), $this->extraCategoryIds),
            'price_from' => $this->priceFrom,
            'slug' => $this->slug,
            'status' => $this->status,
            'tags' => $this->selectedTags,
        ];
        if ($staff) {
            $payload['is_ai_reviewed'] = $this->isAiReviewed;
        }
        $groups = ProductFacts::groups($site->product_fact_groups);
        if ($groups !== []) {
            $payload['facts'] = ProductFacts::mergeEditorFacts(
                is_array($product->facts) ? $product->facts : [],
                $this->factValues,
                $groups,
            );
        }
        $payload['revision'] = $this->revision;
        $payload['variant_weights'] = $this->normalisedVariantWeights();
        $payload['customer_inputs'] = $this->normalisedCustomerInputs();

        try {
            $written = app(ShopDraftWriter::class)->saveFromEditor($site, $product, $payload, auth()->id());
        } catch (ProductRevisionConflictException $exception) {
            $this->addError('revision', $exception->getMessage());

            return;
        } catch (\App\Exceptions\Shop\ProductNotPublishableException $exception) {
            $this->addError('status', $exception->getMessage());

            return;
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->addError('selectedTags', collect($exception->errors())->flatten()->first() ?? $exception->getMessage());

            return;
        } catch (\App\Exceptions\Shop\UnknownProductTagsException $exception) {
            $this->addError('selectedTags', $exception->getMessage());

            return;
        } catch (\InvalidArgumentException $exception) {
            $this->addError('primaryCategoryId', $exception->getMessage());

            return;
        }
        ($written['deferred'])();
        $this->hydrateFromProduct();
        $this->hasUnsavedChanges = false;
    }

    public function toggleTag(string $slug): void
    {
        $this->abortUnlessShopEnabled();
        if (in_array($slug, $this->selectedTags, true)) {
            $this->selectedTags = array_values(array_filter(
                $this->selectedTags,
                fn (string $current): bool => $current !== $slug,
            ));
            $this->hasUnsavedChanges = true;

            return;
        }

        if (count($this->selectedTags) >= 5) {
            $this->addError('selectedTags', 'A product may have at most 5 tags.');

            return;
        }

        $this->resetErrorBag('selectedTags');
        $this->selectedTags[] = $slug;
        $this->hasUnsavedChanges = true;
    }

    public function publish(): void
    {
        $this->writeStatusFromEditor(ProductStatus::Published);
    }

    public function unpublish(): void
    {
        $this->writeStatusFromEditor(ProductStatus::Draft);
    }

    private function writeStatusFromEditor(ProductStatus $status): void
    {
        $site = $this->abortUnlessShopEnabled();
        // Ruling: staff AND client users may publish their own site's products —
        // the Status select + Save is the same path, so no staff-only gate here.
        $product = Product::where('site_id', $this->siteId)->findOrFail($this->productId);
        // Publishing is a visibility change, NOT a review. is_ai_reviewed untouched.
        try {
            $written = app(ShopDraftWriter::class)->setStatusFromEditor(
                $site,
                $product,
                $status,
                auth()->id(),
                $this->revision,
            );
        } catch (ProductRevisionConflictException $exception) {
            $this->addError('revision', $exception->getMessage());

            return;
        } catch (\App\Exceptions\Shop\ProductNotPublishableException $exception) {
            $this->addError('status', $exception->getMessage());

            return;
        }
        ($written['deferred'])();
        $this->status = $status->value;
        $this->revision = (int) ($written['product']->revision ?? $this->revision);
    }

    public function startAddingVariant(): void
    {
        $this->abortUnlessShopEnabled();
        $this->addingVariant = true;
    }

    public function addVariant(): void
    {
        $this->abortUnlessShopEnabled();
        $this->addingVariant = true;
        $this->validate([
            'newVariantSku' => 'required|string|min:1|max:64',
            'newVariantLabel' => 'nullable|string|max:64',
            'newVariantPriceCents' => 'required|integer|min:0',
            'newVariantWeightGrams' => $this->weightGramsRules(),
        ]);

        $weightGrams = $this->normalisedWeightGrams($this->newVariantWeightGrams);
        if (! $this->mutateCatalogue(function (Product $product) use ($weightGrams): void {
            $variant = ProductVariant::query()->create([
                'product_id' => $product->id,
                'sku' => $this->newVariantSku,
                'label' => $this->newVariantLabel ?: null,
                'price_cents' => $this->newVariantPriceCents,
                'weight_grams' => $weightGrams,
            ]);
            app(StockService::class)->initialiseVariant($variant->id);
            $this->variantWeights[$variant->id] = $variant->weight_grams;
        })) {
            return;
        }

        $this->newVariantSku = '';
        $this->newVariantLabel = '';
        $this->newVariantPriceCents = 0;
        $this->newVariantWeightGrams = null;
        $this->addingVariant = false;
    }

    public function startEditingVariant(int $variantId): void
    {
        $this->abortUnlessShopEnabled();
        $variant = $this->ownedVariant($variantId);
        $this->editingVariantId = $variant->id;
        $this->editingVariantLabel = (string) ($variant->label ?? '');
        $this->editingVariantSku = $variant->sku;
        $this->editingVariantPriceCents = (int) $variant->price_cents;
        $this->editingVariantOnHand = (int) VariantStock::query()->where('variant_id', $variant->id)->value('on_hand');
        $this->editingVariantWeightGrams = $variant->weight_grams;
    }

    public function saveVariantRow(): void
    {
        $this->abortUnlessShopEnabled();
        $this->validate([
            'editingVariantId' => 'required|integer',
            'editingVariantSku' => 'required|string|min:1|max:64',
            'editingVariantLabel' => 'nullable|string|max:64',
            'editingVariantPriceCents' => 'required|integer|min:0',
            'editingVariantOnHand' => 'required|integer|min:0',
            'editingVariantWeightGrams' => $this->weightGramsRules(),
        ]);

        $variantId = (int) $this->editingVariantId;
        $weightGrams = $this->normalisedWeightGrams($this->editingVariantWeightGrams);
        if (! $this->mutateCatalogue(function () use ($variantId, $weightGrams): void {
            $variant = $this->ownedVariant($variantId);
            $variant->update([
                'sku' => $this->editingVariantSku,
                'label' => $this->editingVariantLabel !== '' ? $this->editingVariantLabel : null,
                'price_cents' => $this->editingVariantPriceCents,
                'weight_grams' => $weightGrams,
            ]);
            $this->variantWeights[$variant->id] = $weightGrams;
            app(StockService::class)->initialiseVariant($variant->id);
            $current = (int) VariantStock::query()->where('variant_id', $variant->id)->value('on_hand');
            $delta = $this->editingVariantOnHand - $current;
            if ($delta !== 0) {
                app(StockService::class)->recordMovement($variant->id, $delta, InventoryReason::Adjustment, 'product editor');
            }
        })) {
            return;
        }

        $this->editingVariantId = null;
    }

    public function deleteVariant(int $variantId): void
    {
        $this->abortUnlessShopEnabled();
        if (! $this->canManageStaffFields()) {
            return;
        }

        $deleted = $this->mutateCatalogue(function () use ($variantId): bool {
            $remaining = ProductVariant::query()->where('product_id', $this->productId)->count();
            if ($remaining <= 1) {
                $this->addError('editingVariantId', 'The last variant cannot be deleted.');

                return false;
            }

            $variant = $this->ownedVariant($variantId);
            $variant->images()->delete();
            VariantStock::query()->where('variant_id', $variant->id)->delete();
            $variant->delete();

            return true;
        });

        if ($deleted && $this->editingVariantId === $variantId) {
            $this->editingVariantId = null;
        }
    }

    #[On('media-selected')]
    public function onMediaSelected(int $id, string $model = ''): void
    {
        $this->abortUnlessShopEnabled();

        if ($model === 'productImageMediaId') {
            $this->attachProductMedia($id);

            return;
        }

        if ($model === 'variantImageMediaId') {
            $this->attachVariantMedia($id);
        }
    }

    public function setPrimaryImage(int $imageId): void
    {
        $this->abortUnlessShopEnabled();
        $this->mutateCatalogue(function (Product $product) use ($imageId): void {
            $image = ProductImage::query()->where('product_id', $product->id)->whereKey($imageId)->firstOrFail();
            $product->update(['primary_image_id' => $image->id]);
        });
    }

    public function moveImageUp(int $imageId): void
    {
        $this->moveImage($imageId, -1);
    }

    public function moveImageDown(int $imageId): void
    {
        $this->moveImage($imageId, 1);
    }

    private function moveImage(int $imageId, int $direction): void
    {
        $this->abortUnlessShopEnabled();
        $this->mutateCatalogue(function (Product $product) use ($imageId, $direction): void {
            $images = ProductImage::query()->where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->get();
            $index = $images->search(fn (ProductImage $image): bool => $image->id === $imageId);
            if ($index === false) {
                return;
            }

            $swapWith = $index + $direction;
            if ($swapWith < 0 || $swapWith >= $images->count()) {
                return;
            }

            $current = $images[$index];
            $neighbour = $images[$swapWith];
            $currentOrder = (int) $current->sort_order;
            $current->update(['sort_order' => (int) $neighbour->sort_order]);
            $neighbour->update(['sort_order' => $currentOrder]);
        });
    }

    private function attachProductMedia(int $mediaId): void
    {
        $this->abortUnlessShopEnabled();
        $media = $this->ownedLibraryMedia($mediaId);
        $product = Product::query()->where('site_id', $this->siteId)->whereKey($this->productId)->firstOrFail();

        if ($product->images()->count() >= 20) {
            $this->addError('images', 'A product may have at most 20 images.');

            return;
        }

        $sortOrder = (int) $product->images()->max('sort_order') + ($product->images()->exists() ? 1 : 0);
        $this->mutateCatalogue(function (Product $row) use ($media, $sortOrder): void {
            $image = ProductImage::query()->create([
                'product_id' => $row->id,
                'path' => (string) $media->s3_key,
                'sort_order' => $sortOrder,
                'alt' => $media->alt_text,
            ]);
            if ($row->primary_image_id === null) {
                $row->update(['primary_image_id' => $image->id]);
            }
        });
    }

    private function attachVariantMedia(int $mediaId): void
    {
        if ($this->editingVariantId === null) {
            return;
        }

        $media = $this->ownedLibraryMedia($mediaId);
        $variantId = (int) $this->editingVariantId;

        $this->mutateCatalogue(function () use ($media, $variantId): void {
            $variant = $this->ownedVariant($variantId);
            $sortOrder = (int) $variant->images()->max('sort_order') + ($variant->images()->exists() ? 1 : 0);
            ProductVariantImage::query()->create([
                'variant_id' => $variant->id,
                'path' => (string) $media->s3_key,
                'sort_order' => $sortOrder,
                'alt' => $media->alt_text,
            ]);
        });
    }

    private function ownedLibraryMedia(int $mediaId): SiteMedia
    {
        return SiteMedia::query()
            ->library()
            ->where('site_id', $this->siteId)
            ->findOrFail($mediaId);
    }

    private function ownedVariant(int $variantId): ProductVariant
    {
        return ProductVariant::query()
            ->where('product_id', $this->productId)
            ->whereKey($variantId)
            ->firstOrFail();
    }

    /**
     * @param  callable(Product, Site): (void|bool)  $work
     */
    private function mutateCatalogue(callable $work): bool
    {
        $site = $this->abortUnlessShopEnabled();
        $writer = app(ShopDraftWriter::class);
        $committed = true;
        try {
            $deferred = $writer->write($site, [$this->productId], auth()->id(), function () use ($site, $work, $writer, &$committed): void {
                $product = Product::query()->where('site_id', $this->siteId)->whereKey($this->productId)->firstOrFail();
                if ((int) $product->revision !== (int) $this->revision) {
                    throw new ProductRevisionConflictException;
                }
                if ($work($product, $site) === false) {
                    $committed = false;

                    return;
                }
                // Variant rows are written by the closure above; a price entered there
                // may retire the unpriced-import note before the revision moves on.
                $writer->settlePriceNote($product);
                $writer->bumpRevisions($site, $product, auth()->id());
            });
        } catch (ProductRevisionConflictException $exception) {
            $this->addError('revision', $exception->getMessage());

            return false;
        }
        if (! $committed) {
            return false;
        }
        $deferred();
        $this->revision = (int) Product::query()->whereKey($this->productId)->value('revision');
        $this->syncUnsavedChangesFromForm();

        return true;
    }

    private function syncUnsavedChangesFromForm(): void
    {
        $product = Product::where('site_id', $this->siteId)->findOrFail($this->productId);
        $persistedPrimary = $product->primaryCategory()->first()?->id;
        $persistedExtras = $product->categories()
            ->wherePivot('is_primary', false)
            ->pluck('shop_categories.id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
        $formExtras = collect($this->extraCategoryIds)
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $this->hasUnsavedChanges = $this->name !== $product->name
            || $this->description !== ($product->description ?? '')
            || (int) ($this->primaryCategoryId ?? 0) !== (int) ($persistedPrimary ?? 0)
            || $formExtras !== $persistedExtras
            || $this->status !== $product->status->value
            || $this->slug !== $product->slug
            || $this->priceFrom !== (bool) $product->price_from
            || (int) ($this->taxClassId ?? 0) !== (int) ($product->tax_class_id ?? 0)
            || $this->isAiReviewed !== (bool) $product->is_ai_reviewed
            || $this->selectedTags !== $this->assignedTags($product)
            || $this->factsDifferFromProduct($product);
    }

    private function hydrateFromProduct(): void
    {
        $product = Product::where('site_id', $this->siteId)->with('variants')->findOrFail($this->productId);
        $this->name = $product->name;
        $this->description = $product->description ?? '';
        $this->primaryCategoryId = $product->primaryCategory()->first()?->id;
        $this->extraCategoryIds = $product->categories()
            ->wherePivot('is_primary', false)
            ->pluck('shop_categories.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $this->taxClassId = $product->tax_class_id;
        $this->status = $product->status->value;
        $this->slug = $product->slug;
        $this->revision = (int) $product->revision;
        $this->priceFrom = (bool) $product->price_from;
        $this->isAiReviewed = (bool) $product->is_ai_reviewed;
        $this->selectedTags = $this->assignedTags($product);
        $this->addingVariant = false;
        $this->editingVariantId = null;
        $this->newVariantWeightGrams = null;
        $this->editingVariantWeightGrams = null;
        $this->variantWeights = [];
        foreach ($product->variants as $variant) {
            $this->variantWeights[$variant->id] = $variant->weight_grams;
        }
        $this->hydrateFacts($product);
        $this->customerInputs = [];
        foreach (is_array($product->customer_inputs) ? $product->customer_inputs : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['options']) && is_array($row['options'])) {
                $row['optionsText'] = implode("\n", $row['options']);
            }
            $this->customerInputs[] = $row;
        }
    }

    public function addFactPair(string $slug): void
    {
        $this->abortUnlessShopEnabled();
        $pairs = $this->factValues[$slug]['pairs'] ?? [];
        if (count($pairs) >= ProductFacts::MAX_PAIRS) {
            return;
        }
        $pairs[] = ['label' => '', 'value' => ''];
        $this->factValues[$slug]['pairs'] = $pairs;
        $this->hasUnsavedChanges = true;
    }

    public function removeFactPair(string $slug, int $index): void
    {
        $this->abortUnlessShopEnabled();
        $pairs = $this->factValues[$slug]['pairs'] ?? [];
        if (! isset($pairs[$index])) {
            return;
        }
        array_splice($pairs, $index, 1);
        $this->factValues[$slug]['pairs'] = array_values($pairs);
        $this->hasUnsavedChanges = true;
    }

    private function hydrateFacts(Product $product): void
    {
        $site = Site::query()->whereKey($this->siteId)->firstOrFail();
        $groups = ProductFacts::groups($site->product_fact_groups);
        $stored = is_array($product->facts) ? $product->facts : [];
        $this->factValues = [];
        foreach ($groups as $group) {
            $value = is_array($stored[$group['slug']] ?? null) ? $stored[$group['slug']] : [];
            $converted = ProductFacts::convertValueToKind($value, $group['kind']);
            if ($group['kind'] === 'text') {
                $this->factValues[$group['slug']] = ['text' => $converted['text'] ?? ''];

                continue;
            }
            $pairs = $converted['pairs'] ?? [];
            if ($pairs === []) {
                $pairs[] = ['label' => '', 'value' => ''];
            }
            $this->factValues[$group['slug']] = ['pairs' => $pairs];
        }
    }

    private function factsDifferFromProduct(Product $product): bool
    {
        $site = Site::query()->whereKey($this->siteId)->firstOrFail();
        $groups = ProductFacts::groups($site->product_fact_groups);
        if ($groups === []) {
            return false;
        }
        $merged = ProductFacts::mergeEditorFacts(
            is_array($product->facts) ? $product->facts : [],
            $this->factValues,
            $groups,
        );

        return $merged !== (is_array($product->facts) ? $product->facts : []);
    }

    public function addCustomerInput(): void
    {
        $this->abortUnlessShopEnabled();
        if (count($this->customerInputs) >= 3) {
            return;
        }
        $this->customerInputs[] = [
            'slug' => '',
            'label' => '',
            'kind' => 'text',
            'required' => false,
            'max_chars' => 80,
            'pattern' => null,
            'options' => ['', ''],
            'max_files' => 1,
            'help' => '',
        ];
        $this->hasUnsavedChanges = true;
    }

    public function removeCustomerInput(int $index): void
    {
        $this->abortUnlessShopEnabled();
        unset($this->customerInputs[$index]);
        $this->customerInputs = array_values($this->customerInputs);
        $this->hasUnsavedChanges = true;
    }

    public function useSiteDefaults(): void
    {
        $site = $this->abortUnlessShopEnabled();
        $this->customerInputs = is_array($site->default_customer_inputs) ? $site->default_customer_inputs : [];
        $this->hasUnsavedChanges = true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalisedCustomerInputs(): array
    {
        $rows = [];
        foreach ($this->customerInputs as $row) {
            if (! is_array($row)) {
                continue;
            }
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug === '') {
                $slug = Str::slug((string) ($row['label'] ?? ''));
            }
            $options = $row['options'] ?? [];
            if (isset($row['optionsText']) && is_string($row['optionsText'])) {
                $options = array_values(array_filter(array_map(trim(...), preg_split('/\r\n|\r|\n/', $row['optionsText']) ?: [])));
            } elseif (is_string($options)) {
                $options = array_values(array_filter(array_map(trim(...), preg_split('/\r\n|\r|\n/', $options) ?: [])));
            }
            $rows[] = array_merge($row, ['slug' => $slug, 'options' => $options]);
        }

        return CustomerInputDefinition::normalize($rows);
    }

    /**
     * Strict integer 0–100000 (or empty). Rejects '12.5' and 'abc' instead of coercing.
     *
     * @return list<string|\Closure>
     */
    private function weightGramsRules(): array
    {
        return [
            'nullable',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }
                if (! $this->isStrictWeightGrams($value)) {
                    $fail(__('The :attribute must be an integer between 0 and 100000.'));
                }
            },
        ];
    }

    private function isStrictWeightGrams(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= 100000;
        }

        return is_string($value)
            && preg_match('/^\d+$/', $value) === 1
            && (int) $value >= 0
            && (int) $value <= 100000;
    }

    private function normalisedWeightGrams(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array<int, int|null>
     */
    private function normalisedVariantWeights(): array
    {
        $weights = [];
        foreach ($this->variantWeights as $variantId => $grams) {
            $weights[(int) $variantId] = $this->normalisedWeightGrams($grams);
        }

        return $weights;
    }

    /**
     * Column list is additive so a sibling lane can splice in weight without a rewrite.
     *
     * @param  \Illuminate\Support\Collection<int, ProductVariant>  $variants
     * @return list<array{key: string, label: string}>
     */
    private function variantColumns($variants): array
    {
        $columns = [];
        if ($variants->count() > 1) {
            $columns[] = ['key' => 'label', 'label' => 'Label'];
        }
        $columns[] = ['key' => 'sku', 'label' => 'SKU'];
        $columns[] = ['key' => 'price', 'label' => 'Price'];
        $columns[] = ['key' => 'weight', 'label' => 'Weight (g)'];
        $columns[] = ['key' => 'on_hand', 'label' => 'On hand'];
        $columns[] = ['key' => 'image', 'label' => 'Image'];
        $columns[] = ['key' => 'actions', 'label' => ''];

        return $columns;
    }

    public function with(): array
    {
        $product = Product::where('site_id', $this->siteId)
            ->with([
                'images',
                'variants' => fn ($query) => $query->orderBy('id')->with('images'),
            ])
            ->findOrFail($this->productId);
        $site = Site::query()->whereKey($this->siteId)->firstOrFail();
        $host = $site->publicHost();
        $variants = $product->variants;
        $variants->each(function (ProductVariant $variant) use ($product): void {
            $variant->setRelation('product', $product);
        });
        $paidOrderCount = Order::query()
            ->where('site_id', $this->siteId)
            ->where('status', OrderStatus::Paid)
            ->whereHas('items', fn ($query) => $query->where('product_id', $product->id))
            ->count();

        return [
            'product' => $product,
            'variants' => $variants,
            'images' => $product->images,
            'categories' => Category::where('site_id', $this->siteId)->orderBy('sort_order')->get(),
            'taxClasses' => TaxClass::query()->orderBy('name')->get(),
            'storefrontUrl' => ($host ? 'https://'.$host : '').ShopUrls::product($this->slug),
            'onHand' => app(StockService::class)->onHandMap($variants->pluck('id')->all()),
            'variantColumns' => $this->variantColumns($variants),
            'paidOrderCount' => $paidOrderCount,
            'ordersUrl' => route($this->ordersRoute, $this->siteId).'?product='.$product->id,
            'canManageStaffFields' => $this->canManageStaffFields(),
            'tagVocabulary' => \App\Support\Shop\ProductTagVocabulary::normalize($site->product_tags),
            'factGroups' => ProductFacts::groups($site->product_fact_groups),
        ];
    }

    /**
     * @return list<string>
     */
    private function assignedTags(Product $product): array
    {
        $site = Site::query()->whereKey($this->siteId)->firstOrFail();

        return \App\Support\Shop\ProductTagAssignment::normalize(
            $product->tags ?? [],
            \App\Support\Shop\ProductTagVocabulary::normalize($site->product_tags),
        );
    }

    private function canManageStaffFields(): bool
    {
        return auth()->user()?->isStaff() ?? false;
    }
}; ?>
@php $shopCurrency = \App\Models\Site::query()->whereKey($this->siteId)->value('shop_currency') ?? 'GBP'; @endphp

<div class="space-y-6" data-has-unsaved-changes="{{ $hasUnsavedChanges ? '1' : '0' }}">
    <div class="flex w-full flex-wrap items-center gap-3 border-b border-zinc-200 pb-3 dark:border-neutral-700">
        <flux:button variant="ghost" :href="route($listRoute, $this->siteId)" icon="arrow-left" wire:navigate>
            {{ __('Products') }}
        </flux:button>
        <flux:heading size="lg" class="min-w-0 flex-1 truncate">
            {{ $name !== '' ? $name : __('Untitled product') }}
        </flux:heading>
        <flux:badge size="sm" :color="$status === 'published' ? 'green' : 'zinc'">
            {{ ucfirst($status) }}
        </flux:badge>
        @if ($status === 'published')
            <a href="{{ $storefrontUrl }}" target="_blank" rel="noopener noreferrer" class="text-sm text-zinc-700 underline-offset-2 hover:underline dark:text-zinc-200">
                {{ __('View on storefront') }}
            </a>
        @endif
        <flux:button variant="primary" wire:click="save" wire:target="save">{{ __('Save') }}</flux:button>
        <flux:error name="revision" />
    </div>

    @if ($hasUnsavedChanges)
        <div class="sticky bottom-0 z-40 flex w-full items-center justify-center gap-4 border-t border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
            <span>{{ __('Unsaved changes') }}</span>
            <span aria-hidden="true">·</span>
            <button type="button" class="underline underline-offset-2 cursor-pointer" wire:click="discard">
                {{ __('Discard') }}
            </button>
            <flux:button variant="primary" size="sm" wire:click="save" wire:target="save">{{ __('Save') }}</flux:button>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <flux:card>
                <flux:input wire:model="name" label="Title" />
            </flux:card>

            <flux:card>
                <flux:textarea wire:model="description" label="Description" rows="8" />
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ mb_strlen($description) }}</p>
            </flux:card>

@if ($factGroups !== [])
            <flux:card>
                    <flux:heading size="lg">{{ __('Facts') }}</flux:heading>
                    <div class="mt-4 space-y-6">
                        @foreach ($factGroups as $group)
                            <div wire:key="fact-{{ $group['slug'] }}" class="space-y-2">
                                <flux:heading size="sm">{{ $group['label'] }}</flux:heading>
                                @if ($group['kind'] === 'text')
                                    <flux:textarea wire:model="factValues.{{ $group['slug'] }}.text" rows="4" />
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ mb_strlen($factValues[$group['slug']]['text'] ?? '') }} / 4000</p>
                                @else
                                    @foreach (($factValues[$group['slug']]['pairs'] ?? []) as $pairIndex => $pair)
                                        <div wire:key="fact-{{ $group['slug'] }}-{{ $pairIndex }}" class="flex flex-wrap items-end gap-2">
                                            <div class="min-w-[8rem] flex-1">
                                                <flux:input wire:model="factValues.{{ $group['slug'] }}.pairs.{{ $pairIndex }}.label" label="Label" />
                                            </div>
                                            <div class="min-w-[8rem] flex-1">
                                                <flux:input wire:model="factValues.{{ $group['slug'] }}.pairs.{{ $pairIndex }}.value" label="Value" />
                                            </div>
                                            <flux:button size="xs" variant="ghost" wire:click="removeFactPair('{{ $group['slug'] }}', {{ $pairIndex }})">{{ __('Remove') }}</flux:button>
                                        </div>
                                    @endforeach
                                    <flux:button size="sm" wire:click="addFactPair('{{ $group['slug'] }}')">{{ __('Add pair') }}</flux:button>
                                @endif
                            </div>
                        @endforeach
                    </div>
            </flux:card>

@endif
            <flux:card>
                <flux:heading size="lg">{{ __('Media') }}</flux:heading>
                <div class="mt-3">
                    <x-media-picker :site-id="$siteId" model="productImageMediaId" kinds="image" slot-label="Product image" aspect="1:1" />
                </div>
                <flux:error name="images" />
                <div class="mt-4 flex flex-wrap gap-3">
                    @forelse ($images as $image)
                        <div wire:key="img-{{ $image->id }}" class="flex w-24 flex-col items-center gap-1">
                            <img src="{{ $image->url() }}" alt="" class="size-24 rounded object-cover">
                            @if ((int) $product->primary_image_id === (int) $image->id)
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Primary') }}</span>
                            @else
                                <flux:button size="xs" variant="ghost" wire:click="setPrimaryImage({{ $image->id }})" wire:target="setPrimaryImage">
                                    {{ __('Set as primary') }}
                                </flux:button>
                            @endif
                            <div class="flex gap-1">
                                <flux:button size="xs" variant="ghost" wire:click="moveImageUp({{ $image->id }})" wire:target="moveImageUp">{{ __('Up') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="moveImageDown({{ $image->id }})" wire:target="moveImageDown">{{ __('Down') }}</flux:button>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No images yet.') }}</p>
                    @endforelse
                </div>
            </flux:card>

            <flux:card>
                <flux:heading size="lg">{{ __('Categories') }}</flux:heading>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Primary decides the storefront URL and breadcrumb.') }}</p>
                <div class="mt-4 space-y-4">
                    <flux:select wire:model="primaryCategoryId" label="Primary category">
                        <flux:select.option value="">— none —</flux:select.option>
                        @foreach ($categories as $cat)
                            <flux:select.option value="{{ $cat->id }}">{{ $cat->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div class="space-y-2">
                        <flux:label>{{ __('Extra categories') }}</flux:label>
                        <div class="flex flex-col gap-2">
                            @foreach ($categories as $cat)
                                @continue((int) $cat->id === (int) $primaryCategoryId)
                                <flux:checkbox wire:model="extraCategoryIds" value="{{ $cat->id }}" :label="$cat->name" />
                            @endforeach
                        </div>
                    </div>
                </div>
            </flux:card>

            <flux:card>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="lg">{{ __('Customer inputs') }}</flux:heading>
                    <div class="flex gap-2">
                        <flux:button size="sm" variant="ghost" wire:click="useSiteDefaults" wire:target="useSiteDefaults">{{ __('Use site defaults') }}</flux:button>
                        <flux:button size="sm" wire:click="addCustomerInput" wire:target="addCustomerInput" :disabled="count($customerInputs) >= 3">{{ __('Add input') }}</flux:button>
                    </div>
                </div>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Shoppers fill these when adding a line. At most three.') }}</p>
                <div class="mt-4 space-y-4">
                    @forelse ($customerInputs as $index => $input)
                        <div wire:key="customer-input-{{ $index }}" class="rounded-lg border border-zinc-200 p-3 dark:border-neutral-700 space-y-3">
                            <div class="flex justify-between gap-2">
                                <flux:input wire:model="customerInputs.{{ $index }}.label" label="Label" />
                                <flux:button size="xs" variant="ghost" wire:click="removeCustomerInput({{ $index }})">{{ __('Remove') }}</flux:button>
                            </div>
                            <flux:input wire:model="customerInputs.{{ $index }}.slug" label="Slug" />
                            <flux:select wire:model.live="customerInputs.{{ $index }}.kind" label="Kind">
                                <flux:select.option value="text">Text</flux:select.option>
                                <flux:select.option value="textarea">Long text</flux:select.option>
                                <flux:select.option value="choice">Choice</flux:select.option>
                                <flux:select.option value="image">Image</flux:select.option>
                            </flux:select>
                            <flux:checkbox wire:model="customerInputs.{{ $index }}.required" label="Required" />
                            @if (in_array($input['kind'] ?? 'text', ['text', 'textarea'], true))
                                <flux:input wire:model="customerInputs.{{ $index }}.max_chars" type="number" label="Max characters" />
                                <flux:select wire:model="customerInputs.{{ $index }}.pattern" label="Pattern">
                                    <flux:select.option value="">None</flux:select.option>
                                    @foreach (\App\Services\Shop\CustomerInputDefinition::patterns() as $key => $pattern)
                                        <flux:select.option value="{{ $key }}">{{ $pattern['label'] ?? $key }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @endif
                            @if (($input['kind'] ?? '') === 'choice')
                                <flux:textarea wire:model="customerInputs.{{ $index }}.optionsText" label="Options (one per line)" rows="4" />
                            @endif
                            @if (($input['kind'] ?? '') === 'image')
                                <flux:input wire:model="customerInputs.{{ $index }}.max_files" type="number" label="Max files" />
                            @endif
                            <flux:input wire:model="customerInputs.{{ $index }}.help" label="Help" />
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No customer inputs.') }}</p>
                    @endforelse
                </div>
            </flux:card>

            <flux:card>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="lg">{{ __('Variants') }}</flux:heading>
                    <flux:button size="sm" wire:click="startAddingVariant" wire:target="startAddingVariant">{{ __('Add variant') }}</flux:button>
                </div>

                @if ($variants->isEmpty())
                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No variants yet.') }}</p>
                @else
                    <div class="mt-4">
                        <flux:table>
                            <flux:table.columns>
                                @foreach ($variantColumns as $column)
                                    <flux:table.column data-variant-column="{{ $column['key'] }}">{{ $column['label'] }}</flux:table.column>
                                @endforeach
                            </flux:table.columns>
                            <flux:table.rows>
                                @foreach ($variants as $v)
                                    @php $isEditing = $editingVariantId === $v->id; @endphp
                                    <flux:table.row wire:key="variant-{{ $v->id }}" wire:click="startEditingVariant({{ $v->id }})">
                                        @foreach ($variantColumns as $column)
                                            <flux:table.cell>
                                                @if ($column['key'] === 'label')
                                                    @if ($isEditing)
                                                        <flux:input wire:model="editingVariantLabel" wire:click.stop size="sm" />
                                                    @else
                                                        {{ $v->shopperFacingLabel() }}
                                                    @endif
                                                @elseif ($column['key'] === 'sku')
                                                    @if ($isEditing)
                                                        <flux:input wire:model="editingVariantSku" wire:click.stop size="sm" class="font-mono" />
                                                    @else
                                                        <span class="font-mono">{{ $v->sku }}</span>
                                                    @endif
                                                @elseif ($column['key'] === 'price')
                                                    @if ($isEditing)
                                                        <flux:input type="number" wire:model="editingVariantPriceCents" wire:click.stop size="sm" class="font-mono" />
                                                    @else
                                                        <span class="font-mono">{{ \App\Support\ShopMoney::format((int) $v->price_cents, $shopCurrency) }}</span>
                                                    @endif
                                                @elseif ($column['key'] === 'weight')
                                                    @if ($isEditing)
                                                        <flux:input type="number" wire:model="editingVariantWeightGrams" wire:click.stop size="sm" min="0" max="100000" />
                                                    @else
                                                        {{ $v->weight_grams ?? '—' }}
                                                    @endif
                                                @elseif ($column['key'] === 'on_hand')
                                                    @if ($isEditing)
                                                        <flux:input type="number" wire:model="editingVariantOnHand" wire:click.stop size="sm" />
                                                    @else
                                                        {{ (int) ($onHand[$v->id] ?? 0) }}
                                                    @endif
                                                @elseif ($column['key'] === 'image')
                                                    @if ($v->images->isNotEmpty())
                                                        <img src="{{ $v->images->first()->url() }}" alt="" class="size-10 rounded object-cover">
                                                    @elseif ($isEditing)
                                                        <div wire:click.stop>
                                                            <x-media-picker :site-id="$siteId" model="variantImageMediaId" kinds="image" slot-label="Variant image" aspect="1:1" />
                                                        </div>
                                                    @else
                                                        <span class="text-xs text-zinc-400">—</span>
                                                    @endif
                                                @elseif ($column['key'] === 'actions')
                                                    <div class="flex justify-end gap-1" wire:click.stop>
                                                        @if ($isEditing)
                                                            <flux:button size="xs" variant="primary" wire:click="saveVariantRow" wire:target="saveVariantRow">{{ __('Save row') }}</flux:button>
                                                        @endif
                                                        @if ($canManageStaffFields && $variants->count() > 1)
                                                            <flux:button size="xs" variant="ghost" wire:click="deleteVariant({{ $v->id }})" wire:confirm="Delete this variant?" wire:target="deleteVariant">{{ __('Delete') }}</flux:button>
                                                        @endif
                                                    </div>
                                                @endif
                                            </flux:table.cell>
                                        @endforeach
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                @endif

                @if ($addingVariant || $errors->has('newVariantSku') || $errors->has('newVariantLabel') || $errors->has('newVariantPriceCents') || $errors->has('newVariantWeightGrams'))
                    <div class="mt-4 flex flex-col sm:flex-row sm:flex-wrap gap-2 items-start">
                        <flux:input wire:model="newVariantSku" placeholder="SKU" />
                        <flux:input wire:model="newVariantLabel" placeholder="Label" />
                        <flux:input type="number" wire:model="newVariantPriceCents" placeholder="Price (pence)" />
                        <flux:input type="number" wire:model="newVariantWeightGrams" placeholder="Weight (g)" min="0" max="100000" />
                        <flux:button variant="primary" wire:click="addVariant" wire:target="addVariant">{{ __('Add variant') }}</flux:button>
                    </div>
                @endif
                <flux:error name="newVariantSku" />
                <flux:error name="newVariantLabel" />
                <flux:error name="newVariantPriceCents" />
                <flux:error name="newVariantWeightGrams" />
                <flux:error name="editingVariantId" />
                <flux:error name="editingVariantWeightGrams" />
            </flux:card>
        </div>

        <div class="space-y-6">
            <flux:card>
                <flux:heading size="lg">{{ __('Status') }}</flux:heading>
                <div class="mt-4">
                    <flux:select wire:model="status" label="Status">
                        <flux:select.option value="draft">Draft</flux:select.option>
                        <flux:select.option value="published">Published</flux:select.option>
                    </flux:select>
                </div>
            </flux:card>

            <flux:card>
                <flux:heading size="lg">{{ __('Storefront') }}</flux:heading>
                <div class="mt-4 space-y-4">
                    <flux:switch wire:model="priceFrom" label="Show as 'from' price" />
                    @if ($canManageStaffFields)
                        <flux:select wire:model="taxClassId" label="Tax class">
                            <flux:select.option value="">— none —</flux:select.option>
                            @foreach ($taxClasses as $taxClass)
                                <flux:select.option value="{{ $taxClass->id }}">{{ $taxClass->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                    <flux:input wire:model="slug" label="Slug" class="font-mono" />
                    <p class="text-xs text-amber-800 dark:text-amber-200">{{ __('Changing it changes the URL. Renaming the product never changes the slug automatically.') }}</p>
                </div>
            </flux:card>

            @if ($tagVocabulary !== [])
                <flux:card>
                    <flux:heading size="lg">{{ __('Tags') }}</flux:heading>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Up to 5 from the site vocabulary.') }}</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($tagVocabulary as $tag)
                            @php $on = in_array($tag['slug'], $selectedTags, true); @endphp
                            <button
                                type="button"
                                wire:click="toggleTag('{{ $tag['slug'] }}')"
                                class="rounded-full px-3 py-1 text-sm {{ $on ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200' }}"
                            >{{ $tag['label'] }}</button>
                        @endforeach
                    </div>
                    <flux:error name="selectedTags" />
                </flux:card>
            @endif

            <flux:card>
                <flux:heading size="lg">{{ __('Sales') }}</flux:heading>
                <div class="mt-3 space-y-2">
                    @if ($paidOrderCount === 0)
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No sales yet') }}</p>
                    @else
                        <p class="text-sm text-zinc-800 dark:text-zinc-200">
                            {{ trans_choice(':count paid order|:count paid orders', $paidOrderCount, ['count' => $paidOrderCount]) }}
                        </p>
                    @endif
                    <flux:button variant="ghost" size="sm" :href="$ordersUrl">{{ __('View orders') }}</flux:button>
                </div>
            </flux:card>

            @if ($canManageStaffFields && $product->is_ai_seeded)
                <flux:card>
                    <flux:heading size="lg">{{ __('AI provenance') }}</flux:heading>
                    <dl class="mt-3 space-y-1 text-sm text-zinc-700 dark:text-zinc-300">
                        <div><dt class="inline font-medium">{{ __('Source') }}:</dt> <dd class="inline">{{ $product->ai_seed_source }}</dd></div>
                        <div><dt class="inline font-medium">{{ __('Model') }}:</dt> <dd class="inline">{{ $product->ai_model_version }}</dd></div>
                        <div><dt class="inline font-medium">{{ __('Seeded at') }}:</dt> <dd class="inline">{{ $product->ai_seeded_at?->timezone(config('app.timezone'))->toDayDateTimeString() }}</dd></div>
                    </dl>
                    <div class="mt-4">
                        <flux:switch wire:model="isAiReviewed" label="Reviewed" />
                    </div>
                </flux:card>
            @endif
        </div>
    </div>
</div>

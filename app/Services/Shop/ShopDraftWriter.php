<?php

namespace App\Services\Shop;

use App\Enums\Shop\InventoryReason;
use App\Enums\Shop\ProductStatus;
use App\Exceptions\Shop\ProductNotPublishableException;
use App\Exceptions\Shop\ProductRevisionConflictException;
use App\Exceptions\Shop\UnknownProductTagsException;
use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ProductImage;
use App\Models\Shop\ProductVariant;
use App\Models\Shop\ShopDraft;
use App\Models\Site;
use App\Observers\Shop\CatalogObserver;
use App\Services\Site\Editor\Shop\ShopWriteOperation;
use App\Support\Shop\ProductReviewNotes;
use App\Support\Shop\ProductTagAssignment;
use App\Support\Shop\ProductTagVocabulary;
use App\Support\Shop\ShopSlug;
use App\Support\Shop\ShopUrls;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ShopDraftWriter
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * Run catalogue writes under the canonical shop locks with observer
     * dispatch suppressed. Returns a deferred closure that dispatches exactly
     * one RebuildShopSnapshot after commit.
     *
     * @param  list<int>  $subjectProductIds
     * @param  callable(): void  $work
     * @return \Closure(): void
     */
    public function write(Site $site, array $subjectProductIds, ?int $actorUserId, callable $work): \Closure
    {
        $nested = CatalogObserver::isMuted();
        CatalogObserver::mute();

        try {
            $dirty = DB::transaction(function () use ($site, $subjectProductIds, $actorUserId, $work, $nested): array {
                ShopWriteOperation::lockSubject($site, $subjectProductIds, $actorUserId);
                $work();

                return $nested ? [] : CatalogObserver::takeDirtySiteIds();
            });
        } finally {
            CatalogObserver::unmute();
        }

        return function () use ($site, $dirty): void {
            if ($dirty === []) {
                return;
            }

            RebuildShopSnapshot::dispatch($site->id)->afterCommit();
        };
    }

    /**
     * @param  array{
     *     name: string,
     *     slug?: string,
     *     description?: ?string,
     *     facts?: array<string, mixed>|null,
     *     category_id?: int,
     *     tax_class_id?: ?int,
     *     is_ai_seeded?: bool,
     *     is_ai_reviewed?: bool,
     *     ai_seed_source?: ?string,
     *     variants: list<array{sku: string, label?: ?string, price_cents: int, weight_grams?: ?int}>,
     *     images?: list<array{path: string, sort_order?: int, alt?: ?string}>,
     *     customer_inputs?: list<array<string, mixed>>,
     *     tags?: list<string>
     * }  $input
     * @return array{product: Product, deferred: \Closure(): void}
     */
    public function createDraft(Site $site, array $input, ?int $actorUserId = null, bool $bumpCatalogue = true): array
    {
        $product = null;
        $deferred = $this->write($site, [], $actorUserId, function () use ($site, $input, $actorUserId, $bumpCatalogue, &$product): void {
            $seeded = (bool) ($input['is_ai_seeded'] ?? false);
            $customerInputs = $input['customer_inputs'] ?? $site->default_customer_inputs ?? [];
            if ($customerInputs !== [] && $customerInputs !== null) {
                $customerInputs = CustomerInputDefinition::normalize($customerInputs);
            } else {
                $customerInputs = [];
            }

            $explicitSlug = isset($input['slug']) && is_string($input['slug']) && $input['slug'] !== ''
                ? $input['slug']
                : null;
            if ($explicitSlug !== null) {
                $this->assertWritableProductSlug($explicitSlug);
            }

            $attempts = 0;
            while ($product === null) {
                $slug = $explicitSlug ?? ShopSlug::uniqueProduct($site->id, $input['name']);
                $this->assertWritableProductSlug($slug);
                try {
                    $product = DB::transaction(function () use ($site, $input, $slug, $seeded, $customerInputs): Product {
                        $attrs = [
                            'site_id' => $site->id,
                            'slug' => $slug,
                            'name' => $input['name'],
                            'description' => $input['description'] ?? null,
                            'facts' => $input['facts'] ?? null,
                            'status' => ProductStatus::Draft,
                            'tax_class_id' => $input['tax_class_id'] ?? null,
                            'customer_inputs' => $customerInputs,
                            'is_ai_seeded' => $seeded,
                            'is_ai_reviewed' => (bool) ($input['is_ai_reviewed'] ?? false),
                            'ai_seed_source' => $input['ai_seed_source'] ?? null,
                            'ai_seeded_at' => $seeded ? now() : null,
                        ];
                        if (array_key_exists('tags', $input)) {
                            $attrs['tags'] = $this->parsedTags($site, $input['tags']);
                        }

                        return Product::query()->create($attrs);
                    });
                } catch (UniqueConstraintViolationException $e) {
                    $attempts++;
                    if ($explicitSlug !== null || $attempts >= 8) {
                        throw $e;
                    }
                }
            }

            $categorySync = [];
            if (isset($input['category_id'])) {
                $categorySync['primary_category_id'] = $input['category_id'];
            }
            if (array_key_exists('extra_category_ids', $input)) {
                $categorySync['extra_category_ids'] = $input['extra_category_ids'];
            }
            if ($categorySync !== []) {
                $this->syncCategories($product, $categorySync);
            }

            foreach ($input['variants'] as $variantInput) {
                $variant = ProductVariant::query()->create([
                    'product_id' => $product->id,
                    'sku' => $variantInput['sku'],
                    'label' => $variantInput['label'] ?? null,
                    'price_cents' => $variantInput['price_cents'],
                    'weight_grams' => $variantInput['weight_grams'] ?? null,
                ]);
                $this->stock->initialiseVariant($variant->id);
                $stock = $variantInput['stock'] ?? null;
                if (is_int($stock) && $stock > 0) {
                    $this->stock->recordMovement($variant->id, $stock, InventoryReason::Import, 'import_products');
                }
            }

            foreach ($input['images'] ?? [] as $imageInput) {
                ProductImage::query()->create([
                    'product_id' => $product->id,
                    'path' => $imageInput['path'],
                    'sort_order' => $imageInput['sort_order'] ?? 0,
                    'alt' => $imageInput['alt'] ?? null,
                ]);
            }

            $this->bumpRevisions($site, $product, $actorUserId, $bumpCatalogue);
        });

        return ['product' => $product, 'deferred' => $deferred];
    }

    /**
     * @param  array{
     *     name?: string,
     *     description?: ?string,
     *     facts?: array<string, mixed>|null,
     *     tax_class_id?: ?int,
     *     primary_category_id?: ?int,
     *     extra_category_ids?: list<int>,
     *     variants?: list<array{sku: string, label?: ?string, price_cents: int, weight_grams?: ?int}>,
     *     customer_inputs?: list<array<string, mixed>>,
     *     tags?: list<string>
     * }  $input
     * @return array{product: Product, deferred: \Closure(): void}
     */
    public function updateDraft(Site $site, Product $product, array $input, ?int $actorUserId = null): array
    {
        $updated = null;
        $deferred = $this->write($site, [$product->id], $actorUserId, function () use ($site, $product, $input, $actorUserId, &$updated): void {
            $row = Product::query()->whereKey($product->id)->firstOrFail();
            $attrs = [];
            if (array_key_exists('name', $input)) {
                $attrs['name'] = $input['name'];
            }
            if (array_key_exists('description', $input)) {
                $attrs['description'] = $input['description'];
            }
            if (array_key_exists('facts', $input)) {
                $attrs['facts'] = $input['facts'] === [] ? null : $input['facts'];
            }
            if (array_key_exists('tax_class_id', $input)) {
                $attrs['tax_class_id'] = $input['tax_class_id'];
            }
            if (array_key_exists('tags', $input)) {
                $attrs['tags'] = $this->parsedTags($site, $input['tags']);
            }
            if (array_key_exists('customer_inputs', $input)) {
                $attrs['customer_inputs'] = CustomerInputDefinition::normalize($input['customer_inputs']);
            }
            if ($attrs !== []) {
                $row->update($attrs);
            }

            foreach ($input['variants'] ?? [] as $variantInput) {
                $existing = ProductVariant::query()
                    ->where('product_id', $row->id)
                    ->where('sku', $variantInput['sku'])
                    ->first();

                if ($existing !== null) {
                    $existingUpdate = ['price_cents' => $variantInput['price_cents']];
                    if (array_key_exists('label', $variantInput)) {
                        $existingUpdate['label'] = $variantInput['label'];
                    }
                    if (array_key_exists('weight_grams', $variantInput)) {
                        $existingUpdate['weight_grams'] = $variantInput['weight_grams'];
                    }
                    $existing->update($existingUpdate);

                    continue;
                }

                $created = ProductVariant::query()->create([
                    'product_id' => $row->id,
                    'sku' => $variantInput['sku'],
                    'label' => $variantInput['label'] ?? null,
                    'price_cents' => $variantInput['price_cents'],
                    'weight_grams' => $variantInput['weight_grams'] ?? null,
                ]);
                $this->stock->initialiseVariant($created->id);
            }
            $this->settlePriceNote($row);

            $this->syncCategories($row, $input);

            $this->bumpRevisions($site, $row, $actorUserId);
            $updated = $row->fresh();
        });

        return ['product' => $updated, 'deferred' => $deferred];
    }

    /**
     * @param  array{path: string, sort_order?: int, alt?: ?string}  $input
     * @return array{product: Product, image: ProductImage, deferred: \Closure(): void}
     */
    public function attachImage(Site $site, Product $product, array $input, ?int $actorUserId = null): array
    {
        $image = null;
        $deferred = $this->write($site, [$product->id], $actorUserId, function () use ($site, $product, $input, $actorUserId, &$image): void {
            $row = Product::query()->whereKey($product->id)->firstOrFail();
            $image = ProductImage::query()->create([
                'product_id' => $row->id,
                'path' => $input['path'],
                'sort_order' => $input['sort_order'] ?? 0,
                'alt' => $input['alt'] ?? null,
            ]);
            $this->bumpRevisions($site, $row, $actorUserId);
        });

        return ['product' => $product->fresh(), 'image' => $image, 'deferred' => $deferred];
    }

    /**
     * Human product-editor save: same locks and revision bumps as an agent write.
     *
     * @param  array{name: string, description: ?string, facts?: array<string, mixed>|null, tax_class_id: ?int, primary_category_id: ?int, extra_category_ids?: list<int>, price_from?: bool, slug?: string, status?: string, is_ai_reviewed?: bool, revision?: int, variant_weights?: array<int|string, int|null>, customer_inputs?: list<array<string, mixed>>}  $input>
     * @return array{product: Product, deferred: \Closure(): void}
     */
    public function saveFromEditor(Site $site, Product $product, array $input, ?int $actorUserId = null): array
    {
        $updated = null;
        $deferred = $this->write($site, [$product->id], $actorUserId, function () use ($site, $product, $input, $actorUserId, &$updated): void {
            $row = Product::query()->whereKey($product->id)->firstOrFail();
            if (array_key_exists('revision', $input) && (int) $row->revision !== (int) $input['revision']) {
                throw new ProductRevisionConflictException;
            }
            $originalName = $row->name;
            $originalDescription = $row->description ?? '';
            $attrs = [
                'name' => $input['name'],
                'description' => $input['description'],
                'tax_class_id' => $input['tax_class_id'],
            ];
            if (array_key_exists('facts', $input)) {
                $attrs['facts'] = $input['facts'] === [] ? null : $input['facts'];
            }
            if (array_key_exists('price_from', $input)) {
                $attrs['price_from'] = (bool) $input['price_from'];
            }
            if (array_key_exists('slug', $input) && is_string($input['slug']) && $input['slug'] !== '') {
                $this->assertWritableProductSlug($input['slug']);
                $attrs['slug'] = $input['slug'];
            }
            if (array_key_exists('status', $input) && is_string($input['status']) && $input['status'] !== '') {
                $status = ProductStatus::from($input['status']);
                $this->assertPublishable($row, $status);
                $attrs['status'] = $status;
                if ($status === ProductStatus::Published && $row->published_at === null) {
                    $attrs['published_at'] = now();
                }
            }
            if (array_key_exists('tags', $input)) {
                $attrs['tags'] = $this->parsedTags($site, $input['tags']);
            }
            if (array_key_exists('customer_inputs', $input)) {
                $attrs['customer_inputs'] = CustomerInputDefinition::normalize($input['customer_inputs']);
            }
            $this->stampPublishedAt($row, $attrs);
            $row->update($attrs);

            if (array_key_exists('is_ai_reviewed', $input)) {
                $row->update(['is_ai_reviewed' => (bool) $input['is_ai_reviewed']]);
            }

            $contentTouched = $originalName !== $input['name']
                || $originalDescription !== (string) ($input['description'] ?? '');
            $row = $row->fresh() ?? $row;
            if ($row->is_ai_seeded && ! $row->is_ai_reviewed && $contentTouched) {
                $row->update(['is_ai_reviewed' => true]);
            }

            $this->syncCategories($row, $input);

            foreach ($input['variant_weights'] ?? [] as $variantId => $grams) {
                ProductVariant::query()
                    ->where('product_id', $row->id)
                    ->whereKey((int) $variantId)
                    ->update(['weight_grams' => $grams]);
            }

            $this->bumpRevisions($site, $row, $actorUserId);
            $updated = $row->fresh();
        });

        return ['product' => $updated, 'deferred' => $deferred];
    }

    /**
     * Human product-editor add-variant: same locks, stock init, and revision bumps as an agent write.
     *
     * @param  array{sku: string, label?: ?string, price_cents: int, weight_grams?: ?int}  $input
     * @return array{product: Product, variant: ProductVariant, deferred: \Closure(): void}
     */
    public function addVariantFromEditor(Site $site, Product $product, array $input, ?int $actorUserId = null): array
    {
        $created = null;
        $deferred = $this->write($site, [$product->id], $actorUserId, function () use ($site, $product, $input, $actorUserId, &$created): void {
            $row = Product::query()->whereKey($product->id)->firstOrFail();
            $created = ProductVariant::query()->create([
                'product_id' => $row->id,
                'sku' => $input['sku'],
                'label' => $input['label'] ?? null,
                'price_cents' => $input['price_cents'],
                'weight_grams' => $input['weight_grams'] ?? null,
            ]);
            $this->stock->initialiseVariant($created->id);
            $this->settlePriceNote($row);
            $this->bumpRevisions($site, $row, $actorUserId);
        });

        return ['product' => $product->fresh(), 'variant' => $created, 'deferred' => $deferred];
    }

    /**
     * Human product-editor publish/unpublish: visibility change under the canonical locks.
     *
     * @return array{product: Product, deferred: \Closure(): void}
     */
    public function setStatusFromEditor(Site $site, Product $product, ProductStatus $status, ?int $actorUserId = null, ?int $expectedRevision = null): array
    {
        $updated = null;
        $deferred = $this->write($site, [$product->id], $actorUserId, function () use ($site, $product, $status, $actorUserId, $expectedRevision, &$updated): void {
            $row = Product::query()->whereKey($product->id)->firstOrFail();
            if ($expectedRevision !== null && (int) $row->revision !== (int) $expectedRevision) {
                throw new ProductRevisionConflictException;
            }
            $this->assertPublishable($row, $status);
            $attrs = ['status' => $status];
            $this->stampPublishedAt($row, $attrs);
            $row->update($attrs);
            $this->bumpRevisions($site, $row, $actorUserId);
            $updated = $row->fresh();
        });

        return ['product' => $updated, 'deferred' => $deferred];
    }

    /**
     * @param  array{primary_category_id?: ?int, extra_category_ids?: list<int>}  $input
     */
    private function syncCategories(Product $row, array $input): void
    {
        $hasPrimary = array_key_exists('primary_category_id', $input);
        $hasExtras = array_key_exists('extra_category_ids', $input);
        if (! $hasPrimary && ! $hasExtras) {
            return;
        }

        $primary = $hasPrimary
            ? $input['primary_category_id']
            : $row->categories()->wherePivot('is_primary', true)->value('shop_categories.id');

        $extras = $hasExtras
            ? array_values($input['extra_category_ids'] ?? [])
            : $row->categories()->wherePivot('is_primary', false)->pluck('shop_categories.id')->all();

        $sync = [];
        if ($primary) {
            $sync[(int) $primary] = ['is_primary' => true];
        }

        foreach ($extras as $id) {
            $id = (int) $id;
            if ($id <= 0 || isset($sync[$id])) {
                continue;
            }
            $sync[$id] = ['is_primary' => false];
        }

        // Tenant integrity: every id must be a category of THIS product's site.
        if ($sync !== []) {
            $owned = Category::query()->where('site_id', $row->site_id)->whereIn('id', array_keys($sync))->pluck('id')->all();
            $foreign = array_diff(array_keys($sync), $owned);
            if ($foreign !== []) {
                throw new \InvalidArgumentException('Category ids '.implode(',', $foreign).' do not belong to this site.');
            }
        }

        $row->categories()->sync($sync);
    }

    private function assertWritableProductSlug(string $slug): void
    {
        if (ShopUrls::isReservedSlug($slug) || ShopUrls::isReservedPath($slug)) {
            throw ValidationException::withMessages([
                'slug' => ['This slug is reserved for a storefront page.'],
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function parsedTags(Site $site, mixed $raw): array
    {
        try {
            return ProductTagAssignment::parse($raw, ProductTagVocabulary::normalize($site->product_tags));
        } catch (UnknownProductTagsException $exception) {
            throw ValidationException::withMessages([
                'tags' => [$exception->getMessage()],
            ]);
        }
    }

    /**
     * A product still carrying the unpriced-import note is not for sale: its stored
     * zero is a placeholder, not a price, and the storefront would list it at
     * nothing. Every path that turns a draft into a published product passes
     * through here, so the note keeps the product off the shop until a person
     * supplies the number.
     */
    private function assertPublishable(Product $row, ProductStatus $status): void
    {
        if ($status !== ProductStatus::Published || $row->status === ProductStatus::Published) {
            return;
        }

        if (in_array('price_missing', ProductReviewNotes::normalize($row->review_notes), true)) {
            throw ProductNotPublishableException::priceMissing();
        }
    }

    /**
     * The unpriced-import note describes the variants, so it lives exactly as long
     * as one of them still sits at the placeholder zero. Call after any variant
     * price write; a product without the note is untouched.
     */
    public function settlePriceNote(Product $row): void
    {
        $notes = ProductReviewNotes::normalize($row->review_notes);
        if (! in_array('price_missing', $notes, true)) {
            return;
        }

        $unpriced = ProductVariant::query()
            ->where('product_id', $row->id)
            ->where('price_cents', '<=', 0)
            ->exists();
        if ($unpriced) {
            return;
        }

        $remaining = array_values(array_diff($notes, ['price_missing']));
        $row->update(['review_notes' => $remaining === [] ? null : $remaining]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function stampPublishedAt(Product $row, array &$attrs): void
    {
        $status = $attrs['status'] ?? null;
        $next = $status instanceof ProductStatus ? $status : (is_string($status) ? ProductStatus::tryFrom($status) : null);
        if ($next === ProductStatus::Published && $row->published_at === null) {
            $attrs['published_at'] = now();
        }
    }
    public function bumpRevisions(Site $site, Product $product, ?int $actorUserId, bool $bumpCatalogue = true): void
    {
        if ($bumpCatalogue) {
            $this->bumpCatalogue($site, $actorUserId);
        }

        $product->revision = (int) $product->revision + 1;
        $product->save();
    }

    public function bumpCatalogue(Site $site, ?int $actorUserId): int
    {
        $draft = ShopDraft::query()->where('site_id', $site->id)->firstOrFail();
        $draft->catalogue_revision = (int) $draft->catalogue_revision + 1;
        $draft->updated_by_user_id = $actorUserId;
        $draft->save();

        return (int) $draft->catalogue_revision;
    }
}

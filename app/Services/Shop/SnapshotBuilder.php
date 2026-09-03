<?php

namespace App\Services\Shop;

use App\Enums\Shop\ProductReviewStatus;
use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Category;
use App\Models\Shop\FeaturedProduct;
use App\Models\Shop\Product;
use App\Models\Shop\ProductReview;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use App\Support\MediaStorage;
use App\Support\Shop\AutoTagConfig;
use App\Support\Shop\ProductTagAssignment;
use App\Support\Shop\ProductTagResolver;
use App\Support\Shop\ProductTagVocabulary;
use App\Support\Shop\ProductFacts;
use App\Support\ShopMoney;

class SnapshotBuilder
{
    public function __construct(
        protected StockService $stock,
        protected AutoTagComputer $autoTags,
    ) {}

    public function build(int $siteId): array
    {
        $site = Site::query()->find($siteId);
        $currency = $site?->shop_currency ?: 'GBP';
        $factGroups = ProductFacts::groups($site?->product_fact_groups);

        // The snapshot is the single read model for every storefront surface, so it
        // carries drafts for the surfaces allowed to see them (preview host, signed
        // preview, admin). Public renders strip them through RenderContext — a draft
        // never reaches an anonymous visitor.
        $products = Product::where('site_id', $siteId)
            ->whereIn('status', [ProductStatus::Draft, ProductStatus::Published])
            ->with(['variants', 'images', 'categories', 'taxClass'])
            ->orderBy('id')
            ->get();

        $variantIds = $products->pluck('variants.*.id')->flatten()->unique()->all();
        $variantStockMap = $this->stock->onHandMap($variantIds);
        $ratingsByProductId = $this->publishedRatingsByProductId($siteId);

        $vocabulary = ProductTagVocabulary::normalize($site?->product_tags);
        $autoConfig = AutoTagConfig::normalize($site?->auto_tags);
        $autoByProductId = $site !== null
            ? $this->autoTags->forSite($site, $products)
            : [];

        $productsOut = [];
        foreach ($products as $product) {
            $variantsOut = [];
            $anyInStock = false;
            $variantInStock = [];
            foreach ($product->variants as $v) {
                $inStock = ($variantStockMap[$v->id] ?? 0) > 0;
                $anyInStock = $anyInStock || $inStock;
                $variantInStock[$v->id] = $inStock;
                $variantsOut[] = [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'label' => $v->label,
                    'price_cents' => $v->price_cents,
                    'weight_grams' => $v->weight_grams,
                    'image_urls' => $v->images->isNotEmpty() ? $this->imageUrls($v->images->first()->path) : null,
                ];
            }

            $primaryPriceCents = $product->variants->first()?->price_cents ?? 0;
            $priceFrom = (bool) $product->price_from;

            $productsOut[$product->slug] = [
                'id' => $product->id,
                'slug' => $product->slug,
                'status' => $product->status->value,
                'published_at' => $product->published_at?->toIso8601String(),
                'primary_category_slug' => $product->categories->firstWhere('pivot.is_primary', true)?->slug
                    ?? $product->categories->first()?->slug,
                'price_cents' => $primaryPriceCents,
                'price_display' => ShopMoney::display($primaryPriceCents, $currency, $priceFrom),
                'in_stock_any' => $anyInStock,
                'variant_in_stock' => $variantInStock,
                'image_urls' => $product->images->isNotEmpty() ? $this->imageUrls($product->images->first()->path) : null,
                'product_card' => $this->productCardView($product, $primaryPriceCents, $currency, $factGroups),
                'product_detail' => $this->productDetailView($product, $factGroups),
                'variants' => $variantsOut,
                'is_ai_seeded' => (bool) $product->is_ai_seeded,
                'is_ai_reviewed' => (bool) $product->is_ai_reviewed,
                'tags' => ProductTagResolver::resolve(
                    $vocabulary,
                    ProductTagAssignment::normalize($product->tags, $vocabulary),
                    $autoByProductId[(int) $product->id] ?? [],
                    $autoConfig,
                ),
                'customer_inputs' => is_array($product->customer_inputs) ? $product->customer_inputs : [],
            ];

            $rating = $ratingsByProductId[(int) $product->id] ?? null;
            if ($rating !== null) {
                $productsOut[$product->slug]['rating'] = $rating;
            }
        }

        $categoriesOut = [];
        $categoryPaths = [];
        $categories = Category::where('site_id', $siteId)->orderBy('sort_order')->orderBy('id')->get();
        $categoriesByPath = $categories->keyBy('path');
        $categoriesById = $categories->keyBy('id');

        foreach ($categories as $cat) {
            $slugs = $this->rolledUpProductSlugs($cat, $categories, $products);
            $parent = $cat->parent_id ? $categoriesById->get($cat->parent_id) : null;
            $categoriesOut[$cat->slug] = [
                'id' => $cat->id,
                'slug' => $cat->slug,
                'name' => $cat->name,
                'description' => $cat->description,
                'product_slugs' => $slugs,
                'hero_image_url' => $cat->hero_image_url,
                'hero_alt' => $cat->hero_alt,
                'hero_height' => $cat->hero_height ?? 'medium',
                'bg_position_y' => $cat->bg_position_y ?? 50,
                'text_zone' => $cat->text_zone ?? 'middle-left',
                'hero_width' => $cat->hero_width ?? 'boxed',
                'hero_enabled' => $cat->hero_enabled ?? true,
                'hero_mode' => is_string($cat->hero_mode) ? $cat->hero_mode : null,
                'hero_text_style' => is_string($cat->hero_text_style) ? $cat->hero_text_style : null,
                'hero_accent_word' => is_string($cat->hero_accent_word) ? $cat->hero_accent_word : null,
                'intro_band' => (bool) ($cat->intro_band ?? false),
                'parent_slug' => $parent?->slug,
                'path' => $cat->path ?: $cat->slug,
                'depth' => (int) ($cat->depth ?: 1),
                'is_anchor' => (bool) $cat->is_anchor,
                'visibility' => $cat->visibility ?: 'visible',
                'meta_title' => $cat->meta_title,
                'meta_description' => $cat->meta_description,
                'sort' => $cat->sort ?: 'manual',
                'children' => $categories
                    ->filter(fn (Category $child) => $child->parent_id === $cat->id && ($child->visibility ?: 'visible') === 'visible')
                    ->sortBy([
                        ['sort_order', 'asc'],
                        ['id', 'asc'],
                    ])
                    ->pluck('slug')
                    ->values()
                    ->all(),
                'breadcrumb' => $this->categoryBreadcrumb($cat, $categoriesByPath),
            ];
            if (($cat->visibility ?: 'visible') !== 'hidden') {
                $categoryPaths[$cat->path ?: $cat->slug] = $cat->slug;
            }
        }

        // Carry forward the shop-level hero from the current snapshot row.
        $currentSnapshot = ShopSnapshotCurrent::where('site_id', $siteId)->first();
        $currentSnapshotRow = $currentSnapshot
            ? ShopSnapshot::find($currentSnapshot->snapshot_id)
            : null;
        $shopHeroImageUrl = $currentSnapshotRow?->hero_image_url;
        $shopHeroAlt = $currentSnapshotRow?->hero_alt;
        $shopHeroHeight = $currentSnapshotRow?->hero_height ?? 'medium';
        $shopHeroBgPositionY = $currentSnapshotRow?->bg_position_y ?? 50;
        $shopTextZone = $currentSnapshotRow?->text_zone ?? 'middle-left';
        $shopHeroWidth = $currentSnapshotRow?->hero_width ?? 'boxed';
        $shopHeroEnabled = $currentSnapshotRow?->hero_enabled ?? true;
        $shopHeroHeadline = is_string($currentSnapshotRow?->hero_headline)
            ? $currentSnapshotRow->hero_headline
            : null;
        $shopHeroTextStyle = is_string($currentSnapshotRow?->hero_text_style)
            ? $currentSnapshotRow->hero_text_style
            : null;
        $previousJson = is_array($currentSnapshotRow?->json) ? $currentSnapshotRow->json : [];
        $shopHeroAccentWord = $currentSnapshotRow?->hero_accent_word
            ?? (is_string($previousJson['hero_accent_word'] ?? null) ? $previousJson['hero_accent_word'] : null);
        $sharedCategoryHero = is_array($currentSnapshotRow?->shared_category_hero)
            ? $currentSnapshotRow->shared_category_hero
            : null;

        // Featured restricted to the same visible set (draft + published) — archived excluded.
        $visibleSlugs = collect($productsOut)->keys()->flip();
        $featuredSlugs = FeaturedProduct::forSite($siteId)->active()
            ->with('product')
            ->orderBy('sort_order')
            ->get()
            ->pluck('product.slug')
            ->filter(fn ($slug) => $slug && $visibleSlugs->has($slug))
            ->values()
            ->toArray();

        $faceted = SnapshotFacets::decorate($products, $categories, $productsOut, $categoriesOut, $currency);

        return [
            'meta' => [
                'version' => 0, // stamped by RebuildShopSnapshot job
                'built_at' => now()->toIso8601String(),
                'site_id' => $siteId,
                'product_count' => $products->count(),
                'currency' => $currency,
            ],
            'hero_image_url' => $shopHeroImageUrl,
            'hero_alt' => $shopHeroAlt,
            'hero_height' => $shopHeroHeight,
            'bg_position_y' => $shopHeroBgPositionY,
            'text_zone' => $shopTextZone,
            'hero_width' => $shopHeroWidth,
            'hero_enabled' => $shopHeroEnabled,
            'hero_headline' => $shopHeroHeadline,
            'hero_text_style' => $shopHeroTextStyle,
            'hero_accent_word' => $shopHeroAccentWord,
            'shared_category_hero' => $sharedCategoryHero,
            'categories' => $faceted['categories'],
            'category_paths' => $categoryPaths,
            'products' => $faceted['products'],
            'featured_slugs' => $featuredSlugs,
            'facets' => $faceted['facets'],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Category>  $categories
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @return list<string>
     */
    private function rolledUpProductSlugs(Category $cat, $categories, $products): array
    {
        $sort = $cat->sort ?: 'manual';
        $own = $products->filter(fn (Product $product) => $product->categories->contains('id', $cat->id));
        $slugs = $this->sortedProductSlugs($own, $sort);
        $seen = array_fill_keys($slugs, true);

        if (! $cat->is_anchor) {
            return $slugs;
        }

        $descendants = $categories
            ->filter(fn (Category $other) => str_starts_with((string) $other->path, $cat->path.'/'))
            ->sortBy([
                ['depth', 'asc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ]);

        foreach ($descendants as $descendant) {
            $descendantProducts = $products->filter(
                fn (Product $product) => $product->categories->contains('id', $descendant->id),
            );
            foreach ($this->sortedProductSlugs($descendantProducts, $sort) as $slug) {
                if (! isset($seen[$slug])) {
                    $slugs[] = $slug;
                    $seen[$slug] = true;
                }
            }
        }

        return $slugs;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @return list<string>
     */
    private function sortedProductSlugs($products, string $sort): array
    {
        $sorted = match ($sort) {
            'name' => $products->sortBy(fn (Product $product) => mb_strtolower($product->name), SORT_NATURAL)->values(),
            'newest' => $products->sortByDesc('id')->values(),
            'price_asc' => $products->sortBy(fn (Product $product) => $product->variants->first()?->price_cents ?? PHP_INT_MAX)->values(),
            'price_desc' => $products->sortByDesc(fn (Product $product) => $product->variants->first()?->price_cents ?? 0)->values(),
            default => $products->sortBy('id')->values(),
        };

        return $sorted->pluck('slug')->values()->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Category>  $categoriesByPath
     * @return list<array{name: string, path: string}>
     */
    private function categoryBreadcrumb(Category $cat, $categoriesByPath): array
    {
        $crumbs = [];
        $accumulated = '';
        foreach (explode('/', (string) ($cat->path ?: $cat->slug)) as $segment) {
            $accumulated = $accumulated === '' ? $segment : $accumulated.'/'.$segment;
            $node = $categoriesByPath->get($accumulated);
            if ($node === null) {
                continue;
            }
            $crumbs[] = [
                'name' => $node->name,
                'path' => $node->path ?: $node->slug,
            ];
        }

        return $crumbs;
    }

    private function imageUrls(string $path): array
    {
        $base = MediaStorage::disk()->url($path);

        return ['thumb' => $base, 'card' => $base, 'full' => $base];
    }

    /**
     * @param  list<array{slug: string, kind: string, show_on_card: bool}>  $factGroups
     * @return array{slug: string, name: string, price_display: string, price_from: bool, facts_line?: string}
     */
    private function productCardView(Product $p, int $priceCents, string $currency, array $factGroups): array
    {
        $card = [
            'slug' => $p->slug,
            'name' => $p->name,
            'price_display' => ShopMoney::display($priceCents, $currency, (bool) $p->price_from),
            'price_from' => (bool) $p->price_from,
        ];
        $line = ProductFacts::cardLine($factGroups, is_array($p->facts) ? $p->facts : []);
        if ($line !== null) {
            $card['facts_line'] = $line;
        }

        return $card;
    }

    /**
     * @param  list<array{slug: string, kind: string}>  $factGroups
     * @return array{slug: string, name: string, description: ?string, facts?: array<string, mixed>}
     */
    private function productDetailView(Product $p, array $factGroups): array
    {
        $detail = [
            'slug' => $p->slug,
            'name' => $p->name,
            'description' => $p->description,
        ];
        $facts = ProductFacts::forCurrentGroups($factGroups, is_array($p->facts) ? $p->facts : []);
        if ($facts !== []) {
            $detail['facts'] = $facts;
        }

        return $detail;
    }

    /**
     * @return array<int, array{avg: float, count: int}>
     */
    private function publishedRatingsByProductId(int $siteId): array
    {
        $rows = ProductReview::query()
            ->where('site_id', $siteId)
            ->where('status', ProductReviewStatus::Published)
            ->selectRaw('product_id, AVG(rating) as rating_avg, COUNT(*) as rating_count')
            ->groupBy('product_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $count = (int) $row->rating_count;
            if ($count < 1) {
                continue;
            }
            $out[(int) $row->product_id] = [
                'avg' => round((float) $row->rating_avg, 1),
                'count' => $count,
            ];
        }

        return $out;
    }
}

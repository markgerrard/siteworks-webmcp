<?php

namespace App\Services\Shop;

use App\Models\Shop\Category;
use App\Models\Shop\Product;
use App\Models\Shop\ProductVariant;
use App\Support\ShopMoney;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class SnapshotFacets
{
    public const OPTIONS_CAP = 8;

    public const OPTIONS_MIN_PRODUCTS = 3;

    /**
     * Round a quartile edge in cents to shopper-sensible money:
     *   < £5 (500¢):    nearest 50p (50)
     *   < £20 (2000¢):  nearest £1 (100)
     *   < £50 (5000¢):  nearest £5 (500)
     *   < £200 (20000¢): nearest £10 (1000)
     *   else:           nearest £25 (2500)
     * A positive edge never rounds to 0.
     */
    public static function roundMoney(int $cents): int
    {
        $step = match (true) {
            $cents < 500 => 50,
            $cents < 2000 => 100,
            $cents < 5000 => 500,
            $cents < 20000 => 1000,
            default => 2500,
        };

        $rounded = (int) (round($cents / $step) * $step);

        if ($rounded <= 0 && $cents > 0) {
            return $step;
        }

        return max(0, $rounded);
    }

    public static function optionId(string $label): string
    {
        $normalized = preg_replace('/["″”]/u', 'in', $label) ?? $label;

        return Str::slug($normalized);
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, Category>  $categories
     * @param  array<string, array<string, mixed>>  $productsOut
     * @param  array<string, array<string, mixed>>  $categoriesOut
     * @return array{products: array<string, array<string, mixed>>, categories: array<string, array<string, mixed>>, facets: array<string, mixed>}
     */
    public static function decorate(
        Collection $products,
        Collection $categories,
        array $productsOut,
        array $categoriesOut,
        string $currency,
    ): array {
        $categoriesById = $categories->keyBy('id');
        $productsBySlug = $products->keyBy('slug');

        $rawOptionsBySlug = [];
        foreach ($productsOut as $slug => $entry) {
            $product = $productsBySlug->get($slug);
            $minPrice = self::minVariantPrice($product);
            $optionIds = $product instanceof Product ? self::productOptionIds($product) : [];
            $rawOptionsBySlug[$slug] = $optionIds;
            $productsOut[$slug]['f'] = [
                'c' => $product instanceof Product ? self::productCategorySlugs($product, $categoriesById) : [],
                'p' => $minPrice,
                'a' => ($entry['in_stock_any'] ?? false) ? 'in' : 'mto',
                'o' => $optionIds,
            ];
        }

        $optionFacet = self::siteOptionFacet($rawOptionsBySlug, $productsBySlug);
        $allowedOptionIds = array_column($optionFacet, 'id');
        $allowedLookup = array_fill_keys($allowedOptionIds, true);

        foreach ($productsOut as $slug => $entry) {
            $productsOut[$slug]['f']['o'] = array_values(array_filter(
                $entry['f']['o'] ?? [],
                fn (string $id): bool => isset($allowedLookup[$id]),
            ));
        }

        $allSlugs = array_keys($productsOut);
        $siteFacets = self::buildFacetBlock(
            $allSlugs,
            $productsOut,
            $categoriesOut,
            $currency,
            $optionFacet,
            parentSlug: null,
        );

        foreach ($categoriesOut as $slug => $cat) {
            $categoriesOut[$slug]['facets'] = self::buildFacetBlock(
                $cat['product_slugs'] ?? [],
                $productsOut,
                $categoriesOut,
                $currency,
                $optionFacet,
                parentSlug: $slug,
            );
        }

        return [
            'products' => $productsOut,
            'categories' => $categoriesOut,
            'facets' => $siteFacets,
        ];
    }

    /**
     * Recount facet value counts from remaining products. Bucket edges, option
     * membership, and labels stay as built so shareable `?price=` URLs stay stable.
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    public static function recount(array $json): array
    {
        $products = $json['products'] ?? [];
        if (! is_array($products)) {
            return $json;
        }

        if (isset($json['facets']) && is_array($json['facets'])) {
            $json['facets'] = self::recountBlock($json['facets'], array_keys($products), $products);
        }

        foreach ($json['categories'] ?? [] as $slug => $cat) {
            if (! is_array($cat) || ! isset($cat['facets']) || ! is_array($cat['facets'])) {
                continue;
            }
            $json['categories'][$slug]['facets'] = self::recountBlock(
                $cat['facets'],
                $cat['product_slugs'] ?? [],
                $products,
            );
        }

        return $json;
    }

    /**
     * @param  Collection<int, Category>  $categoriesById
     * @return list<string>
     */
    private static function productCategorySlugs(Product $product, Collection $categoriesById): array
    {
        $slugs = [];
        $seen = [];
        foreach ($product->categories as $category) {
            $chain = [];
            $node = $category;
            while ($node instanceof Category) {
                $chain[] = $node->slug;
                $node = $node->parent_id ? $categoriesById->get($node->parent_id) : null;
            }
            foreach (array_reverse($chain) as $slug) {
                if (isset($seen[$slug])) {
                    continue;
                }
                $seen[$slug] = true;
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * @return list<string>
     */
    private static function productOptionIds(Product $product): array
    {
        $ids = [];
        $seen = [];
        foreach ($product->variants as $variant) {
            if (! $variant instanceof ProductVariant) {
                continue;
            }
            $variant->setRelation('product', $product);
            $label = $variant->shopperFacingLabel();
            if ($label === '') {
                continue;
            }
            $id = self::optionId($label);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
        }

        return $ids;
    }

    private static function minVariantPrice(?Product $product): int
    {
        if (! $product instanceof Product || $product->variants->isEmpty()) {
            return 0;
        }

        return (int) $product->variants->min('price_cents');
    }

    /**
     * @param  array<string, list<string>>  $rawOptionsBySlug
     * @param  Collection<string, Product>  $productsBySlug
     * @return list<array{id: string, label: string, count: int}>
     */
    private static function siteOptionFacet(array $rawOptionsBySlug, Collection $productsBySlug): array
    {
        $counts = [];
        $labels = [];
        foreach ($rawOptionsBySlug as $slug => $ids) {
            $product = $productsBySlug->get($slug);
            $labelById = $product instanceof Product ? self::optionLabelsById($product) : [];
            foreach (array_unique($ids) as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
                if (! isset($labels[$id]) && isset($labelById[$id])) {
                    $labels[$id] = $labelById[$id];
                }
            }
        }

        $rows = [];
        foreach ($counts as $id => $count) {
            if ($count < self::OPTIONS_MIN_PRODUCTS) {
                continue;
            }
            $rows[] = [
                'id' => (string) $id,
                'label' => $labels[$id] ?? (string) $id,
                'count' => $count,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            if ($a['count'] !== $b['count']) {
                return $b['count'] <=> $a['count'];
            }

            return $a['id'] <=> $b['id'];
        });

        return array_slice($rows, 0, self::OPTIONS_CAP);
    }

    /**
     * @return array<string, string>
     */
    private static function optionLabelsById(Product $product): array
    {
        $labels = [];
        foreach ($product->variants as $variant) {
            if (! $variant instanceof ProductVariant) {
                continue;
            }
            $variant->setRelation('product', $product);
            $label = $variant->shopperFacingLabel();
            if ($label === '') {
                continue;
            }
            $id = self::optionId($label);
            if ($id !== '' && ! isset($labels[$id])) {
                $labels[$id] = $label;
            }
        }

        return $labels;
    }

    /**
     * @param  list<string>  $productSlugs
     * @param  array<string, array<string, mixed>>  $productsOut
     * @param  array<string, array<string, mixed>>  $categoriesOut
     * @param  list<array{id: string, label: string, count: int}>  $optionFacet
     * @return array{category: list<array<string, mixed>>, price: list<array<string, mixed>>, availability: list<array<string, mixed>>, options: list<array<string, mixed>>}
     */
    private static function buildFacetBlock(
        array $productSlugs,
        array $productsOut,
        array $categoriesOut,
        string $currency,
        array $optionFacet,
        ?string $parentSlug,
    ): array {
        $childSlugs = [];
        if ($parentSlug === null) {
            foreach ($categoriesOut as $slug => $cat) {
                $parent = $cat['parent_slug'] ?? null;
                $depth = (int) ($cat['depth'] ?? 1);
                $visible = ($cat['visibility'] ?? 'visible') === 'visible';
                if ($visible && ($parent === null || $parent === '') && $depth <= 1) {
                    $childSlugs[] = $slug;
                }
            }
        } else {
            $childSlugs = $categoriesOut[$parentSlug]['children'] ?? [];
        }

        $category = [];
        foreach ($childSlugs as $childSlug) {
            $child = $categoriesOut[$childSlug] ?? null;
            if (! is_array($child)) {
                continue;
            }
            $count = 0;
            foreach ($productSlugs as $slug) {
                $c = $productsOut[$slug]['f']['c'] ?? [];
                if (in_array($childSlug, $c, true)) {
                    $count++;
                }
            }
            $category[] = [
                'slug' => $childSlug,
                'name' => (string) ($child['name'] ?? $childSlug),
                'count' => $count,
            ];
        }

        $prices = [];
        $availabilityCounts = ['in' => 0, 'mto' => 0];
        $optionCounts = [];
        foreach ($optionFacet as $row) {
            $optionCounts[$row['id']] = 0;
        }

        foreach ($productSlugs as $slug) {
            $f = $productsOut[$slug]['f'] ?? null;
            if (! is_array($f)) {
                continue;
            }
            $prices[] = (int) ($f['p'] ?? 0);
            $a = ($f['a'] ?? 'mto') === 'in' ? 'in' : 'mto';
            $availabilityCounts[$a]++;
            foreach ($f['o'] ?? [] as $optionId) {
                if (isset($optionCounts[$optionId])) {
                    $optionCounts[$optionId]++;
                }
            }
        }

        $availability = [];
        foreach (['in' => 'In stock', 'mto' => 'Made to order'] as $id => $label) {
            if ($availabilityCounts[$id] > 0) {
                $availability[] = [
                    'id' => $id,
                    'label' => $label,
                    'count' => $availabilityCounts[$id],
                ];
            }
        }

        $options = [];
        foreach ($optionFacet as $row) {
            $count = $optionCounts[$row['id']] ?? 0;
            if ($count <= 0) {
                continue;
            }
            $options[] = [
                'id' => $row['id'],
                'label' => $row['label'],
                'count' => $count,
            ];
        }

        return [
            'category' => $category,
            'price' => self::priceBuckets($prices, $currency),
            'availability' => $availability,
            'options' => $options,
        ];
    }

    /**
     * @param  list<int>  $prices
     * @return list<array{id: int, min: int, max: int|null, label: string, count: int}>
     */
    private static function priceBuckets(array $prices, string $currency): array
    {
        if ($prices === []) {
            return [];
        }

        sort($prices);
        $n = count($prices);
        $q1 = self::roundMoney($prices[(int) floor(($n - 1) * 0.25)]);
        $q2 = self::roundMoney($prices[(int) floor(($n - 1) * 0.50)]);
        $q3 = self::roundMoney($prices[(int) floor(($n - 1) * 0.75)]);

        $edges = [$q1, $q2, $q3];
        $unique = [];
        foreach ($edges as $edge) {
            if ($edge <= 0) {
                continue;
            }
            if ($unique === [] || $edge > $unique[array_key_last($unique)]) {
                $unique[] = $edge;
            }
        }

        $bounds = [0, ...$unique, null];
        $buckets = [];
        for ($i = 0; $i < count($bounds) - 1; $i++) {
            $min = $bounds[$i];
            $max = $bounds[$i + 1];
            $count = 0;
            foreach ($prices as $price) {
                if ($price < $min) {
                    continue;
                }
                if ($max !== null && $price >= $max) {
                    continue;
                }
                $count++;
            }
            $buckets[] = [
                'id' => $i,
                'min' => $min,
                'max' => $max,
                'label' => self::priceLabel($min, $max, $currency),
                'count' => $count,
            ];
        }

        return $buckets;
    }

    private static function priceLabel(int $min, ?int $max, string $currency): string
    {
        if ($min === 0 && $max !== null) {
            return 'Under '.ShopMoney::format($max, $currency);
        }
        if ($max === null) {
            return ShopMoney::format($min, $currency).'+';
        }

        return ShopMoney::format($min, $currency).'–'.ShopMoney::format($max, $currency);
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  list<string>  $productSlugs
     * @param  array<string, array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    private static function recountBlock(array $block, array $productSlugs, array $products): array
    {
        if (isset($block['category']) && is_array($block['category'])) {
            foreach ($block['category'] as $i => $row) {
                $childSlug = $row['slug'] ?? '';
                $count = 0;
                foreach ($productSlugs as $slug) {
                    $c = $products[$slug]['f']['c'] ?? [];
                    if (in_array($childSlug, $c, true)) {
                        $count++;
                    }
                }
                $block['category'][$i]['count'] = $count;
            }
        }

        if (isset($block['price']) && is_array($block['price'])) {
            foreach ($block['price'] as $i => $row) {
                $min = (int) ($row['min'] ?? 0);
                $max = $row['max'] ?? null;
                $count = 0;
                foreach ($productSlugs as $slug) {
                    $p = (int) ($products[$slug]['f']['p'] ?? 0);
                    if ($p < $min) {
                        continue;
                    }
                    if ($max !== null && $p >= (int) $max) {
                        continue;
                    }
                    $count++;
                }
                $block['price'][$i]['count'] = $count;
            }
        }

        if (isset($block['availability']) && is_array($block['availability'])) {
            foreach ($block['availability'] as $i => $row) {
                $id = $row['id'] ?? '';
                $count = 0;
                foreach ($productSlugs as $slug) {
                    if (($products[$slug]['f']['a'] ?? null) === $id) {
                        $count++;
                    }
                }
                $block['availability'][$i]['count'] = $count;
            }
        }

        if (isset($block['options']) && is_array($block['options'])) {
            foreach ($block['options'] as $i => $row) {
                $id = $row['id'] ?? '';
                $count = 0;
                foreach ($productSlugs as $slug) {
                    $o = $products[$slug]['f']['o'] ?? [];
                    if (in_array($id, $o, true)) {
                        $count++;
                    }
                }
                $block['options'][$i]['count'] = $count;
            }
        }

        return $block;
    }
}

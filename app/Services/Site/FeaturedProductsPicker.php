<?php

namespace App\Services\Site;

use App\Models\Site;
use App\Services\Shop\RenderContext;
use App\Services\Shop\SnapshotReader;
use App\Support\Shop\ProductBlockSource;

final class FeaturedProductsPicker
{
    public function __construct(private readonly SnapshotReader $snapshots) {}

    /**
     * @param  array<string, mixed>  $section
     * @return list<array<string, mixed>>
     */
    public function productsFor(Site $site, array $section, string $mode = 'public'): array
    {
        return $this->products(
            $section,
            $this->snapshots->forSite((int) $site->getKey()),
            $mode !== 'public',
        );
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>|null  $snapshot
     * @return list<array<string, mixed>>
     */
    public function products(array $section, ?array $snapshot, bool $includeDrafts = false): array
    {
        if (! is_array($snapshot)) {
            return [];
        }

        $filtered = (new RenderContext($includeDrafts))->filterSnapshot($snapshot);
        $products = is_array($filtered['products'] ?? null) ? $filtered['products'] : [];

        if ($products === []) {
            return [];
        }

        $parsed = ProductBlockSource::parse($section['source'] ?? 'featured');
        $picked = match ($parsed['kind']) {
            'newest' => $this->fromNewest($products),
            'tag' => $this->fromTag($products, (string) $parsed['slug']),
            'category' => $this->fromCategory($filtered, $products, (string) $parsed['slug']),
            default => $this->fromFeatured($filtered, $products),
        };

        if ($picked === [] && $parsed['kind'] === 'featured') {
            $picked = $this->fromNewest($products);
        }

        if ($picked === []) {
            return [];
        }

        if (ProductBlockSource::requiresPair($parsed['kind']) && count($picked) < 2) {
            return [];
        }

        return array_slice($picked, 0, $this->clampedCount($section));
    }

    /**
     * @param  array<string, mixed>  $section
     */
    public function clampedCount(array $section): int
    {
        if (array_key_exists('limit', $section) && $section['limit'] !== null && $section['limit'] !== '') {
            return max(4, min(12, (int) $section['limit']));
        }

        return max(3, min(8, (int) ($section['count'] ?? 4)));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function fromFeatured(array $snapshot, array $products): array
    {
        $picked = [];

        foreach ($snapshot['featured_slugs'] ?? [] as $slug) {
            if (is_string($slug) && $slug !== '' && isset($products[$slug]) && is_array($products[$slug])) {
                $picked[] = $products[$slug];
            }
        }

        return $picked;
    }

    /**
     * @param  array<string, array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function fromNewest(array $products): array
    {
        $list = array_values(array_filter($products, 'is_array'));

        usort($list, fn (array $left, array $right): int => ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0)));

        return $list;
    }

    /**
     * @param  array<string, array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function fromTag(array $products, string $slug): array
    {
        $picked = [];
        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }
            foreach ($product['tags'] ?? [] as $tag) {
                if (is_array($tag) && ($tag['slug'] ?? null) === $slug) {
                    $picked[] = $product;
                    break;
                }
            }
        }

        return $picked;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function fromCategory(array $snapshot, array $products, string $slug): array
    {
        $picked = [];
        $category = is_array($snapshot['categories'][$slug] ?? null) ? $snapshot['categories'][$slug] : null;
        if ($category === null) {
            return [];
        }

        foreach ($category['product_slugs'] ?? [] as $productSlug) {
            if (is_string($productSlug) && $productSlug !== '' && isset($products[$productSlug]) && is_array($products[$productSlug])) {
                $picked[] = $products[$productSlug];
            }
        }

        return $picked;
    }
}

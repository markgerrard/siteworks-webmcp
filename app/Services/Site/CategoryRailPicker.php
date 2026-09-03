<?php

namespace App\Services\Site;

use App\Models\Site;
use App\Services\Shop\RenderContext;
use App\Services\Shop\SnapshotReader;
use App\Support\Shop\ShopUrls;

final class CategoryRailPicker
{
    public function __construct(private readonly SnapshotReader $snapshots) {}

    /**
     * @param  array<string, mixed>  $section
     * @return list<array{slug: string, name: string, href: string, image_url: string|null, alt: string}>
     */
    public function tilesFor(Site $site, array $section, string $mode = 'public'): array
    {
        $snapshot = $this->snapshots->forSite((int) $site->getKey());
        if (! is_array($snapshot)) {
            return [];
        }

        $filtered = (new RenderContext($mode !== 'public'))->filterSnapshot($snapshot);
        $categories = is_array($filtered['categories'] ?? null) ? $filtered['categories'] : [];
        $products = is_array($filtered['products'] ?? null) ? $filtered['products'] : [];

        if ($categories === []) {
            return [];
        }

        $tiles = [];
        foreach ($this->orderedSlugs($section, $categories) as $slug) {
            $category = $categories[$slug] ?? null;
            if (! is_array($category)) {
                continue;
            }

            $name = is_string($category['name'] ?? null) ? $category['name'] : $slug;
            $path = is_string($category['path'] ?? null) && $category['path'] !== ''
                ? $category['path']
                : $slug;
            $imageUrl = $this->imageUrl($category, $products);

            $tiles[] = [
                'slug' => $slug,
                'name' => $name,
                'href' => ShopUrls::collection($path),
                'image_url' => $imageUrl,
                'alt' => $name,
            ];
        }

        $tiles = array_slice($tiles, 0, $this->clampedLimit($section));

        return count($tiles) < 3 ? [] : $tiles;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    public function clampedLimit(array $section): int
    {
        return max(3, min(12, (int) ($section['limit'] ?? 8)));
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $categories
     * @return list<string>
     */
    private function orderedSlugs(array $section, array $categories): array
    {
        $requested = $section['slugs'] ?? [];
        if (! is_array($requested)) {
            $requested = [];
        }

        $requested = array_values(array_filter(
            $requested,
            fn (mixed $slug): bool => is_string($slug) && $slug !== '',
        ));

        if ($requested !== []) {
            return $requested;
        }

        $slugs = [];
        foreach ($categories as $slug => $category) {
            if (is_string($slug) && is_array($category) && $this->isTopLevel($category)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * Top-level categories have a single breadcrumb crumb. Path without `/`
     * is the fallback when breadcrumb is absent.
     *
     * @param  array<string, mixed>  $category
     */
    private function isTopLevel(array $category): bool
    {
        if (isset($category['breadcrumb']) && is_array($category['breadcrumb'])) {
            return count($category['breadcrumb']) === 1;
        }

        $path = is_string($category['path'] ?? null) ? $category['path'] : (string) ($category['slug'] ?? '');

        return $path !== '' && ! str_contains($path, '/');
    }

    /**
     * @param  array<string, mixed>  $category
     * @param  array<string, mixed>  $products
     */
    private function imageUrl(array $category, array $products): ?string
    {
        $hero = $category['hero_image_url'] ?? null;
        if (is_string($hero) && $hero !== '') {
            return $hero;
        }

        $allowed = [];
        $ownSlug = $category['slug'] ?? null;
        if (is_string($ownSlug) && $ownSlug !== '') {
            $allowed[$ownSlug] = true;
        }
        foreach ($category['children'] ?? [] as $child) {
            if (is_string($child) && $child !== '') {
                $allowed[$child] = true;
            }
        }

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }
            if (($product['status'] ?? 'published') !== 'published') {
                continue;
            }

            $primary = $product['primary_category_slug'] ?? null;
            if (! is_string($primary) || ! isset($allowed[$primary])) {
                continue;
            }

            $card = is_array($product['image_urls'] ?? null)
                ? ($product['image_urls']['card'] ?? null)
                : null;
            if (is_string($card) && $card !== '') {
                return $card;
            }
        }

        return null;
    }
}

<?php

namespace App\Support\Shop;

use App\Models\Site;
use App\Services\Shop\RenderContext;
use App\Services\Shop\SnapshotReader;
use App\Support\ShopMoney;

final class ShopSearchPanel
{
    /**
     * @return array{
     *     query: string,
     *     currentCategorySlug: string|null,
     *     categories: list<array{slug: string, name: string, path: string}>,
     *     popular: list<array{slug: string, name: string, url: string, price_display: string, image_url: string|null}>,
     *     firstCategory: array{slug: string, name: string}|null,
     *     searchUrl: string,
     *     vat: bool
     * }
     */
    public static function for(Site $site): array
    {
        $snapshot = app(SnapshotReader::class)->forSite($site->id) ?? [];
        // Preview host only. Do not call RenderContext::fromRequest() here: account
        // pages authenticate a Customer, and Customer has no isAdmin().
        $ctx = new RenderContext(includeDrafts: request()->attributes->getBoolean('is_preview_host'));
        $snapshot = $ctx->filterSnapshot($snapshot);

        $categories = [];
        foreach ($snapshot['categories'] ?? [] as $cat) {
            if (! is_array($cat) || ($cat['product_slugs'] ?? []) === []) {
                continue;
            }

            if (($cat['visibility'] ?? 'visible') !== 'visible') {
                continue;
            }

            $parentSlug = $cat['parent_slug'] ?? null;
            $depth = (int) ($cat['depth'] ?? 1);
            if (($parentSlug !== null && $parentSlug !== '') || $depth > 1) {
                continue;
            }

            $categories[] = [
                'slug' => (string) $cat['slug'],
                'name' => (string) $cat['name'],
                'path' => (string) ($cat['path'] ?? $cat['slug']),
            ];
        }

        $featured = $snapshot['featured_slugs'] ?? [];
        if ($featured === []) {
            $featured = array_slice(array_keys($snapshot['products'] ?? []), 0, 4);
        } else {
            $featured = array_slice($featured, 0, 4);
        }

        $popular = [];
        foreach ($featured as $slug) {
            $product = $snapshot['products'][$slug] ?? null;
            if (! is_array($product)) {
                continue;
            }

            $imageUrls = $product['image_urls'] ?? null;
            $popular[] = [
                'slug' => (string) $slug,
                'name' => (string) ($product['product_card']['name'] ?? $product['name'] ?? $slug),
                'url' => ShopUrls::product((string) $slug),
                'price_display' => (string) ($product['product_card']['price_display'] ?? $product['price_display'] ?? ''),
                'image_url' => is_array($imageUrls)
                    ? ($imageUrls['thumb'] ?? $imageUrls['card'] ?? null)
                    : null,
            ];
        }

        $query = trim((string) request()->query('q', ''));
        if (strlen($query) > 100) {
            $query = substr($query, 0, 100);
        }

        $current = request()->routeIs('shop.category')
            ? (string) (request()->route('path') ?? request()->route('slug'))
            : null;

        return [
            'query' => $query,
            'currentCategorySlug' => $current,
            'categories' => $categories,
            'popular' => $popular,
            'firstCategory' => $categories[0] ?? null,
            'searchUrl' => route('shop.search'),
            'vat' => ShopMoney::includesVat($site->shop_currency ?? 'GBP'),
        ];
    }
}

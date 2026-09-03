<?php

namespace App\Support\Shop;

use App\Models\Site;

final class ShopOpenGraph
{
    /**
     * @param  array<string, mixed>  $product
     */
    public static function productImage(array $product): ?string
    {
        $urls = $product['image_urls'] ?? null;
        if (! is_array($urls)) {
            return null;
        }

        foreach (['full', 'card', 'thumb'] as $size) {
            $src = $urls[$size] ?? null;
            if (is_string($src) && $src !== '') {
                return ShopJsonLd::absolute($src);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public static function productWidth(array $product): ?int
    {
        $width = $product['image_width'] ?? $product['image_urls']['width'] ?? null;

        return is_numeric($width) ? (int) $width : null;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public static function productHeight(array $product): ?int
    {
        $height = $product['image_height'] ?? $product['image_urls']['height'] ?? null;

        return is_numeric($height) ? (int) $height : null;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public static function shouldEmitPrice(array $product, string $shopMode): bool
    {
        $priceFrom = (bool) ($product['price_from'] ?? data_get($product, 'product_card.price_from', false));

        if ($priceFrom && in_array($shopMode, ['quote', 'enquire'], true)) {
            return false;
        }

        return self::priceAmount($product) !== null;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public static function priceAmount(array $product): ?string
    {
        $cents = $product['price_cents'] ?? null;
        if (! is_numeric($cents)) {
            $primary = $product['variants'][0] ?? null;
            $cents = is_array($primary) ? ($primary['price_cents'] ?? null) : null;
        }
        if (! is_numeric($cents)) {
            return null;
        }

        return number_format(((int) $cents) / 100, 2, '.', '');
    }

    public static function currency(Site $site): string
    {
        return strtoupper((string) ($site->shop_currency ?? 'GBP'));
    }

    /**
     * Category thumbnail (T22 hero) → first product image → site card.
     *
     * @param  array<string, mixed>  $category
     * @param  array<int, array<string, mixed>>  $products
     */
    public static function categoryImage(array $category, array $products, Site $site): ?string
    {
        $thumb = $category['hero_image_url'] ?? $category['thumbnail_url'] ?? null;
        if (is_string($thumb) && $thumb !== '') {
            return ShopJsonLd::absolute($thumb);
        }

        foreach ($products as $product) {
            if (! is_array($product)) {
                continue;
            }
            $image = self::productImage($product);
            if ($image !== null) {
                return $image;
            }
        }

        return $site->ogImageUrl();
    }

    /**
     * @param  array<string, mixed>  $category
     * @param  array<int, array<string, mixed>>  $products
     * @return array{width: int|null, height: int|null}
     */
    public static function categoryDimensions(array $category, array $products, Site $site): array
    {
        $thumb = $category['hero_image_url'] ?? $category['thumbnail_url'] ?? null;
        if (is_string($thumb) && $thumb !== '') {
            return [
                'width' => self::numericDimension($category['hero_image_width'] ?? $category['thumbnail_width'] ?? null),
                'height' => self::numericDimension($category['hero_image_height'] ?? $category['thumbnail_height'] ?? null),
            ];
        }

        foreach ($products as $product) {
            if (is_array($product) && self::productImage($product) !== null) {
                return ['width' => self::productWidth($product), 'height' => self::productHeight($product)];
            }
        }

        return $site->ogImageUrl() !== null
            ? $site->ogImageCardDimensions()
            : ['width' => null, 'height' => null];
    }

    private static function numericDimension(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
};

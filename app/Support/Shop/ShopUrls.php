<?php

namespace App\Support\Shop;

use App\Models\Shop\Product;

final class ShopUrls
{
    public const RESERVED_SLUGS = [
        'cart',
        'search',
        'quote',
        'checkout',
        'account',
        'enquire',
        'products',
        'collections',
    ];

    public static function product(string|Product $slugOrProduct): string
    {
        $slug = $slugOrProduct instanceof Product ? (string) $slugOrProduct->slug : $slugOrProduct;

        return '/products/'.$slug;
    }

    public static function collection(string $path): string
    {
        return '/collections/'.$path;
    }

    public static function productAbsolute(string|Product $slugOrProduct): string
    {
        return url(self::product($slugOrProduct));
    }

    public static function collectionAbsolute(string $path): string
    {
        return url(self::collection($path));
    }

    public static function isReservedSlug(string $slug): bool
    {
        return in_array($slug, self::RESERVED_SLUGS, true);
    }

    public static function isReservedPath(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if (self::isReservedSlug($segment)) {
                return true;
            }
        }

        return false;
    }
}

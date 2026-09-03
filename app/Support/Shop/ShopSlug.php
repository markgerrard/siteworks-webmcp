<?php

namespace App\Support\Shop;

use App\Models\Shop\Product;

final class ShopSlug
{
    public const SEEDER_SUFFIX = '/^(.+)-[a-z0-9]{6}$/';

    /**
     * @var (\Closure(int, string): void)|null
     */
    public static $afterAllocate = null;

    public static function uniqueProduct(int $siteId, string $name, ?int $ignoreId = null): string
    {
        $base = \Illuminate\Support\Str::slug($name);
        if ($base === '') {
            $base = 'product';
        }

        $slug = self::uniqueFromBase($siteId, $base, $ignoreId);
        $hook = self::$afterAllocate;
        self::$afterAllocate = null;
        if ($hook !== null) {
            $hook($siteId, $slug);
        }

        return $slug;
    }

    /**
     * @param  list<string>  $extraTaken
     */
    public static function uniqueFromBase(int $siteId, string $base, ?int $ignoreId = null, array $extraTaken = []): string
    {
        $candidate = $base;
        $n = 2;
        while (
            ShopUrls::isReservedSlug($candidate)
            || in_array($candidate, $extraTaken, true)
            || self::productTaken($siteId, $candidate, $ignoreId)
        ) {
            $candidate = $base.'-'.$n;
            $n++;
        }

        return $candidate;
    }

    public static function stripSeederSuffix(string $slug): ?string
    {
        if (preg_match(self::SEEDER_SUFFIX, $slug, $matches) !== 1) {
            return null;
        }

        return $matches[1] !== '' ? $matches[1] : null;
    }

    public static function currentProductSlug(int $siteId, string $slug): string
    {
        $redirect = \App\Models\Shop\ShopSlugRedirect::query()
            ->where('site_id', $siteId)
            ->where('kind', 'product')
            ->where('old_slug', $slug)
            ->value('slug');

        return is_string($redirect) && $redirect !== '' ? $redirect : $slug;
    }

    private static function productTaken(int $siteId, string $slug, ?int $ignoreId): bool
    {
        $query = Product::query()->where('site_id', $siteId)->where('slug', $slug);
        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->exists();
    }
}

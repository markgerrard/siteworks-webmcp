<?php

namespace App\Services\Shop;

use App\Support\Shop\ShopListingQuery;

final class CatalogueListing
{
    public const SORT_FEATURED = 'featured';

    public const SORT_PRICE_DESC = 'price_desc';

    public const SORT_PRICE_ASC = 'price_asc';

    public const SORT_NEWEST = 'newest';

    public const SORT_RATING = 'rating';

    public const PAGE_SIZES = [12, 24, 48];

    /**
     * @return array<string, string>
     */
    public static function sortLabels(bool $hasRating): array
    {
        $labels = [
            self::SORT_FEATURED => 'Featured',
            self::SORT_PRICE_DESC => 'Price: Highest – Lowest',
            self::SORT_PRICE_ASC => 'Price: Lowest – Highest',
            self::SORT_NEWEST => 'Newest',
        ];

        if ($hasRating) {
            $labels[self::SORT_RATING] = 'Rating';
        }

        return $labels;
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    public static function hasRating(array $products): bool
    {
        foreach ($products as $product) {
            if (is_array($product) && array_key_exists('rating', $product)) {
                return true;
            }
        }

        return false;
    }

    public static function resolveSort(?string $sort, bool $hasRating, ?string $default = null): string
    {
        $allowed = [
            self::SORT_FEATURED,
            self::SORT_PRICE_DESC,
            self::SORT_PRICE_ASC,
            self::SORT_NEWEST,
        ];
        if ($hasRating) {
            $allowed[] = self::SORT_RATING;
        }

        if ($sort === null || $sort === '') {
            $fallback = is_string($default) && $default !== '' ? $default : self::SORT_FEATURED;

            return in_array($fallback, $allowed, true) ? $fallback : self::SORT_FEATURED;
        }

        return in_array($sort, $allowed, true) ? $sort : self::SORT_FEATURED;
    }

    /**
     * Null page size (today's storefront) shows the whole list so existing
     * unpaginated HTML stays byte-identical until a merchant picks 12/24/48.
     */
    public static function resolvePageSize(?int $configured, int $total): int
    {
        if ($configured === null) {
            return max($total, 1);
        }

        return in_array($configured, self::PAGE_SIZES, true) ? $configured : self::PAGE_SIZES[0];
    }

    /**
     * Sort (and later filter/paginate) snapshot products in memory.
     *
     * @param  list<array<string, mixed>>  $products
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $facets
     * @return array{
     *     products: list<array<string, mixed>>,
     *     sort: string,
     *     sortLabels: array<string, string>,
     *     state: array<string, mixed>,
     *     total: int,
     *     filtered: int,
     *     activeFilterCount: int,
     *     hasRating: bool,
     *     page: int,
     *     lastPage: int,
     *     pageSize: int
     * }
     */
    public static function apply(array $products, array $query, array $facets = [], ?int $pageSize = null, ?string $defaultSort = null): array
    {
        $items = array_values($products);
        $hasRating = self::hasRating($items);
        $state = ShopListingQuery::parse($query, $hasRating, $defaultSort);
        $sorted = self::sort($items, $state['sort']);
        $matched = array_values(array_filter(
            $sorted,
            fn (array $product): bool => self::matches($product, $state, $facets),
        ));
        $total = count($items);
        $filtered = count($matched);
        $size = self::resolvePageSize($pageSize, $filtered);
        $lastPage = max(1, (int) ceil($filtered / $size));
        $page = min(max(1, $state['page']), $lastPage);
        $offset = ($page - 1) * $size;
        $paged = array_slice($matched, $offset, $size);
        $state['page'] = $page;

        return [
            'products' => $paged,
            'sort' => $state['sort'],
            'sortLabels' => self::sortLabels($hasRating),
            'state' => $state,
            'total' => $total,
            'filtered' => $filtered,
            'activeFilterCount' => ShopListingQuery::activeFilterCount($state),
            'hasRating' => $hasRating,
            'page' => $page,
            'lastPage' => $lastPage,
            'pageSize' => $size,
            'from' => $filtered === 0 ? 0 : $offset + 1,
            'to' => $offset + count($paged),
            'facets' => $facets,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    public static function sort(array $products, string $sort, ?string $default = null): array
    {
        $items = array_values($products);
        $resolved = self::resolveSort($sort, self::hasRating($items), $default);

        if ($items === [] || $resolved === self::SORT_FEATURED) {
            return $items;
        }

        $indexed = [];
        foreach ($items as $index => $product) {
            $indexed[] = ['product' => $product, 'index' => $index];
        }

        usort($indexed, function (array $a, array $b) use ($resolved): int {
            $cmp = match ($resolved) {
                self::SORT_PRICE_ASC => self::comparePrice($a['product'], $b['product'], descending: false),
                self::SORT_PRICE_DESC => self::comparePrice($a['product'], $b['product'], descending: true),
                self::SORT_NEWEST => self::compareNewest($a['product'], $b['product']),
                self::SORT_RATING => self::compareRating($a['product'], $b['product']),
                default => 0,
            };

            return $cmp !== 0 ? $cmp : $a['index'] <=> $b['index'];
        });

        return array_map(fn (array $row): array => $row['product'], $indexed);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function compareNewest(array $a, array $b): int
    {
        $aPublished = self::publishedTimestamp($a);
        $bPublished = self::publishedTimestamp($b);

        if ($aPublished === null && $bPublished !== null) {
            return 1;
        }
        if ($aPublished !== null && $bPublished === null) {
            return -1;
        }

        $publishedComparison = ($bPublished ?? 0) <=> ($aPublished ?? 0);

        return $publishedComparison !== 0
            ? $publishedComparison
            : ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private static function publishedTimestamp(array $product): ?int
    {
        $publishedAt = $product['published_at'] ?? null;
        if (! is_string($publishedAt) || $publishedAt === '') {
            return null;
        }

        $timestamp = strtotime($publishedAt);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $facets
     */
    public static function matches(array $product, array $state, array $facets): bool
    {
        $payload = is_array($product['f'] ?? null) ? $product['f'] : [];

        $cats = ShopListingQuery::listValues($state['cat'] ?? []);
        if ($cats !== []) {
            $owned = array_map('strval', is_array($payload['c'] ?? null) ? $payload['c'] : []);
            if (array_intersect($cats, $owned) === []) {
                return false;
            }
        }

        $prices = ShopListingQuery::listValues($state['price'] ?? []);
        if ($prices !== []) {
            $cents = self::priceCents($product);
            $hit = false;
            foreach ($facets['price'] ?? [] as $bucket) {
                if (! is_array($bucket) || ! in_array((string) ($bucket['id'] ?? ''), $prices, true)) {
                    continue;
                }
                if ($cents === null) {
                    continue;
                }
                if ($cents < (int) ($bucket['min'] ?? 0)) {
                    continue;
                }
                $max = $bucket['max'] ?? null;
                if ($max !== null && $cents >= (int) $max) {
                    continue;
                }
                $hit = true;
                break;
            }
            if (! $hit) {
                return false;
            }
        }

        $avail = ShopListingQuery::listValues($state['avail'] ?? []);
        if ($avail !== [] && ! in_array((string) ($payload['a'] ?? ''), $avail, true)) {
            return false;
        }

        $opts = ShopListingQuery::listValues($state['opt'] ?? []);
        if ($opts !== []) {
            $owned = array_map('strval', is_array($payload['o'] ?? null) ? $payload['o'] : []);
            if (array_intersect($opts, $owned) === []) {
                return false;
            }
        }

        $attrs = is_array($state['attrs'] ?? null) ? $state['attrs'] : [];
        foreach ($attrs as $group => $values) {
            $wanted = ShopListingQuery::listValues($values);
            if ($wanted === []) {
                continue;
            }
            $owned = [];
            if (isset($payload['attrs'][$group]) && is_array($payload['attrs'][$group])) {
                $owned = array_map('strval', $payload['attrs'][$group]);
            } elseif ((string) $group === 'opt') {
                $owned = array_map('strval', is_array($payload['o'] ?? null) ? $payload['o'] : []);
            }
            if (array_intersect($wanted, $owned) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $facets
     * @param  array<string, mixed>  $state
     * @return list<array{id: string, name: string, param: string, open: bool, values: list<array{id: string, label: string, count: int, checked: bool}>}>
     */
    public static function drawerGroups(array $facets, array $state): array
    {
        $groups = [];

        $priceSelected = ShopListingQuery::listValues($state['price'] ?? []);
        $priceValues = self::drawerValues($facets['price'] ?? [], $priceSelected, idKey: 'id', labelKey: 'label');
        if ($priceValues !== []) {
            $groups[] = [
                'id' => 'price',
                'name' => 'Price',
                'param' => 'price',
                'open' => $priceSelected !== [],
                'values' => $priceValues,
            ];
        }

        $catSelected = ShopListingQuery::listValues($state['cat'] ?? []);
        $catValues = self::drawerValues($facets['category'] ?? [], $catSelected, idKey: 'slug', labelKey: 'name');
        if ($catValues !== []) {
            $groups[] = [
                'id' => 'cat',
                'name' => 'Category',
                'param' => 'cat',
                'open' => $catSelected !== [],
                'values' => $catValues,
            ];
        }

        $availSelected = ShopListingQuery::listValues($state['avail'] ?? []);
        $availValues = self::drawerValues($facets['availability'] ?? [], $availSelected, idKey: 'id', labelKey: 'label');
        if ($availValues !== []) {
            $groups[] = [
                'id' => 'avail',
                'name' => 'Availability',
                'param' => 'avail',
                'open' => $availSelected !== [],
                'values' => $availValues,
            ];
        }

        $attributes = $facets['attributes'] ?? null;
        if (is_array($attributes) && $attributes !== []) {
            foreach ($attributes as $attribute) {
                if (! is_array($attribute)) {
                    continue;
                }
                $id = (string) ($attribute['id'] ?? '');
                $name = (string) ($attribute['name'] ?? '');
                if ($id === '' || $name === '') {
                    continue;
                }
                $selected = ShopListingQuery::listValues($state['attrs'][$id] ?? []);
                $values = self::drawerValues($attribute['values'] ?? [], $selected, idKey: 'id', labelKey: 'label');
                if ($values === []) {
                    continue;
                }
                $groups[] = [
                    'id' => $id,
                    'name' => $name,
                    'param' => 'attr['.$id.']',
                    'open' => $selected !== [],
                    'values' => $values,
                ];
            }
        } else {
            $optSelected = ShopListingQuery::listValues($state['opt'] ?? []);
            $optValues = self::drawerValues($facets['options'] ?? [], $optSelected, idKey: 'id', labelKey: 'label');
            if ($optValues !== []) {
                $groups[] = [
                    'id' => 'opt',
                    'name' => (string) ($facets['options_name'] ?? ''),
                    'param' => 'opt',
                    'open' => $optSelected !== [],
                    'values' => $optValues,
                ];
            }
        }

        return $groups;
    }

    /**
     * @param  list<array<string, mixed>>|array<string, mixed>  $rows
     * @param  list<string>  $selected
     * @return list<array{id: string, label: string, count: int, checked: bool}>
     */
    private static function drawerValues(mixed $rows, array $selected, string $idKey, string $labelKey): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row[$idKey] ?? '');
            if ($id === '') {
                continue;
            }
            $count = (int) ($row['count'] ?? 0);
            $checked = in_array($id, $selected, true);
            if ($count <= 0 && ! $checked) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'label' => (string) ($row[$labelKey] ?? $id),
                'count' => $count,
                'checked' => $checked,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public static function priceCents(array $product): ?int
    {
        if (isset($product['f']) && is_array($product['f']) && array_key_exists('p', $product['f']) && is_numeric($product['f']['p'])) {
            return (int) $product['f']['p'];
        }

        if (array_key_exists('price_cents', $product) && is_numeric($product['price_cents'])) {
            return (int) $product['price_cents'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private static function comparePrice(array $left, array $right, bool $descending): int
    {
        $leftPrice = self::priceCents($left);
        $rightPrice = self::priceCents($right);

        if ($leftPrice === null && $rightPrice === null) {
            return 0;
        }
        if ($leftPrice === null) {
            return 1;
        }
        if ($rightPrice === null) {
            return -1;
        }

        return $descending ? $rightPrice <=> $leftPrice : $leftPrice <=> $rightPrice;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private static function compareRating(array $left, array $right): int
    {
        $leftHas = array_key_exists('rating', $left) && is_numeric($left['rating']);
        $rightHas = array_key_exists('rating', $right) && is_numeric($right['rating']);

        if (! $leftHas && ! $rightHas) {
            return 0;
        }
        if (! $leftHas) {
            return 1;
        }
        if (! $rightHas) {
            return -1;
        }

        return ((float) $right['rating']) <=> ((float) $left['rating']);
    }
}

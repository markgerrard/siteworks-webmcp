<?php

namespace App\Services\Shop;

use App\Enums\Shop\ProductStatus;
use App\Models\Shop\Product;

class ProductSearchService
{
    public function search(int $siteId, string $query, bool $includeDrafts = false, int $limit = 50): \Illuminate\Support\Collection
    {
        $statuses = $includeDrafts
            ? [ProductStatus::Draft, ProductStatus::Published]
            : [ProductStatus::Published];

        $tsquery = self::prefixTsquery($query);
        if ($tsquery === null) {
            return collect();
        }

        $products = Product::where('site_id', $siteId)
            ->whereIn('status', $statuses)
            ->limit($limit);

        if ($products->getConnection()->getDriverName() === 'pgsql') {
            return $products
                ->whereRaw("search_vector @@ to_tsquery('english', ?)", [$tsquery])
                ->orderByRaw("ts_rank(search_vector, to_tsquery('english', ?)) DESC", [$tsquery])
                ->get();
        }

        // Without a tsvector column (the demo's SQLite), every query word must
        // appear in the name or description, case-insensitively.
        foreach (self::words($query) as $word) {
            $like = '%'.$word.'%';
            $products->where(function ($q) use ($like): void {
                $q->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw("lower(coalesce(description, '')) like ?", [$like]);
            });
        }

        return $products->orderBy('name')->get();
    }

    /**
     * Build a prefix-matching tsquery ("lem" → 'lem:*', "salted car" → 'salted:* & car:*').
     * The header search panel fetches on every keystroke, and plainto_tsquery only
     * matches whole lexemes ("lem" → 0 rows, "lemon" → 8 on the cakery). Every word is
     * quoted so tsquery operators typed by the customer (& | ! : * ( ) ') are literal.
     * Returns null for a query with no searchable words.
     */
    public static function prefixTsquery(string $query): ?string
    {
        $words = self::words($query);
        if ($words === []) {
            return null;
        }

        return implode(' & ', array_map(fn (string $w) => "'".str_replace("'", "''", $w)."':*", $words));
    }

    /**
     * Lower-cased searchable words of a query, at most eight, in order of first appearance.
     *
     * @return list<string>
     */
    public static function words(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($words)), 0, 8);
    }
}

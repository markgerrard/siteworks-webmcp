<?php

namespace App\Services\Site;

use App\Models\Shop\ProductReview;
use App\Models\Site;
use App\Models\SiteReview;
use App\Support\Shop\ShopUrls;
use Illuminate\Support\Facades\Cache;

class TrustSummary
{
    private const SOURCES = ['site', 'product', 'both'];

    /**
     * @return array{average: float, count: int, reviews: list<array{source: string, rating: int, body: string, author: string, created_at: string, product_name: ?string, product_url: ?string}>}
     */
    public function for(Site|int $site, string $sources = 'both'): array
    {
        $siteId = $site instanceof Site ? (int) $site->getKey() : $site;
        $source = in_array($sources, self::SOURCES, true) ? $sources : 'both';

        return Cache::rememberForever(
            self::cacheKey($siteId, $source),
            fn (): array => $this->build($siteId, $source),
        );
    }

    public function forget(int $siteId): void
    {
        foreach (self::SOURCES as $source) {
            Cache::forget(self::cacheKey($siteId, $source));
        }
    }

    public static function cacheKey(int $siteId, string $sources): string
    {
        return "site:{$siteId}:trust-summary:{$sources}";
    }

    /**
     * @return array{average: float, count: int, reviews: list<array{source: string, rating: int, body: string, author: string, created_at: string, product_name: ?string, product_url: ?string}>}
     */
    private function build(int $siteId, string $sources): array
    {
        $siteRows = collect();
        $productRows = collect();

        if ($sources !== 'product') {
            $siteRows = SiteReview::query()
                ->where('site_id', $siteId)
                ->approved()
                ->latest()
                ->get(['id', 'rating', 'text', 'author_name', 'created_at'])
                ->map(fn (SiteReview $review): array => [
                    'source' => 'site',
                    'rating' => (int) $review->rating,
                    'body' => (string) $review->text,
                    'author' => (string) $review->author_name,
                    'created_at' => $review->created_at->toIso8601String(),
                    'product_name' => null,
                    'product_url' => null,
                ]);
        }

        if ($sources !== 'site') {
            $productRows = ProductReview::query()
                ->where('site_id', $siteId)
                ->published()
                ->with('product:id,slug,name')
                ->latest()
                ->get(['id', 'product_id', 'rating', 'body', 'author_name', 'created_at'])
                ->map(fn (ProductReview $review): array => [
                    'source' => 'product',
                    'rating' => (int) $review->rating,
                    'body' => (string) $review->body,
                    'author' => (string) $review->author_name,
                    'created_at' => $review->created_at->toIso8601String(),
                    'product_name' => $review->product?->name,
                    'product_url' => $review->product === null ? null : ShopUrls::product($review->product),
                ]);
        }

        $reviews = $siteRows
            ->concat($productRows)
            ->sortByDesc('created_at')
            ->values();
        $count = $reviews->count();

        return [
            'average' => $count === 0 ? 0.0 : round((float) $reviews->avg('rating'), 1),
            'count' => $count,
            'reviews' => $reviews->take(8)->all(),
        ];
    }
}

<?php

namespace App\Support\Shop;

use App\Models\Shop\ProductReview;
use App\Models\Site;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ProductReviews
{
    /**
     * @param  array<string, mixed>  $product
     */
    public static function showOnCard(Site $site, array $product): bool
    {
        $settings = ProductReviewSettings::fromSite($site);
        $count = (int) data_get($product, 'rating.count', 0);

        return $settings->enabled
            && $settings->showOnCards
            && $count >= $settings->minReviewsForCard;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public static function cardMarkup(Site $site, array $product): string
    {
        if (! self::showOnCard($site, $product)) {
            return '';
        }

        return self::starsMarkup(
            (float) data_get($product, 'rating.avg', 0),
            (int) data_get($product, 'rating.count', 0),
        );
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public static function showOnPdp(Site $site, array $product): bool
    {
        $settings = ProductReviewSettings::fromSite($site);
        $count = (int) data_get($product, 'rating.count', 0);

        return $settings->enabled && $count >= 1;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public static function pdpSummaryMarkup(Site $site, array $product): string
    {
        if (! self::showOnPdp($site, $product)) {
            return '';
        }

        return self::starsMarkup(
            (float) data_get($product, 'rating.avg', 0),
            (int) data_get($product, 'rating.count', 0),
        );
    }

    public static function starsMarkup(float $avg, int $count): string
    {
        return trim(view('shop.partials.product-rating-stars', [
            'avg' => round($avg, 1),
            'count' => $count,
        ])->render());
    }

    public static function ariaLabel(float $avg, int $count): string
    {
        $avg = number_format(round($avg, 1), 1, '.', '');
        $noun = $count === 1 ? 'review' : 'reviews';

        return $avg.' out of 5, '.$count.' '.$noun;
    }

    public static function publishedPage(int $siteId, int $productId, ?int $page = null): LengthAwarePaginator
    {
        return ProductReview::query()
            ->where('site_id', $siteId)
            ->where('product_id', $productId)
            ->published()
            ->latest()
            ->paginate(10, ['*'], 'reviews_page', $page);
    }

    /**
     * @return array<int, int>
     */
    public static function distribution(int $siteId, int $productId): array
    {
        $counts = ProductReview::query()
            ->where('site_id', $siteId)
            ->where('product_id', $productId)
            ->published()
            ->selectRaw('rating, COUNT(*) as c')
            ->groupBy('rating')
            ->pluck('c', 'rating');

        $out = [];
        for ($rating = 5; $rating >= 1; $rating--) {
            $out[$rating] = (int) ($counts[$rating] ?? 0);
        }

        return $out;
    }
}

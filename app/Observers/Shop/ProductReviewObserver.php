<?php

namespace App\Observers\Shop;

use App\Jobs\Shop\RebuildShopSnapshot;
use App\Models\Shop\ProductReview;
use App\Models\Site;
use App\Services\Site\TrustSummary;

class ProductReviewObserver
{
    public function __construct(private readonly TrustSummary $trustSummary) {}

    /**
     * Request/job-scoped mute. When true, review saves record dirty site
     * ids instead of dispatching RebuildShopSnapshot.
     */
    public static bool $muted = false;

    private static int $muteDepth = 0;

    /**
     * @var array<int, true>
     */
    private static array $dirtySiteIds = [];

    public static function mute(): void
    {
        if (self::$muteDepth === 0) {
            self::$dirtySiteIds = [];
        }
        self::$muteDepth++;
        self::$muted = true;
    }

    public static function unmute(): void
    {
        self::$muteDepth = max(0, self::$muteDepth - 1);
        if (self::$muteDepth === 0) {
            self::$muted = false;
        }
    }

    /**
     * @return list<int>
     */
    public static function takeDirtySiteIds(): array
    {
        $ids = array_map(intval(...), array_keys(self::$dirtySiteIds));
        self::$dirtySiteIds = [];

        return $ids;
    }

    public function saved(ProductReview $review): void
    {
        $this->dispatchFor($review);
    }

    public function deleted(ProductReview $review): void
    {
        $this->dispatchFor($review);
    }

    private function dispatchFor(ProductReview $review): void
    {
        $siteId = (int) $review->site_id;
        if ($siteId === 0) {
            return;
        }

        $this->trustSummary->forget($siteId);

        if (! Site::shopEnabledFor($siteId)) {
            return;
        }

        if (self::$muted) {
            self::$dirtySiteIds[$siteId] = true;

            return;
        }

        RebuildShopSnapshot::dispatch($siteId)->afterCommit();
    }
}

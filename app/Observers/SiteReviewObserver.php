<?php

namespace App\Observers;

use App\Models\SiteReview;
use App\Services\Site\TrustSummary;

class SiteReviewObserver
{
    public function __construct(private readonly TrustSummary $trustSummary) {}

    public function saved(SiteReview $siteReview): void
    {
        $this->trustSummary->forget((int) $siteReview->site_id);
    }

    public function deleted(SiteReview $siteReview): void
    {
        $this->trustSummary->forget((int) $siteReview->site_id);
    }
}

<?php

namespace App\Console\Commands\Site;

use App\Enums\SiteReviewStatus;
use App\Models\SiteReview;
use App\Services\Site\PublicPageCache;
use Illuminate\Console\Command;

class ModerateSiteReviewsCommand extends Command
{
    protected $signature = 'site-reviews:moderate
        {review? : SiteReview id to moderate (omit to list pending reviews)}
        {--approve : Approve the review}
        {--reject : Reject the review}
        {--site= : When listing, only show pending reviews for this site id}';

    protected $description = 'List pending native site reviews, or approve/reject one (approval invalidates the site public page cache).';

    public function handle(PublicPageCache $publicPageCache): int
    {
        $reviewId = $this->argument('review');

        if ($reviewId === null) {
            return $this->listPending();
        }

        $review = SiteReview::find((int) $reviewId);
        if (! $review) {
            $this->error("SiteReview {$reviewId} not found.");

            return self::FAILURE;
        }

        $approve = (bool) $this->option('approve');
        $reject = (bool) $this->option('reject');
        if ($approve === $reject) {
            $this->error('Pass exactly one of --approve or --reject.');

            return self::FAILURE;
        }

        $review->update(['status' => $approve ? SiteReviewStatus::Approved : SiteReviewStatus::Rejected]);
        $publicPageCache->invalidate($review->site);

        $this->info(sprintf(
            'Review %d (%s, site %d) %s. Public page cache invalidated.',
            $review->id,
            $review->author_name,
            $review->site_id,
            $approve ? 'approved' : 'rejected',
        ));

        return self::SUCCESS;
    }

    private function listPending(): int
    {
        $pending = SiteReview::query()
            ->pending()
            ->when($this->option('site'), fn ($q, $siteId) => $q->where('site_id', (int) $siteId))
            ->latest()
            ->limit(50)
            ->get(['id', 'site_id', 'author_name', 'rating', 'text', 'created_at']);

        if ($pending->isEmpty()) {
            $this->info('No pending reviews.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Site', 'Author', 'Rating', 'Excerpt', 'Submitted'],
            $pending->map(fn (SiteReview $r) => [
                $r->id,
                $r->site_id,
                $r->author_name,
                $r->rating,
                \Illuminate\Support\Str::limit($r->text, 60),
                $r->created_at->toDateTimeString(),
            ]),
        );

        return self::SUCCESS;
    }
}

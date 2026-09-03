<?php

use App\Enums\SiteReviewStatus;
use App\Models\Site;
use App\Models\SiteReview;
use App\Services\Site\TrustSummary;
use App\Services\Site\PublicPageCache;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Locked]
    public int $siteId;

    /** Status filter: pending (default), approved, rejected, or all. */
    public string $statusFilter = 'pending';

    public ?string $statusMessage = null;

    /** 'success' or 'warning' — drives the callout variant so conflict/no-op outcomes don't render as successes. */
    public string $statusMessageVariant = 'success';

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
    }

    /**
     * Coerce the client-mutable filter to the allowlist (fail CLOSED to
     * pending on unrecognised values), clear any stale action message,
     * and reset the paginator — the new filter has its own page count.
     */
    public function updatedStatusFilter(): void
    {
        if (! in_array($this->statusFilter, ['pending', 'approved', 'rejected', 'all'], true)) {
            $this->statusFilter = 'pending';
        }

        $this->statusMessage = null;
        $this->resetPage('reviewsPage');
    }

    /**
     * Stale action messages should not outlive a page change. $pageName
     * is nullable with a default: a crafted top-level `paginators`
     * update dispatches this hook with null, which must not TypeError.
     */
    public function updatedPaginators(mixed $page, ?string $pageName = null): void
    {
        $this->statusMessage = null;
    }

    public function approve(int $reviewId): void
    {
        $this->moderate($reviewId, SiteReviewStatus::Approved);
    }

    public function reject(int $reviewId): void
    {
        $this->moderate($reviewId, SiteReviewStatus::Rejected);
    }

    /**
     * Resolve the site strictly within the authenticated user's
     * accessible set — never an unguarded Site::find() on the
     * client-mutable siteId prop (the SiteSwitcher tenant-leak shape
     * fixed in 8141493). A tampered siteId resolves to null and every
     * action fail-silently no-ops.
     */
    private function resolveOwnSite(): ?Site
    {
        return auth()->user()?->accessibleSites()->whereKey($this->siteId)->first();
    }

    /**
     * Same transition as the site-reviews:moderate CLI, but clients may
     * only moderate reviews that are still Pending — an agent decision
     * (approved/rejected) is final on this surface. The status guard
     * lives in the UPDATE's WHERE clause so a stale session's
     * double-moderation no-ops atomically instead of overwriting, and
     * the public page cache is only invalidated when a row actually
     * changed.
     */
    private function moderate(int $reviewId, SiteReviewStatus $status): void
    {
        $site = $this->resolveOwnSite();
        if (! $site) {
            return;
        }

        $review = SiteReview::query()->whereBelongsTo($site)->find($reviewId);
        if (! $review) {
            return;
        }

        $changed = SiteReview::query()
            ->whereKey($review->getKey())
            ->where('status', SiteReviewStatus::Pending)
            ->update(['status' => $status]);

        if ($changed === 0) {
            $this->statusMessage = sprintf(
                'Review from %s has already been moderated — refresh to see its current status.',
                $review->author_name,
            );
            $this->statusMessageVariant = 'warning';

            return;
        }

        app(PublicPageCache::class)->invalidate($site);
        app(TrustSummary::class)->forget((int) $site->id);

        $this->statusMessage = sprintf(
            'Review from %s %s.',
            $review->author_name,
            $status === SiteReviewStatus::Approved ? 'approved' : 'rejected',
        );
        $this->statusMessageVariant = 'success';
    }

    /**
     * Protected computed properties instead of a with() method: with()
     * is a remotely callable Livewire action whose return value (the
     * full Site model + review rows) would be JSON-encoded into the
     * response. Computed properties are evaluated via reflection, so
     * they can stay protected — and Livewire refuses to invoke
     * #[Computed] methods as actions outright.
     */
    #[Computed]
    protected function site(): ?Site
    {
        return $this->resolveOwnSite();
    }

    #[Computed]
    protected function reviews(): ?LengthAwarePaginator
    {
        $site = $this->site;
        if (! $site) {
            return null;
        }

        // Belt-and-braces fail-CLOSED: updatedStatusFilter() already
        // coerces to the allowlist, but if an unknown value ever reaches
        // the query un-coerced it must narrow to Pending — not silently
        // drop the WHERE and show every status.
        return SiteReview::query()
            ->whereBelongsTo($site)
            ->when(
                $this->statusFilter !== 'all',
                fn ($q) => $q->where(
                    'status',
                    SiteReviewStatus::tryFrom($this->statusFilter) ?? SiteReviewStatus::Pending,
                ),
            )
            ->latest()
            ->paginate(25, pageName: 'reviewsPage');
    }
};
?>

<div class="space-y-4">
    @if (! $this->site)
        <p class="text-sm text-red-600">Site not found / not authorised.</p>
    @else
        @include('livewire.review-moderation-list', ['reviews' => $this->reviews])
    @endif
</div>

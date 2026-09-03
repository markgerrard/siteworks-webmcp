<?php

use App\Models\Site;
use App\Models\SiteEnquiry;
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

    public function mount(int $siteId): void
    {
        $this->siteId = $siteId;
    }

    /**
     * Resolve the site strictly within the authenticated user's
     * accessible set — never an unguarded Site::find() on the
     * client-mutable siteId prop (the SiteSwitcher tenant-leak shape
     * fixed in 8141493).
     */
    private function resolveOwnSite(): ?Site
    {
        return auth()->user()?->accessibleSites()->whereKey($this->siteId)->first();
    }

    /**
     * Protected computed properties instead of a with() method: with()
     * is a remotely callable Livewire action whose return value (the
     * full Site model + enquiry rows) would be JSON-encoded into the
     * response. Computed properties are evaluated via reflection, so
     * they can stay protected — and Livewire refuses to invoke
     * #[Computed] methods as actions outright.
     */
    #[Computed]
    protected function site(): ?Site
    {
        return $this->resolveOwnSite();
    }

    /**
     * Read-only inbox of the client's own enquiries, newest first —
     * same scope and order as the site-enquiries:list CLI.
     */
    #[Computed]
    protected function enquiries(): ?LengthAwarePaginator
    {
        $site = $this->site;
        if (! $site) {
            return null;
        }

        return SiteEnquiry::query()
            ->whereBelongsTo($site)
            ->latest()
            ->paginate(25, pageName: 'enquiriesPage');
    }
};
?>

<div class="space-y-4">
    @if (! $this->site)
        <p class="text-sm text-red-600">Site not found / not authorised.</p>
    @else
        @include('livewire.enquiries-list', ['enquiries' => $this->enquiries])
    @endif
</div>

<?php

namespace App\Services\Site;

use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use Illuminate\Support\Collection;

class ChildPageEnumerator
{
    /**
     * Published, non-archived children of a listing page, ordered by
     * sort_order then id. Empty when the parent is unsaved.
     *
     * @return Collection<int, GeneratedPage>
     */
    public function forPage(Site $site, GeneratedPage $page): Collection
    {
        if (! $site->id || ! $page->id) {
            return collect();
        }

        return GeneratedPage::query()
            ->where('site_id', $site->id)
            ->where('parent_id', $page->id)
            ->where('status', PageStatus::Published)
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}

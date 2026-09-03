<?php

namespace App\Services\Site;

use App\Enums\ProjectItemStatus;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use Illuminate\Support\Collection;

class SimilarProjectsSelector
{
    /**
     * Same site + shared category_id, newest published first, id desc
     * tie-break, cap 2. Empty when the current page has no linked item
     * or a null category_id.
     *
     * @return Collection<int, ProjectItem>
     */
    public function forPage(Site $site, GeneratedPage $page): Collection
    {
        if (! $site->id || ! $page->id) {
            return collect();
        }

        $current = ProjectItem::query()
            ->where('site_id', $site->id)
            ->where('detail_page_id', $page->id)
            ->first();

        if ($current === null || $current->category_id === null) {
            return collect();
        }

        return ProjectItem::query()
            ->with(['image', 'detailPage'])
            ->where('site_id', $site->id)
            ->where('category_id', $current->category_id)
            ->where('status', ProjectItemStatus::Published)
            ->where('id', '!=', $current->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(2)
            ->get();
    }
}

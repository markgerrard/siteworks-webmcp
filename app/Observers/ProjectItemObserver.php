<?php

namespace App\Observers;

use App\Models\GeneratedPage;
use App\Models\ProjectCategory;
use App\Models\ProjectItem;
use Illuminate\Support\Facades\DB;

class ProjectItemObserver
{
    public function saving(ProjectItem $item): void
    {
        $this->guardSiteScopedRelationship($item, 'detail_page_id', GeneratedPage::class, 'detail page');
        $this->guardSiteScopedRelationship($item, 'category_id', ProjectCategory::class, 'project category');

        $item->content_hash = $this->computeContentHash($item);
        $item->media_hash = $this->computeMediaHash($item);
    }

    /** @param class-string<GeneratedPage|ProjectCategory> $relatedModel */
    private function guardSiteScopedRelationship(
        ProjectItem $item,
        string $foreignKey,
        string $relatedModel,
        string $relationshipName,
    ): void {
        if ((! $item->isDirty($foreignKey) && ! $item->isDirty('site_id')) || $item->{$foreignKey} === null) {
            return;
        }

        $related = $relatedModel::query()->find($item->{$foreignKey});
        if ($related === null) {
            throw new \DomainException("The project item {$relationshipName} does not exist.");
        }

        if ((int) $related->site_id !== (int) $item->site_id) {
            throw new \DomainException("The project item {$relationshipName} must belong to the same site.");
        }
    }

    public function deleting(ProjectItem $item): void
    {
        $pinned = DB::table('site_versions_current')
            ->join('site_versions', 'site_versions.id', '=', 'site_versions_current.version_id')
            ->where('site_versions_current.site_id', $item->site_id)
            ->get(['site_versions.page_revisions']);

        foreach ($pinned as $v) {
            $revisions = is_string($v->page_revisions)
                ? json_decode($v->page_revisions, true)
                : $v->page_revisions;

            if (! is_array($revisions)) {
                continue;
            }

            foreach ($revisions as $pin) {
                $revision = \App\Models\Site\PageRevision::find($pin['revision_id'] ?? null);
                if (! $revision) {
                    continue;
                }
                $sections = $revision->content_data['sections'] ?? [];
                foreach ($sections as $section) {
                    $ids = $section['item_ids'] ?? [];
                    if (in_array($item->id, $ids, true)) {
                        throw new \RuntimeException(
                            "Cannot hard-delete project_item {$item->id}: pinned in a live SiteVersion. Archive it instead."
                        );
                    }
                }
            }
        }
    }

    protected function computeContentHash(ProjectItem $item): string
    {
        return sha1(implode("\n", [
            (string) $item->title,
            (string) $item->description,
            (string) $item->category,
            $item->metrics === null ? 'null' : json_encode($item->metrics),
        ]));
    }

    protected function computeMediaHash(ProjectItem $item): string
    {
        return sha1((string) ($item->image_id ?? ''));
    }
}

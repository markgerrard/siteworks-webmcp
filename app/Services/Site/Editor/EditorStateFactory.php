<?php

namespace App\Services\Site\Editor;

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Services\Site\CompositionService;

final class EditorStateFactory
{
    public function __construct(private readonly CompositionService $composition) {}

    public function for(Site $site, ?GeneratedPage $page): EditorState
    {
        return new EditorState(
            siteId: $site->id,
            pageId: $page?->id,
            draftRevisionId: $page?->draft_revision_id ?? $page?->published_revision_id,
            compositionRevision: (int) (SiteDraft::query()->where('site_id', $site->id)->value('admin_revision') ?? 0),
            pendingPublish: GeneratedPage::query()
                ->where('site_id', $site->id)
                ->whereNotNull('draft_revision_id')
                ->exists()
                || $this->composition->hasPendingComposition($site)
                || app(DraftAssetSelections::class)->any($site),
            structureEpoch: $page?->structure_epoch,
        );
    }
}

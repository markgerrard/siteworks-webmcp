<?php

namespace App\Services\Site\Editor;

final class EditorState
{
    public function __construct(
        public int $siteId,
        public ?int $pageId,
        public ?int $draftRevisionId,
        public int $compositionRevision,
        public bool $pendingPublish,
        public ?int $structureEpoch = null,
    ) {}

    /**
     * @return array{site_id: int, page_id: int|null, draft_revision_id: int|null, composition_revision: int, pending_publish: bool, structure_epoch: int|null}
     */
    public function toArray(): array
    {
        return [
            'site_id' => $this->siteId,
            'page_id' => $this->pageId,
            'draft_revision_id' => $this->draftRevisionId,
            'composition_revision' => $this->compositionRevision,
            'pending_publish' => $this->pendingPublish,
            'structure_epoch' => $this->structureEpoch,
        ];
    }
}

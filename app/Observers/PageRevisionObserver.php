<?php

namespace App\Observers;

use App\Models\Site\PageRevision;
use App\Services\Site\Editor\SectionIdentifiers;

/**
 * Mints section ids into new and updated page revisions.
 *
 * Gated on isDirty('content_data') per § D1.1: an ungated hook would
 * write stale content_data back into the DB on pointer-only saves.
 */
class PageRevisionObserver
{
    public function saving(PageRevision $revision): void
    {
        if (! $revision->isDirty('content_data')) {
            return;
        }

        $identifiers = app(SectionIdentifiers::class);
        $ensured = $identifiers->ensure($revision->content_data);
        if ($ensured !== $revision->content_data) {
            $revision->content_data = $ensured;
        }
    }
}
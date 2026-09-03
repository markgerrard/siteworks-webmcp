<?php

namespace App\Enums;

/**
 * Describes WHO authored a draft mutation so downstream safety logic
 * (admin_revision guard, auto-publish eligibility) can reason about it.
 *
 * Replaces the legacy "null userId = pipeline" heuristic. Every call
 * into CompositionService now passes an explicit source.
 */
enum MutationSource: string
{
    /** Admin UI action — page-manager, WYSIWYG, nav editor, status dropdown. Bumps admin_revision. */
    case Admin = 'admin';

    /** Queued generation job (GenerateServicePageJob, etc.). Does NOT bump admin_revision. */
    case Pipeline = 'pipeline';

    /** Migrations, backfills, observers, internal automation. Does NOT bump admin_revision. */
    case System = 'system';

    /** True if a mutation of this source represents explicit admin intent. */
    public function isAdminIntent(): bool
    {
        return $this === self::Admin;
    }
}

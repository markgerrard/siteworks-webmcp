<?php

namespace App\Enums;

/**
 * Explicit admin intent for a generated page's public visibility.
 *
 * Important: PageStatus controls eligibility for the NEXT published
 * SiteVersion, not the currently live version. Changing a page to Draft
 * does not immediately 404 its URL on the public site — the change takes
 * effect when a new SiteVersion is published (auto or manual).
 *
 * The currently pinned SiteVersion remains the single source of truth
 * for public reachability.
 */
enum PageStatus: string
{
    /** Live — visible in page manager, included in published nav, reachable on public site. */
    case Published = 'published';

    /** Hidden — visible in page manager, excluded from published nav + public site. */
    case Draft = 'draft';

    /** Retired — hidden from page manager by default, excluded from published nav + public site, retained for recovery. */
    case Archived = 'archived';

    /**
     * Allowed status transitions. Self-transitions are treated as no-ops
     * by callers (don't bump admin_revision for Published → Published).
     *
     * Transitions NOT in this list are rejected at the PageStatusController
     * / Livewire action layer.
     */
    public function canTransitionTo(self $to): bool
    {
        if ($this === $to) {
            return false;
        }

        return match ($this) {
            self::Published => in_array($to, [self::Draft, self::Archived], true),
            self::Draft => in_array($to, [self::Published, self::Archived], true),
            self::Archived => in_array($to, [self::Draft, self::Published], true),
        };
    }

    /**
     * Transitions that should prompt the admin for confirmation in the UI.
     * Keeps destructive-feeling actions from happening on a stray click.
     */
    public function requiresConfirmationFor(self $to): bool
    {
        return $this === self::Published && $to === self::Archived;
    }

    /** Human-facing label for the status pill + dropdown options. */
    public function label(): string
    {
        return match ($this) {
            self::Published => 'Published',
            self::Draft => 'Draft',
            self::Archived => 'Archived',
        };
    }

    /** Eligible to be pinned into the next SiteVersion's page_revisions. */
    public function isEligibleForPublish(): bool
    {
        return $this === self::Published;
    }

    /** Shown on the public site after next publish (same rule as isEligibleForPublish for now). */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }
}

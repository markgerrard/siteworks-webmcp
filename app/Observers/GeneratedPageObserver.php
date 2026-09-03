<?php

namespace App\Observers;

use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use Illuminate\Support\Str;

/**
 * Keeps generated_pages.archived_at in lock-step with the status column.
 *
 * archived_at semantics: "currently archived at". NOT a historical log.
 * When status transitions away from Archived, we clear it. If audit
 * history is needed later, add a separate audit_events table — do NOT
 * overload this column with dual meaning.
 *
 * Fires on saving() (before the row persists) so the DB write is a
 * single transaction with both fields consistent. Only reacts to real
 * transitions — no-ops if status is unchanged.
 */
class GeneratedPageObserver
{
    public function saving(GeneratedPage $page): void
    {
        $this->guardImmutablePageType($page);
        $this->guardPageTypeLength($page);
        $this->guardParentIntegrity($page);
        $this->guardArchivingParent($page);

        // Mint section ids into content_data when it is actually changing.
        // Gated: only runs when content_data is dirty to avoid writing stale
        // content back on pointer-only saves (see § D1.1 lost-update hazard).
        if ($page->isDirty('content_data')) {
            $identifiers = app(\App\Services\Site\Editor\SectionIdentifiers::class);
            $ensured = $identifiers->ensure($page->content_data);
            if ($ensured !== $page->content_data) {
                $page->content_data = $ensured;
            }
        }

        // No change to status → nothing to do. (creating() → isDirty('status')
        // will be true when status != DB default, and that's fine.)
        if (! $page->isDirty('status')) {
            return;
        }

        $status = $page->status;
        // Defensive: the cast gives us an enum, but tests/raw fills might
        // pass strings. Normalise.
        if (is_string($status)) {
            $status = PageStatus::tryFrom($status) ?? PageStatus::Published;
            $page->status = $status;
        }

        if ($status === PageStatus::Archived) {
            if ($page->archived_at === null) {
                $page->archived_at = now();
            }
        } else {
            // Transitioning AWAY from Archived — clear the marker.
            if ($page->archived_at !== null) {
                $page->archived_at = null;
            }
        }
    }

    public function deleting(GeneratedPage $page): void
    {
        if (! $page->isForceDeleting()) {
            $this->guardLivePublishedChildren($page);
        }
    }

    private function guardImmutablePageType(GeneratedPage $page): void
    {
        if ($page->exists && $page->isDirty('page_type')) {
            throw new \DomainException('Generated page page_type is immutable after creation.');
        }
    }

    private function guardPageTypeLength(GeneratedPage $page): void
    {
        if ($page->isDirty('page_type') && Str::length($this->pageTypeValue($page->page_type)) > 200) {
            throw new \DomainException('Generated page page_type cannot exceed 200 characters.');
        }
    }

    private function guardParentIntegrity(GeneratedPage $page): void
    {
        if ((! $page->isDirty('parent_id') && ! $page->isDirty('site_id')) || $page->parent_id === null) {
            return;
        }

        $parent = GeneratedPage::query()->find($page->parent_id);
        if ($parent === null) {
            throw new \DomainException('The generated page parent does not exist.');
        }

        if ($page->exists && $parent->is($page)) {
            throw new \DomainException('A generated page cannot be its own parent.');
        }

        if ((int) $parent->site_id !== (int) $page->site_id) {
            throw new \DomainException('A generated page parent must belong to the same site.');
        }

        $resultingDepth = 1;
        $ancestor = $parent;
        $visitedIds = [];

        while ($ancestor !== null) {
            if ($page->exists && $ancestor->is($page)) {
                throw new \DomainException('The generated page parent would create an ancestor cycle.');
            }

            if (isset($visitedIds[$ancestor->id])) {
                throw new \DomainException('The generated page parent ancestry contains a cycle.');
            }

            if ((int) $ancestor->site_id !== (int) $page->site_id) {
                throw new \DomainException('Every generated page ancestor must belong to the same site.');
            }

            $visitedIds[$ancestor->id] = true;
            $resultingDepth++;
            if ($resultingDepth > 4) {
                throw new \DomainException('Generated page hierarchy depth cannot exceed 4.');
            }

            $ancestor = $ancestor->parent_id === null
                ? null
                : GeneratedPage::query()->find($ancestor->parent_id);
        }

        $pageType = $this->pageTypeValue($page->page_type);
        $leafSegment = Str::afterLast($pageType, '/');
        $expectedPageType = $parent->page_type.'/'.$leafSegment;

        if (preg_match('/^[a-z0-9-]+$/', $leafSegment) !== 1 || $pageType !== $expectedPageType) {
            throw new \DomainException('Generated page page_type must equal its parent path plus one [a-z0-9-]+ leaf segment.');
        }
    }

    private function pageTypeValue(mixed $pageType): string
    {
        return $pageType instanceof \BackedEnum ? (string) $pageType->value : (string) $pageType;
    }

    private function guardArchivingParent(GeneratedPage $page): void
    {
        $status = $page->status;
        if (is_string($status)) {
            $status = PageStatus::tryFrom($status);
        }

        $isArchiving = ($page->isDirty('status') && $status === PageStatus::Archived)
            || ($page->isDirty('archived_at') && $page->archived_at !== null);

        if ($isArchiving) {
            $this->guardLivePublishedChildren($page);
        }
    }

    private function guardLivePublishedChildren(GeneratedPage $page): void
    {
        $hasLivePublishedChildren = $page->children()
            ->published()
            ->whereNull('archived_at')
            ->exists();

        if ($hasLivePublishedChildren) {
            throw new \RuntimeException(
                "Cannot archive or delete generated page {$page->id}: live published children exist. Archive children instead."
            );
        }
    }
}

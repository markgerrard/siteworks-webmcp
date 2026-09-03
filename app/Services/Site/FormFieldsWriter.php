<?php

namespace App\Services\Site;

use App\Exceptions\Site\StaleRevisionException;
use App\Models\GeneratedPage;
use App\Models\Site\PageRevision;
use App\Services\PreviewSnapshotWriter;

/**
 * Synchronises form-field writes from the front-end panel and page-manager's
 * contact editor across the draft revision and latest preview snapshot.
 * The admin lead-form editor still writes through CompositionService.
 */
class FormFieldsWriter
{
    public function __construct(
        private CompositionService $composition,
        private PageService $pages,
        private PreviewSnapshotWriter $snapshots,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $fields  Already normalised by FormFieldDefinition.
     * @param  array<string, mixed>  $sectionExtras  e.g. ['title' => ..., 'submit_label' => ...]
     */
    public function write(
        GeneratedPage $page,
        int $sectionIndex,
        array $fields,
        ?int $userId = null,
        ?int $expectedBaseRevisionId = null,
        array $sectionExtras = [],
        bool $draftOnly = false,
    ): PageRevision {
        $currentBase = $page->draft_revision_id ?? $page->published_revision_id;

        if ($expectedBaseRevisionId !== null && (int) $expectedBaseRevisionId !== (int) $currentBase) {
            throw new StaleRevisionException('Page revision base is stale.');
        }

        $revision = $currentBase ? PageRevision::find($currentBase) : null;
        $content = $revision?->content_data ?? $page->content_data ?? [];

        $section = $content['sections'][$sectionIndex] ?? null;

        if (! is_array($section)) {
            throw new \InvalidArgumentException("Section {$sectionIndex} does not exist.");
        }

        $sectionType = $section['type'] ?? '';
        // lead_form keeps its custom fields under a different key to
        // contact_form; everything else about them is identical.
        $fieldsKey = $sectionType === 'lead_form' ? 'extra_fields' : 'fields';

        $section[$fieldsKey] = array_values($fields);

        if ($sectionType === 'lead_form') {
            $section['message_field_migrated'] = true;
        } elseif ($sectionType === 'contact_form') {
            $section['fields_migrated'] = true;
        }

        foreach ($sectionExtras as $key => $value) {
            $section[$key] = $value;
        }

        $content['sections'][$sectionIndex] = $section;

        $site = $page->site;

        $this->composition->applyAdminChange(
            $site,
            function () use ($page, $content, $userId) {
                $this->pages->replaceContent(
                    $page,
                    $content,
                    aiGenerated: false,
                    userId: $userId,
                );
            },
            userId: $userId,
            invalidatePublicCache: ! $draftOnly,
        );

        // Mirror into the snapshot the public site actually renders. Keyed by
        // section NAME, matching how saveSection writes it. Editor operations
        // (draftOnly) never touch the snapshot or the public cache — publish does.
        $preview = $draftOnly ? null : $site->latestPreview;
        if ($preview) {
            $this->snapshots->mutate($preview, function (&$snapshot) use ($page, $sectionType, $section) {
                $snapshot['pages'][$page->page_type][$sectionType] = $section;
            });
        }

        return PageRevision::find($page->fresh()->draft_revision_id);
    }
}

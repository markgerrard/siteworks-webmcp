<?php

namespace App\Services\Site;

use App\Exceptions\Site\PageStateException;
use App\Exceptions\Site\StaleRevisionException;
use App\Exceptions\Site\StaleStructureException;
use App\Models\GeneratedPage;
use App\Models\Site\PageRevision;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PageService
{
    public function __construct(private readonly RepeatableSectionEntries $repeatableEntries) {}

    /**
     * Apply a single field change. Creates a new revision per the editing flow:
     *   - if no draft exists: clone published (or empty content if none) into new draft revision
     *   - else: create new revision from current draft content
     * Then advance draft_revision_id pointer.
     *
     * If $expectedBaseRevisionId is provided, this method asserts atomically inside
     * the transaction that the locked page's current pointer (draft ?? published)
     * matches the expected value. Mismatch throws StaleRevisionException — closes
     * the TOCTOU window between the controller's pre-check and the write.
     *
     * If $expectedStructureEpoch is provided, the locked page's structure_epoch
     * must match; mismatch throws StaleStructureException.
     *
     * @throws StaleRevisionException
     * @throws StaleStructureException
     */
    public function editField(
        GeneratedPage $page,
        string $fieldPath,
        mixed $value,
        ?int $userId = null,
        ?int $expectedBaseRevisionId = null,
        ?int $expectedStructureEpoch = null,
    ): PageRevision {
        return $this->writeContentUnderLock(
            $page,
            $userId,
            $expectedBaseRevisionId,
            $expectedStructureEpoch,
            fn (array $base): array => $this->applyFieldWrites($base, [$fieldPath => $value]),
        );
    }

    /**
     * Apply several field writes in one locked revision. Shares editField's
     * lock, base-revision and structure-epoch checks so two paths cannot
     * land as two revisions.
     *
     * @param  array<string, mixed>  $pathsToValues
     *
     * @throws StaleRevisionException
     * @throws StaleStructureException
     */
    public function editFields(
        GeneratedPage $page,
        array $pathsToValues,
        ?int $userId = null,
        ?int $expectedBaseRevisionId = null,
        ?int $expectedStructureEpoch = null,
    ): PageRevision {
        return $this->writeContentUnderLock(
            $page,
            $userId,
            $expectedBaseRevisionId,
            $expectedStructureEpoch,
            fn (array $base): array => $this->applyFieldWrites($base, $pathsToValues),
        );
    }

    /**
     * Replace one schema-declared section entry list in its submitted order.
     *
     * @param  list<array<string, mixed>>  $entries
     *
     * @throws StaleRevisionException
     * @throws StaleStructureException
     */
    public function editRepeatableEntries(
        GeneratedPage $page,
        int $sectionIndex,
        string $listPath,
        array $entries,
        ?int $userId = null,
        ?int $expectedBaseRevisionId = null,
        ?int $expectedStructureEpoch = null,
    ): PageRevision {
        return DB::transaction(function () use ($page, $sectionIndex, $listPath, $entries, $userId, $expectedBaseRevisionId, $expectedStructureEpoch) {
            $locked = GeneratedPage::query()->lockForUpdate()->find($page->id);
            if (! $locked) {
                throw new PageStateException("Page {$page->id} not found inside transaction.");
            }

            if ($expectedBaseRevisionId !== null) {
                $currentBase = $locked->draft_revision_id ?? $locked->published_revision_id;
                if ($currentBase !== $expectedBaseRevisionId) {
                    throw new StaleRevisionException(
                        "Expected base revision {$expectedBaseRevisionId} but page is now at ".var_export($currentBase, true).'.'
                    );
                }
            }

            if ($expectedStructureEpoch !== null && (int) $locked->structure_epoch !== $expectedStructureEpoch) {
                throw new StaleStructureException(
                    "Expected structure epoch {$expectedStructureEpoch} but page is now at ".var_export($locked->structure_epoch, true).'.'
                );
            }

            $base = $this->currentEditableContent($locked);
            $section = $base['sections'][$sectionIndex] ?? null;
            if (! is_array($section) || ! is_string($section['type'] ?? null)) {
                throw ValidationException::withMessages([
                    'section_index' => 'Section index is out of range.',
                ]);
            }

            $validatedEntries = $this->repeatableEntries->validated(
                $section['type'],
                $listPath,
                $entries,
                (int) $locked->site_id,
            );
            Arr::set($base, "sections.{$sectionIndex}.{$listPath}", $validatedEntries);

            $revision = $this->createDraftRevision($locked, $base, aiGenerated: false, userId: $userId);
            $page->refresh();

            return $revision;
        });
    }

    /**
     * Replace the page's sections list under lock. Bumps structure_epoch so
     * field writes that still hold an old index are rejected.
     *
     * @param  \Closure(list<array<string, mixed>>): list<array<string, mixed>>  $transform
     *
     * @throws StaleRevisionException
     * @throws StaleStructureException
     */
    public function mutateSections(
        GeneratedPage $page,
        int $expectedBaseRevisionId,
        int $expectedStructureEpoch,
        \Closure $transform,
        ?int $userId = null,
    ): PageRevision {
        return DB::transaction(function () use ($page, $expectedBaseRevisionId, $expectedStructureEpoch, $transform, $userId) {
            $locked = GeneratedPage::lockForUpdate()->find($page->id);
            if (! $locked) {
                throw new PageStateException("Page {$page->id} not found inside transaction.");
            }

            $currentBase = $locked->draft_revision_id ?? $locked->published_revision_id;
            if ($currentBase !== $expectedBaseRevisionId) {
                throw new StaleRevisionException(
                    "Expected base revision {$expectedBaseRevisionId} but page is now at ".var_export($currentBase, true).'.'
                );
            }

            if ((int) $locked->structure_epoch !== $expectedStructureEpoch) {
                throw new StaleStructureException(
                    "Expected structure epoch {$expectedStructureEpoch} but page is now at ".var_export($locked->structure_epoch, true).'.'
                );
            }

            $content = $this->currentEditableContent($locked);
            $sections = $transform($content['sections'] ?? []);
            if (! is_array($sections) || ! array_is_list($sections)) {
                throw new \InvalidArgumentException('mutateSections transform must return a list of sections.');
            }

            $content['sections'] = $sections;

            $revision = $this->createDraftRevision($locked, $content, aiGenerated: false, userId: $userId);
            $locked->increment('structure_epoch'); // the locked, persisted row — never the caller's instance
            $page->refresh();

            return $revision;
        });
    }

    /**
     * Replace the entire content_data with a new one. Used by AI generation flows.
     */
    public function replaceContent(
        GeneratedPage $page,
        array $contentData,
        bool $aiGenerated = false,
        ?string $aiModelVersion = null,
        ?string $aiPromptUsed = null,
        ?int $userId = null,
    ): PageRevision {
        // Auto-translate legacy flat shape ({hero: ..., services: ...}) to the
        // new sections-array shape on write. Means GenerateContentJob and any
        // other AI/import path can keep emitting legacy shape — single conversion
        // boundary at the service layer. Idempotent: docs already in new shape
        // (sections key present) are passed through untouched by the translator.
        if (! isset($contentData['sections'])) {
            $contentData = app(ContentShapeTranslator::class)->translate($contentData);
        }

        // Lineage is a locking requirement, not just a column write: deriving the parent from a stale
        // in-memory pointer would record the wrong predecessor and race the pointer advance, so the
        // page row is locked and reloaded before createDraftRevision() reads the current pointer.
        return DB::transaction(function () use ($page, $contentData, $aiGenerated, $aiModelVersion, $aiPromptUsed, $userId) {
            $locked = GeneratedPage::lockForUpdate()->find($page->id);
            if (! $locked) {
                throw new PageStateException("Page {$page->id} not found inside transaction.");
            }

            $revision = $this->createDraftRevision(
                $locked,
                $contentData,
                aiGenerated: $aiGenerated,
                aiModelVersion: $aiModelVersion,
                aiPromptUsed: $aiPromptUsed,
                userId: $userId,
            );

            $page->refresh();

            return $revision;
        });
    }

    /**
     * Publish: flip pointers. NO COPY. Idempotent: no-op if no draft.
     *
     * Reloads the page row inside the transaction with lockForUpdate() so a
     * concurrent edit can't slip a newer draft in between our caller's read
     * and our pointer flip (which would lose the new draft pointer).
     */
    public function publishPage(GeneratedPage $page): void
    {
        DB::transaction(function () use ($page) {
            $locked = GeneratedPage::lockForUpdate()->find($page->id);
            if (! $locked || ! $locked->draft_revision_id) {
                return;
            }

            $locked->update([
                'published_revision_id' => $locked->draft_revision_id,
                'draft_revision_id' => null,
            ]);

            // Refresh the caller's instance so its pointers reflect committed state
            $page->refresh();
        });
    }

    /**
     * Discard the draft revision. Mirrors legacy `content_data` back to the
     * published revision's content (or empty array if no published exists yet).
     *
     * Wrapped in a transaction with lockForUpdate so the discard is atomic
     * relative to concurrent publishes/edits.
     */
    public function discardDraft(GeneratedPage $page): void
    {
        DB::transaction(function () use ($page) {
            $locked = GeneratedPage::lockForUpdate()->find($page->id);
            if (! $locked || ! $locked->draft_revision_id) {
                return;
            }

            $publishedContent = $locked->published_revision_id
                ? (PageRevision::find($locked->published_revision_id)?->content_data ?? [])
                : [];

            $locked->update([
                'draft_revision_id' => null,
                'content_data' => $publishedContent,
            ]);

            $page->refresh();
        });
    }

    /**
     * Roll a page back to a specific prior revision. The revision becomes the new published_revision_id.
     * Does NOT clear an existing draft (merchant may want to keep working).
     */
    public function rollbackToRevision(GeneratedPage $page, PageRevision $revision): void
    {
        if ($revision->page_id !== $page->id) {
            throw new PageStateException("Revision {$revision->id} does not belong to page {$page->id}.");
        }

        DB::transaction(function () use ($page, $revision) {
            $locked = GeneratedPage::lockForUpdate()->find($page->id);
            if (! $locked || $locked->id !== $page->id) {
                throw new PageStateException("Page {$page->id} not found inside rollback transaction.");
            }

            $locked->update([
                'published_revision_id' => $revision->id,
                // mirror legacy
                'content_data' => $revision->content_data,
            ]);

            $page->refresh();
        });
    }

    public function archivePage(GeneratedPage $page): void
    {
        $page->update(['archived_at' => now()]);
    }

    public function unarchivePage(GeneratedPage $page): void
    {
        $page->update(['archived_at' => null]);
    }

    /**
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $mutate
     */
    private function writeContentUnderLock(
        GeneratedPage $page,
        ?int $userId,
        ?int $expectedBaseRevisionId,
        ?int $expectedStructureEpoch,
        \Closure $mutate,
    ): PageRevision {
        return DB::transaction(function () use ($page, $userId, $expectedBaseRevisionId, $expectedStructureEpoch, $mutate) {
            $locked = GeneratedPage::lockForUpdate()->find($page->id);
            if (! $locked) {
                throw new PageStateException("Page {$page->id} not found inside transaction.");
            }

            if ($expectedBaseRevisionId !== null) {
                $currentBase = $locked->draft_revision_id ?? $locked->published_revision_id;
                if ($currentBase !== $expectedBaseRevisionId) {
                    throw new StaleRevisionException(
                        "Expected base revision {$expectedBaseRevisionId} but page is now at ".var_export($currentBase, true).'.'
                    );
                }
            }

            if ($expectedStructureEpoch !== null && (int) $locked->structure_epoch !== $expectedStructureEpoch) {
                throw new StaleStructureException(
                    "Expected structure epoch {$expectedStructureEpoch} but page is now at ".var_export($locked->structure_epoch, true).'.'
                );
            }

            $base = $this->currentEditableContent($locked);
            $content = $mutate($base);
            $rev = $this->createDraftRevision($locked, $content, aiGenerated: false, userId: $userId);
            $page->refresh();

            return $rev;
        });
    }

    /**
     * Apply path writes, then drop accent_ranges on any section whose title
     * changed without a simultaneous ranges write.
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $pathsToValues
     * @return array<string, mixed>
     */
    private function applyFieldWrites(array $content, array $pathsToValues): array
    {
        $titleIndexes = [];
        $rangeIndexes = [];

        foreach ($pathsToValues as $path => $value) {
            Arr::set($content, (string) $path, $value);
            if (preg_match('/^sections\.(\d+)\.title$/', (string) $path, $matches) === 1) {
                $titleIndexes[(int) $matches[1]] = true;
            }
            if (preg_match('/^sections\.(\d+)\.accent_ranges$/', (string) $path, $matches) === 1) {
                $rangeIndexes[(int) $matches[1]] = true;
            }
        }

        foreach ($titleIndexes as $index => $_) {
            if (isset($rangeIndexes[$index])) {
                continue;
            }
            if (is_array($content['sections'][$index] ?? null)
                && array_key_exists('accent_ranges', $content['sections'][$index])) {
                unset($content['sections'][$index]['accent_ranges']);
            }
        }

        return $content;
    }

    /**
     * Get the current "in-progress" content: draft if any, else published, else empty array.
     * Used as the base for the next edit.
     */
    protected function currentEditableContent(GeneratedPage $page): array
    {
        if ($page->draft_revision_id) {
            return PageRevision::find($page->draft_revision_id)?->content_data ?? [];
        }
        if ($page->published_revision_id) {
            return PageRevision::find($page->published_revision_id)?->content_data ?? [];
        }

        return [];
    }

    /**
     * Undo the current draft revision: write a NEW revision carrying the current draft's recorded
     * parent content, under the same lock and base/epoch checks mutateSections() uses. The new
     * revision's own parent is the undone draft (createDraftRevision() records the current pointer),
     * so undoing the undo restores the discarded content.
     *
     * Refuses — ValidationException — when the current draft revision has no recorded parent (every
     * revision written before the lineage column existed), or when the recorded parent does not
     * resolve within the same page: the lookup is always scoped to the page, never a bare
     * PageRevision::find(), so a mis-copied clone pointer cannot become a cross-tenant draft write.
     *
     * This is a draft-side operation: published_revision_id never moves here. Moving that pointer
     * (and mirroring legacy content_data as a live view) is rollbackToRevision()'s publish-side job.
     *
     * @throws StaleRevisionException
     * @throws StaleStructureException
     * @throws ValidationException When the draft revision has no resolvable recorded parent.
     */
    public function revertRevision(
        GeneratedPage $page,
        int $expectedBaseRevisionId,
        int $expectedStructureEpoch,
        ?int $userId = null,
    ): PageRevision {
        return DB::transaction(function () use ($page, $expectedBaseRevisionId, $expectedStructureEpoch, $userId) {
            $locked = GeneratedPage::lockForUpdate()->find($page->id);
            if (! $locked) {
                throw new PageStateException("Page {$page->id} not found inside transaction.");
            }

            $currentBase = $locked->draft_revision_id ?? $locked->published_revision_id;
            if ($currentBase !== $expectedBaseRevisionId) {
                throw new StaleRevisionException(
                    "Expected base revision {$expectedBaseRevisionId} but page is now at ".var_export($currentBase, true).'.'
                );
            }

            if ((int) $locked->structure_epoch !== $expectedStructureEpoch) {
                throw new StaleStructureException(
                    "Expected structure epoch {$expectedStructureEpoch} but page is now at ".var_export($locked->structure_epoch, true).'.'
                );
            }

            $draft = $locked->draft_revision_id
                ? PageRevision::query()->where('page_id', $locked->id)->find($locked->draft_revision_id)
                : null;

            $parentId = $draft?->parent_revision_id;
            $parent = $parentId !== null
                ? PageRevision::query()->where('page_id', $locked->id)->find($parentId)
                : null;

            if ($parent === null) {
                throw ValidationException::withMessages([
                    'revision_id' => 'The current draft revision has no recorded parent revision; there is nothing to undo to.',
                ]);
            }

            $content = $parent->content_data;
            $revision = $this->createDraftRevision($locked, $content, aiGenerated: false, userId: $userId);

            // An undo that changes the section list invalidates stored indexes exactly like a
            // structure write, so it bumps the epoch on the same terms as mutateSections().
            if ($this->sectionListsChangeIndexes($draft->content_data['sections'] ?? [], $content['sections'] ?? [])) {
                $locked->increment('structure_epoch'); // the locked, persisted row — never the caller's instance
            }

            $page->refresh();

            return $revision;
        });
    }

    /**
     * Decide whether a revert moves the section list in a way that invalidates clients' stored
     * section indexes. Sections carry no stable per-section id, so this is decided structurally,
     * and it bumps the epoch when ANY of the three rules below holds:
     *
     *   1. the two lists have different lengths;
     *   2. their ordered type sequences differ;
     *   3. the two lists are a permutation of each other but are NOT in identical order.
     *
     * Rule 3 closes the same-type reorder gap: two `text` (or two `feature`) sections swapped keep
     * length and the type sequence equal, yet the stored indexes moved just as the distinct-type
     * case does. Rules 2 and 3 together keep the field-only edit from bumping — equal length, equal
     * types, and a content-only difference is neither a type change nor a same-type reorder (the
     * multiset changed, so it is not a permutation), so the epoch stays put.
     */
    private function sectionListsChangeIndexes(mixed $before, mixed $after): bool
    {
        if (! is_array($before) || ! is_array($after) || ! array_is_list($before) || ! array_is_list($after)) {
            // Not comparable as two section lists — treat an unprovable structure as changed.
            return true;
        }

        // Rule 1 — the two lists have different lengths.
        if (count($before) !== count($after)) {
            return true;
        }

        $typesBefore = array_map(fn (array $s): string => $s['type'] ?? '', $before);
        $typesAfter  = array_map(fn (array $s): string => $s['type'] ?? '', $after);

        // Rule 2 — their ordered type sequences differ.
        if ($typesBefore !== $typesAfter) {
            return true;
        }

        // Rule 3 — a permutation (the same multiset of sections) but not in identical order.
        // A content-only change is NOT a permutation (the multiset differs), so it falls through.
        //
        // The multiset comparison canonicalises each section to a string first. sort() on the raw
        // section arrays cannot be used: PHP's array comparison is not a total ordering when two
        // associative arrays have different key sets (comparing them yields "uncomparable"), so two
        // lists that ARE exact permutations can sort into different orders and the reorder is missed.
        // Two same-type sections with differing optional fields — one carrying `title`, the other
        // `body` — are an ordinary shape that triggers exactly that.
        if ($before !== $after) {
            $canonical = static function (array $section): string {
                ksort($section);

                return json_encode($section, JSON_THROW_ON_ERROR);
            };

            $canonicalBefore = array_map($canonical, $before);
            $canonicalAfter  = array_map($canonical, $after);
            sort($canonicalBefore);
            sort($canonicalAfter);

            if ($canonicalBefore === $canonicalAfter) {
                return true;
            }
        }

        return false;
    }

    /**
     * Insert a new revision row and advance draft_revision_id.
     * Mirrors content into legacy generated_pages.content_data so existing readers keep working.
     *
     * The parent is the pointer this service already uses everywhere for "the current content" —
     * draft_revision_id ?? published_revision_id — NOT draft_revision_id alone: publish clears the
     * draft pointer, so the first edit after a publish would otherwise record a null parent and be
     * permanently un-undoable. Callers must hold the generated_pages row lock (editField,
     * editRepeatableEntries, mutateSections and revertRevision do; replaceContent takes it here)
     * so the pointer this reads is the locked row's, not a stale in-memory copy.
     */
    protected function createDraftRevision(
        GeneratedPage $page,
        array $contentData,
        bool $aiGenerated = false,
        ?string $aiModelVersion = null,
        ?string $aiPromptUsed = null,
        ?int $userId = null,
    ): PageRevision {
        $revision = PageRevision::create([
            'page_id' => $page->id,
            'parent_revision_id' => $page->draft_revision_id ?? $page->published_revision_id,
            'content_data' => $contentData,
            'ai_generated' => $aiGenerated,
            'ai_model_version' => $aiModelVersion,
            'ai_prompt_used' => $aiPromptUsed,
            'created_by_user_id' => $userId,
            'created_at' => now(),
        ]);

        $page->update([
            'draft_revision_id' => $revision->id,
            // legacy mirror — kept until the renderer cutover so PreviewRenderer + downstream readers keep working
            'content_data' => $contentData,
        ]);

        return $revision;
    }
}

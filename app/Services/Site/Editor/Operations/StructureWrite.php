<?php

namespace App\Services\Site\Editor\Operations;

use App\Exceptions\Site\StaleRevisionException;
use App\Exceptions\Site\StaleStructureException;
use App\Models\GeneratedPage;
use App\Models\Site\PageRevision;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorState;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PageRenderer;
use App\Services\Site\PageService;
use Illuminate\Validation\ValidationException;

final class StructureWrite
{
    public function __construct(
        private readonly PageService $pages,
        private readonly PageRenderer $renderer,
        private readonly EditorStateFactory $states,
    ) {}

    public function page(EditorContext $ctx, array $input): ?GeneratedPage
    {
        if (! isset($input['page_id'])) {
            return null;
        }

        return GeneratedPage::query()
            ->where('site_id', $ctx->site->id)
            ->whereNull('archived_at') // archived pages are not editable — every sibling op and the legacy route agree
            ->find((int) $input['page_id']);
    }

    public function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(0|-?[1-9][0-9]*)$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    public function isCatalogued(string $type): bool
    {
        return array_key_exists($type, config('section_catalog', []));
    }

    /**
     * @return array{page: GeneratedPage, base: int, epoch: int, state: EditorState}|OperationResult
     */
    public function prepare(EditorContext $ctx, array $input): array|OperationResult
    {
        $page = $this->page($ctx, $input);
        $state = $this->states->for($ctx->site, $page);

        if ($page === null) {
            return OperationResult::fail('not_found', 'Page not found.', $state);
        }

        $base = $this->intOrNull($input['revision_base'] ?? null);
        if ($base === null) {
            return OperationResult::fail('validation', 'revision_base is required.', $state, [
                'fields' => ['revision_base' => ['required integer']],
            ]);
        }

        $epoch = $this->intOrNull($input['structure_epoch'] ?? null);
        if ($epoch === null) {
            return OperationResult::fail('validation', 'structure_epoch is required.', $state, [
                'fields' => ['structure_epoch' => ['required integer']],
            ]);
        }

        $currentBase = $page->draft_revision_id ?? $page->published_revision_id;
        if ($currentBase === null) {
            return OperationResult::fail('validation', 'Structure operations need a base revision.', $state);
        }

        return [
            'page' => $page,
            'base' => $base,
            'epoch' => $epoch,
            'state' => $state,
        ];
    }

    /**
     * @param  \Closure(list<array<string, mixed>>): list<array<string, mixed>>  $transform
     * @param  array<string, mixed>  $input
     */
    public function mutate(
        EditorContext $ctx,
        GeneratedPage $page,
        int $base,
        int $epoch,
        \Closure $transform,
        array $input,
    ): OperationResult {
        $state = $this->states->for($ctx->site, $page);

        try {
            $revision = $this->pages->mutateSections($page, $base, $epoch, $transform, $ctx->actor->id);
        } catch (StaleStructureException $exception) {
            return $this->stale($page, $state, $exception);
        } catch (StaleRevisionException $exception) {
            return $this->stale($page, $state, $exception);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? $exception->getMessage();

            return OperationResult::fail('validation', (string) $message, $state, [
                'fields' => $exception->errors(),
            ]);
        }

        $page->refresh();

        $html = $this->renderer->render(
            $ctx->site,
            $page->id,
            mode: 'admin-edit',
            // Signed nav is a UI-channel affordance. Each nav href here is an 8-hour
            // `editor-preview` temporarySignedRoute, and that signature is the only authorization the
            // preview route checks — so on an agent channel the result html would carry standing
            // credentials for the whole draft site out to a third-party model. Only the human's own iframe
            // navigates with them; the agent fronts use the html for a section/form swap, never for nav.
            signedNav: $ctx->channel === ActorChannel::Ui,
            parentOrigin: is_string($input['parent_origin'] ?? null) ? \App\Support\EditorParentOrigin::resolve($input['parent_origin']) : null, // allowlisted surface only, like every other render call site
            formPanel: true,
        );

        return OperationResult::ok([
            'draft_revision_id' => $revision->id,
            'structure_epoch' => (int) $page->structure_epoch,
            'html' => $html,
        ], $this->states->for($ctx->site, $page));
    }

    /**
     * The section list the next structural write transforms — the draft revision's
     * content when a draft exists, else the published revision's. Any list change
     * bumps structure_epoch, so a read here plus the epoch check inside
     * mutateSections() is the same guarantee the in-closure offset use has.
     *
     * @return list<array<string, mixed>>
     */
    public function currentSections(GeneratedPage $page): array
    {
        $revisionId = $page->draft_revision_id ?? $page->published_revision_id;
        if ($revisionId === null) {
            return [];
        }

        $content = PageRevision::query()->find($revisionId)?->content_data ?? [];
        $sections = $content['sections'] ?? null;

        return is_array($sections) && array_is_list($sections) ? $sections : [];
    }

    /**
     * Resolve a section address to its offset in $sections (plan § D6).
     *
     * - `section_id` present: the offset of the section whose `id` matches. When the
     *   positional key is also given the two must AGREE — a disagreement is exactly
     *   the retargeting stable ids exist to prevent, so it is never silently resolved.
     * - `section_id` absent: the positional value under $key, unchanged — the ruling's
     *   transition clause; every existing caller keeps behaving exactly as before.
     *
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, mixed>  $input
     */
    public function resolveSectionAddress(
        array $sections,
        array $input,
        string $key = 'stored_index',
        ?EditorState $state = null,
    ): int|OperationResult {
        $state ??= new EditorState(siteId: 0, pageId: null, draftRevisionId: null, compositionRevision: 0, pendingPublish: false);

        $sectionId = $input['section_id'] ?? null;
        $positionalProvided = array_key_exists($key, $input) && $input[$key] !== null;
        $positional = $this->intOrNull($input[$key] ?? null);

        if ($positionalProvided && $positional === null) {
            return OperationResult::fail('validation', "{$key} must be a non-negative integer.", $state, [
                'fields' => [$key => ['required integer']],
            ]);
        }

        if (is_string($sectionId) && $sectionId !== '') {
            $offset = $this->offsetForSectionId($sections, $sectionId);

            if ($offset === null) {
                return OperationResult::fail('not_found', 'section_id does not name a section on this page.', $state, [
                    'fields' => ['section_id' => ['unknown section id']],
                ]);
            }

            if ($positional !== null && $positional !== $offset) {
                return OperationResult::fail('validation', "section_id and {$key} name different sections; provide one.", $state, [
                    'fields' => [
                        'section_id' => ["disagrees with {$key}"],
                        $key => ['disagrees with section_id'],
                    ],
                ]);
            }

            return $offset;
        }

        if ($positional === null || $positional < 0) {
            return OperationResult::fail('validation', "{$key} must be a non-negative integer.", $state, [
                'fields' => [$key => ['required integer when section_id is absent']],
            ]);
        }

        return $positional;
    }

    /**
     * Offset of the section carrying $sectionId, or null when no stored section matches.
     *
     * @param  list<array<string, mixed>>  $sections
     */
    public function offsetForSectionId(array $sections, string $sectionId): ?int
    {
        foreach ($sections as $offset => $section) {
            if (is_array($section) && ($section['id'] ?? null) === $sectionId) {
                return $offset;
            }
        }

        return null;
    }

    public function unknownType(): never
    {
        throw ValidationException::withMessages([
            'type' => 'Section type is not addable / not editable via structure ops.',
        ]);
    }

    public function failUnknownType(EditorState $state): OperationResult
    {
        return OperationResult::fail(
            'validation',
            'Section type is not addable / not editable via structure ops.',
            $state,
            ['fields' => ['type' => ['not addable / not editable via structure ops']]],
        );
    }

    private function stale(GeneratedPage $page, EditorState $state, StaleRevisionException $exception): OperationResult
    {
        $fresh = $page->fresh() ?? $page;

        return OperationResult::fail('stale_revision', $exception->getMessage(), $state, [
            'current_revision_id' => $fresh->draft_revision_id ?? $fresh->published_revision_id,
        ]);
    }
}

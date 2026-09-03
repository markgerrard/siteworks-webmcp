<?php

namespace App\Services\Site\Editor\Operations;

use App\Exceptions\Site\StaleRevisionException;
use App\Exceptions\Site\StaleStructureException;
use App\Models\GeneratedPage;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorState;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PageRenderer;
use App\Services\Site\PageService;
use App\Support\EditorParentOrigin;
use Illuminate\Validation\ValidationException;

final class UndoRevisionOperation extends BaseOperation
{
    public function __construct(
        private readonly PageService $pages,
        private readonly PageRenderer $renderer,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'undo_revision';
    }

    public function readOnly(): bool
    {
        return false;
    }

    /**
     * Declared explicitly — never inherited from BaseOperation's false default, which would silently
     * declare this safe at the approval boundary. Undo destroys recoverable state: the undone draft's
     * content is only reachable again by undoing the undo. No delegation, so nothing implicit to rely on.
     */
    public function requiresApproval(): bool
    {
        return true;
    }

    /**
     * Spec § 5.1: undo destroys recoverable draft state. MCP annotations are per-tool,
     * so the whole operation is marked destructive rather than inferred from a front-end list.
     */
    public function destructive(): bool
    {
        return true;
    }

    public function sideEffects(): string
    {
        return 'Writes a new draft revision restoring the current draft\'s recorded parent; never moves published_revision_id.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['page_id', 'revision_id', 'revision_base', 'structure_epoch'],
            'properties' => [
                'page_id' => ['type' => 'integer'],
                'revision_id' => [
                    'type' => 'integer',
                    'description' => 'The draft revision to undo; must be the current draft_revision_id.',
                ],
                'revision_base' => ['type' => 'integer'],
                'structure_epoch' => ['type' => 'integer'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);

        $pageId = self::intOrNull($input['page_id'] ?? null);
        $revisionId = self::intOrNull($input['revision_id'] ?? null);
        $base = self::intOrNull($input['revision_base'] ?? null);
        $epoch = self::intOrNull($input['structure_epoch'] ?? null);

        if ($pageId === null || $revisionId === null || $base === null || $epoch === null) {
            return OperationResult::fail('validation', 'page_id, revision_id, revision_base and structure_epoch are required.', $state, [
                'fields' => [
                    'page_id' => $pageId === null ? ['required integer'] : [],
                    'revision_id' => $revisionId === null ? ['required integer'] : [],
                    'revision_base' => $base === null ? ['required integer'] : [],
                    'structure_epoch' => $epoch === null ? ['required integer'] : [],
                ],
            ]);
        }

        // Both pointers must name the revision being undone: revision_base is the page currency the
        // receipt's changed[] diff reads from, revision_id names the undo target. Divergence is a
        // client bug, not staleness — nothing has moved yet.
        if ($revisionId !== $base) {
            return OperationResult::fail('validation', 'revision_id and revision_base must name the same current draft revision.', $state, [
                'fields' => [
                    'revision_id' => ['must equal revision_base for undo'],
                    'revision_base' => ['must equal revision_id for undo'],
                ],
            ]);
        }

        $page = GeneratedPage::query()
            ->where('site_id', $ctx->site->id)
            ->whereNull('archived_at') // archived pages are not editable — every sibling op and the legacy route agree
            ->find($pageId);

        if (! $page) {
            return OperationResult::fail('not_found', 'Page not found.', $state);
        }

        $state = $this->states->for($ctx->site, $page);

        if ($page->draft_revision_id !== $revisionId) {
            return OperationResult::fail('stale_revision', 'revision_id is not the current draft revision.', $state, [
                'current_revision_id' => $page->draft_revision_id ?? $page->published_revision_id,
            ]);
        }

        try {
            $revision = $this->pages->revertRevision($page, $revisionId, $epoch, $ctx->actor->id);
        } catch (StaleStructureException $exception) {
            return $this->stale($page, $state, $exception->getMessage());
        } catch (StaleRevisionException $exception) {
            return $this->stale($page, $state, $exception->getMessage());
        } catch (ValidationException $exception) {
            // revertRevision() only throws ValidationException for the no-resolvable-parent refusal.
            $message = collect($exception->errors())->flatten()->first() ?? $exception->getMessage();

            return OperationResult::fail('validation', (string) $message, $state, [
                'no_recorded_parent' => true,
                'fields' => $exception->errors(),
            ]);
        }

        $page->refresh();

        $html = $this->renderer->render(
            $ctx->site,
            $page->id,
            mode: 'admin-edit',
            // Signed nav is a UI-channel affordance — on an agent channel the html would carry
            // standing credentials for the whole draft site out to a third-party model.
            signedNav: $ctx->channel === ActorChannel::Ui,
            parentOrigin: is_string($input['parent_origin'] ?? null) ? EditorParentOrigin::resolve($input['parent_origin']) : null,
            formPanel: true,
            // The draft selection is only read when useDraftAssets is true — mirrors EditFieldOperation.
            useDraftAssets: true,
        );

        return OperationResult::ok([
            'draft_revision_id' => $revision->id,
            'structure_epoch' => (int) $page->structure_epoch,
            'html' => $html,
        ], $this->states->for($ctx->site, $page));
    }

    private function stale(GeneratedPage $page, EditorState $state, string $message): OperationResult
    {
        $fresh = $page->fresh() ?? $page;

        return OperationResult::fail('stale_revision', $message, $state, [
            'current_revision_id' => $fresh->draft_revision_id ?? $fresh->published_revision_id,
        ]);
    }

    /**
     * Accepts ints and canonical integer strings; rejects bools/floats/other.
     */
    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(0|-?[1-9][0-9]*)$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}

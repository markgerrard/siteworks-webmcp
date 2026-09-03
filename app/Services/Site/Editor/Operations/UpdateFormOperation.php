<?php

namespace App\Services\Site\Editor\Operations;

use App\Exceptions\Site\StaleRevisionException;
use App\Models\GeneratedPage;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\FormDefinitionWriter;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PageRenderer;
use Illuminate\Validation\ValidationException;

final class UpdateFormOperation extends BaseOperation
{
    public function __construct(
        private readonly FormDefinitionWriter $definitions,
        private readonly PageRenderer $renderer,
        private readonly EditorStateFactory $states,
    ) {}

    public function name(): string
    {
        return 'update_form';
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function wrapInAdminChange(): bool
    {
        return false;
    }

    public function sideEffects(): string
    {
        return 'Replaces a form section definition on the draft.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['page_id', 'stored_index', 'fields', 'revision_base'],
            'properties' => [
                'page_id' => ['type' => 'integer'],
                'stored_index' => ['type' => 'integer', 'minimum' => 0],
                'title' => ['type' => 'string', 'maxLength' => 60],
                'submit_label' => ['type' => 'string', 'maxLength' => 32],
                'fields' => ['type' => 'array'],
                'revision_base' => ['type' => 'integer'],
                'parent_origin' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(EditorContext $ctx, array $input): OperationResult
    {
        $state = $this->states->for($ctx->site, null);

        $base = self::intOrNull($input['revision_base'] ?? null);
        if ($base === null) {
            return OperationResult::fail('validation', 'revision_base is required.', $state, [
                'fields' => ['revision_base' => ['required integer']],
            ]);
        }

        $pageId = self::intOrNull($input['page_id'] ?? null);
        $storedIndex = self::intOrNull($input['stored_index'] ?? null);

        if ($pageId === null || $storedIndex === null) {
            return OperationResult::fail('validation', 'page_id and stored_index are required.', $state, [
                'fields' => [
                    'page_id' => $pageId === null ? ['required integer'] : [],
                    'stored_index' => $storedIndex === null ? ['required integer'] : [],
                ],
            ]);
        }

        $page = GeneratedPage::query()
            ->where('site_id', $ctx->site->id)
            ->whereNull('archived_at')
            ->find($pageId);

        if (! $page) {
            return OperationResult::fail('not_found', 'Page not found.', $state);
        }

        $state = $this->states->for($ctx->site, $page);

        try {
            $revision = $this->definitions->write(
                $page,
                $storedIndex,
                $input,
                $base,
                $ctx->actor->id,
                draftOnly: true, // spec § 4: operations never invalidate the public cache or mutate Preview.snapshot
            );
        } catch (StaleRevisionException) {
            $fresh = $page->fresh();

            return OperationResult::fail('stale_revision', 'Page revision base is stale.', $state, [
                'current_revision_id' => $fresh->draft_revision_id ?? $fresh->published_revision_id,
            ]);
        } catch (ValidationException $exception) {
            return OperationResult::fail('validation', 'Form definition failed validation.', $state, [
                'fields' => $exception->errors(),
            ]);
        }

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
            parentOrigin: is_string($input['parent_origin'] ?? null) ? \App\Support\EditorParentOrigin::resolve($input['parent_origin']) : null, // allowlisted surface only
            formPanel: true,
            // PageRenderer defaults this to false. The coordinator prefers this html over the controller's
            // for its section swap, so without it an edit made after select_logo / restore_image_version
            // repainted the PUBLISHED logo or hero into the iframe until a full reload — the draft
            // selection is only read when useDraftAssets is true. EditorPreviewController already passes it.
            useDraftAssets: true,
        );

        return OperationResult::ok([
            'stored_index' => $storedIndex, // Front 2 targets the section swap with this
            'revision_id' => $revision->id,
            'html' => $html,
        ], $this->states->for($ctx->site, $page->fresh()));
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

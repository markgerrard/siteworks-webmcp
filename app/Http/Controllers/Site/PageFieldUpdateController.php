<?php

namespace App\Http\Controllers\Site;

use App\Exceptions\Site\StaleRevisionException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Editor\EditorOperationController;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\SiteMedia;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\AgentToolsGate;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperationRecorder;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\PageRenderer;
use App\Services\Site\PageService;
use App\Services\Site\SectionSchema;
use App\Support\EditorParentOrigin;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PageFieldUpdateController extends Controller
{
    public function __construct(
        protected PageService $pages,
        protected SectionSchema $schema,
        protected PageRenderer $renderer,
        protected EditorOperations $operations,
        protected AgentToolsGate $agentToolsGate,
        protected EditorOperationRecorder $recorder,
    ) {}

    /**
     * Front 2 reaches `edit_field` through THIS legacy route (`tools.js` maps it to `fieldUpdateUrl`),
     * so hardcoding `Ui` here laundered every agent write into a human one: `AgentToolsGate` short-circuits
     * to `true` for `Ui` but enforces `editor.agent_tools.roles` for `Webmcp`, so the role allowlist —
     * the designed control over who may drive agent tools — was unreachable on the primary agent write
     * path, and `editor_operation_log` recorded `actor_channel: ui` for it. Mirrors
     * EditorOperationController::channelFor: a declared channel can only make the gate STRICTER.
     */
    private static function channelFor(Request $request): ActorChannel
    {
        return trim((string) $request->header('X-Editor-Channel')) === 'webmcp'
            ? ActorChannel::Webmcp
            : ActorChannel::Ui;
    }

    public function __invoke(Request $request, int $site, int $page)
    {
        $siteModel = Site::findOrFail($site);
        $this->authorize('update', $siteModel);

        // Exposure refusal (spec § 8, ruling R1) BEFORE any validation / optimistic-concurrency
        // preflight below: an agent caller must not learn that edit_field exists but is unexposed for
        // this site — a 422 (bad body) or 409 (stale base) returned to such a caller is exactly that
        // oracle. Byte-identical to the unknown-name refusal (same code, message, audit row). This
        // fixes ORDERING; run() re-checks as the real boundary. A stranger is refused by the SitePolicy
        // gate above before this, so only a permitted caller reaches it.
        $channel = self::channelFor($request);
        if ($channel !== ActorChannel::Ui) {
            $refused = $this->operations->refuseIfUnexposed(
                new EditorContext($request->user(), $siteModel, $channel),
                'edit_field',
                $request->all(),
            );
            if ($refused !== null) {
                return response()->json($refused->toArray(), EditorOperationController::statusFor($refused));
            }
        }

        $pageModel = GeneratedPage::where('site_id', $site)
            ->whereNull('archived_at')
            ->findOrFail($page);

        $validated = $request->validate([
            'section_index' => 'required|integer|min:0',
            'field_path' => 'required|string|max:200',
            'value' => $request->input('field_path') === 'variant'
                && $request->exists('value')
                && $request->input('value') === null
                    ? 'nullable'
                    : 'required',
        ]);

        // Optimistic concurrency — pre-check is a fast-path 409 before hitting the DB.
        // The authoritative check is inside PageService::editField's transaction.
        $expectedBase = $request->header('X-Page-Revision-Base');
        $expectedBaseId = null;
        if ($expectedBase !== null && $expectedBase !== '') {
            $expectedBaseId = (int) $expectedBase;
            $currentBase = $pageModel->draft_revision_id ?? $pageModel->published_revision_id;
            if ($expectedBaseId !== (int) $currentBase) {
                return response()->json([
                    'message' => 'Page revision base is stale.',
                    'current_revision_id' => $currentBase,
                ], 409);
            }
        }

        $base = $this->currentEditableContent($pageModel);
        $section = $base['sections'][$validated['section_index']] ?? null;
        if (! $section) {
            abort(422, 'Section index out of range.');
        }
        $sectionType = $section['type'] ?? '';

        $errors = $this->schema->validateField($sectionType, $validated['field_path'], $validated['value']);
        if (! empty($errors)) {
            throw ValidationException::withMessages(['value' => $errors]);
        }

        $fieldRules = $this->schema->resolveField($sectionType, $validated['field_path']);
        if (($fieldRules['type'] ?? null) === 'image' && str_ends_with($validated['field_path'], '_id')) {
            $mediaId = $validated['value'];
            if (! is_int($mediaId)
                || ! SiteMedia::query()->where('site_id', $siteModel->id)->whereKey($mediaId)->exists()) {
                throw ValidationException::withMessages([
                    'value' => 'The selected media must belong to this site.',
                ]);
            }
        }

        $result = null;
        $channel = self::channelFor($request);

        /*
         * A declared agent ALWAYS goes through the operations layer, whatever the flags say. Deciding this
         * on the Ui gate was safe only while one flag governed both channels; with independent flags, an
         * agent write arriving while the HUMAN layer is off would otherwise fall into the legacy writer —
         * which runs no gate and writes no audit row, silently reopening the ungated-write hole. If the
         * agent's own gate denies it, the layer returns `forbidden` and we 403 below; it never degrades to
         * an ungated write. Only a human may take the legacy path.
         */
        $delegate = $channel !== ActorChannel::Ui
            || ($expectedBaseId !== null && $this->agentToolsGate->enabledFor($request->user(), $channel));

        if (! $delegate) {
            $fullPath = "sections.{$validated['section_index']}.{$validated['field_path']}";
            try {
                $this->pages->editField(
                    $pageModel,
                    $fullPath,
                    $validated['value'],
                    userId: $request->user()->id,
                    expectedBaseRevisionId: $expectedBaseId,
                );
            } catch (StaleRevisionException) {
                $currentBase = $pageModel->fresh()->draft_revision_id ?? $pageModel->fresh()->published_revision_id;

                $this->recorder->record($siteModel->id, $pageModel->id, $request->user()->id, $channel, 'edit_field', 'stale_revision');

                return response()->json([
                    'message' => 'Page revision base is stale.',
                    'current_revision_id' => $currentBase,
                ], 409);
            }

            // The layer logs its own calls; this branch bypasses the layer (human, operations flag off),
            // so without this a text edit would be the one editor action missing from the log.
            $this->recorder->record($siteModel->id, $pageModel->id, $request->user()->id, $channel, 'edit_field');
        } else {
            $result = $this->operations->run(
                new EditorContext($request->user(), $siteModel, $channel),
                'edit_field',
                [
                    'page_id' => $pageModel->id,
                    'stored_index' => $validated['section_index'],
                    'field_path' => $validated['field_path'],
                    'value' => $validated['value'],
                    'revision_base' => $expectedBaseId,
                    'parent_origin' => EditorParentOrigin::fromRequest($request),
                ],
            );

            if (! $result->ok && $result->error['code'] === 'stale_revision') {
                return response()->json([
                    'message' => 'Page revision base is stale.',
                    'current_revision_id' => $result->error['current_revision_id'] ?? null,
                ], 409);
            }

            // A declared agent whose role is outside editor.agent_tools.roles is refused by the gate in
            // Layer 0. Without this it fell through to the ValidationException below and surfaced as a 422,
            // which reads as "bad input" rather than "not allowed on this channel".
            if (! $result->ok && $result->error['code'] === 'forbidden') {
                return response()->json($result->toArray(), 403);
            }

            if (! $result->ok) {
                throw ValidationException::withMessages($result->error['fields'] ?? [
                    'value' => [$result->error['message']],
                ]);
            }
        }

        // This render exists for PublicEditFieldController, which wraps this
        // controller for the dormant public-host editor. No client consumes the
        // html key today, and two test files pin it — two agents have tried to
        // delete it and been stopped by the suite.
        // See docs/architecture/public-host-editor-dormant.md before removing.
        //
        // Re-render page in edit mode and return. signedNav + parentOrigin
        // match EditorPreviewController: without them the swapped nav 404s
        // inside the cross-origin iframe and the destination page's bridge
        // falls back to agent_domain.
        $html = $this->renderer->render(
            $siteModel,
            $pageModel->id,
            mode: 'admin-edit',
            signedNav: true,
            parentOrigin: EditorParentOrigin::fromRequest($request),
            formPanel: true,
            useDraftAssets: true,
        );

        $body = [
            'html' => $html,
            'page_id' => $pageModel->id,
            'draft_revision_id' => $pageModel->fresh()->draft_revision_id,
        ];

        // Front 2 maps edit_field to this route, and the WebMCP tool contract promises the operations
        // envelope {ok, state, data}. The legacy body carried none of it, so `result.ok` was undefined in
        // tools.js — every successful agent write was logged ok:false and no agent could tell success from
        // failure. Added ONLY for a declared agent so the human body stays byte-identical (two test files
        // pin it; see the render comment above).
        if ($result !== null && $channel === ActorChannel::Webmcp) {
            $body += $result->toArray();
        }

        return response()->json($body);
    }

    protected function currentEditableContent(GeneratedPage $page): array
    {
        $rid = $page->draft_revision_id ?? $page->published_revision_id;

        return $rid ? (PageRevision::find($rid)?->content_data ?? []) : [];
    }

}

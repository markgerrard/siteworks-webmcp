<?php

namespace App\Http\Controllers\Site;

use App\Exceptions\Site\StaleRevisionException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Editor\EditorOperationController;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperationRecorder;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\FormDefinitionWriter;
use App\Services\Site\PageRenderer;
use App\Support\EditorParentOrigin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FormUpdateController extends Controller
{
    public function __construct(
        private FormDefinitionWriter $writer,
        private PageRenderer $renderer,
        private EditorOperations $operations,
        private EditorOperationRecorder $recorder,
    ) {}

    /**
     * Front 2 maps `update_form` to this legacy route, so without a channel split an agent's form write
     * faced no AgentToolsGate, produced no editor_operation_log row, and ran with `draftOnly: false` —
     * invalidating the public cache and mirroring Preview.snapshot, which spec § 4 forbids for every other
     * agent write. The two paths cannot be merged: the human route's cache invalidation + snapshot mirror
     * is exactly what `UpdateFormOperation` hardcodes OFF (`draftOnly: true`), so routing humans through the
     * operation would take their live preview away (the T18 ruling). Splitting on the declared channel gives
     * both: humans keep the legacy write byte-for-byte, agents get the gate, the audit row and draft-only.
     */
    private static function channelFor(Request $request): ActorChannel
    {
        return trim((string) $request->header('X-Editor-Channel')) === 'webmcp'
            ? ActorChannel::Webmcp
            : ActorChannel::Ui;
    }

    public function __invoke(Request $request, int $site, int $page, int $section): JsonResponse
    {
        $siteModel = Site::findOrFail($site);
        $this->authorize('update', $siteModel);

        // Exposure refusal (spec § 8, ruling R1) BEFORE any validation / base-header preflight below:
        // an agent caller must not learn that update_form exists but is unexposed for this site — a
        // 422 (bad body) or 409 (missing base) returned to such a caller is exactly that oracle.
        // Byte-identical to the unknown-name refusal (same code, message, audit row). This fixes
        // ORDERING; run() re-checks as the real boundary. Only a permitted caller reaches it, so a
        // stranger is refused by the SitePolicy gate above with its own literal.
        if (self::channelFor($request) !== ActorChannel::Ui) {
            $refused = $this->operations->refuseIfUnexposed(
                new EditorContext($request->user(), $siteModel, self::channelFor($request)),
                'update_form',
                $request->all(),
            );
            if ($refused !== null) {
                return response()->json($refused->toArray(), EditorOperationController::statusFor($refused));
            }
        }

        $pageModel = GeneratedPage::where('site_id', $site)
            ->whereNull('archived_at')
            ->findOrFail($page);

        $content = $this->currentEditableContent($pageModel);
        $sectionData = $content['sections'][$section] ?? null;

        if (! is_array($sectionData)) {
            abort(422, 'Section index out of range.');
        }

        $sectionType = (string) ($sectionData['type'] ?? '');

        if (! in_array($sectionType, ['contact_form', 'lead_form'], true)) {
            abort(422, 'Section is not a form.');
        }

        // Validate BEFORE the base-header check — legacy order (invalid body + missing header = 422, not 409).
        $prepared = $this->writer->validate($request->all(), $sectionType);

        $expectedBase = $request->header('X-Page-Revision-Base');
        if ($expectedBase === null || $expectedBase === '') {
            return $this->staleRevisionResponse($pageModel);
        }
        $expectedBaseId = (int) $expectedBase;

        if (self::channelFor($request) === ActorChannel::Webmcp) {
            return $this->agentWrite($request, $siteModel, $pageModel, $section, $expectedBaseId);
        }

        try {
            $revision = $this->writer->writeValidated(
                $pageModel,
                $section,
                $prepared,
                $expectedBaseId,
                $request->user()->id,
                draftOnly: false, // the human route keeps its cache invalidation + snapshot mirror (legacy behaviour)
            );
        } catch (StaleRevisionException) {
            $this->recorder->record($siteModel->id, $pageModel->id, $request->user()->id, ActorChannel::Ui, 'update_form', 'stale_revision');

            return $this->staleRevisionResponse($pageModel);
        }

        // The human path deliberately does not go through the layer (it keeps draftOnly:false so the live
        // preview refreshes), so it has to record itself.
        $this->recorder->record($siteModel->id, $pageModel->id, $request->user()->id, ActorChannel::Ui, 'update_form');

        $html = $this->renderer->render(
            $siteModel,
            $pageModel->id,
            mode: 'admin-edit',
            signedNav: true,
            parentOrigin: EditorParentOrigin::fromRequest($request),
            formPanel: true,
            useDraftAssets: true,
        );

        return response()->json([
            'status' => 'ok',
            'revision_id' => $revision->id,
            'html' => $html,
        ]);
    }

    /**
     * A declared agent's form write goes through Layer 0 — SitePolicy, AgentToolsGate (which enforces
     * editor.agent_tools.roles on this channel, unlike Ui), the audit row naming the human whose session
     * it is plus actor_channel=webmcp, and draft-only semantics. The rendered html is still returned so the
     * in-editor preview swaps; that is a client-side render, not the public cache the operation refuses to
     * touch. Legacy keys are kept alongside the envelope so the existing coordinator path is unaffected.
     */
    private function agentWrite(
        Request $request,
        Site $siteModel,
        GeneratedPage $pageModel,
        int $section,
        int $expectedBaseId,
    ): JsonResponse {
        $input = [
            'page_id' => $pageModel->id,
            'stored_index' => $section,
            'fields' => $request->input('fields', []),
            'revision_base' => $expectedBaseId,
            'parent_origin' => EditorParentOrigin::fromRequest($request),
        ];

        foreach (['title', 'submit_label'] as $optional) {
            if ($request->exists($optional)) {
                $input[$optional] = $request->input($optional);
            }
        }

        $result = $this->operations->run(
            new EditorContext($request->user(), $siteModel, ActorChannel::Webmcp),
            'update_form',
            $input,
        );

        if (! $result->ok) {
            return match ($result->error['code']) {
                'stale_revision' => $this->staleRevisionResponse($pageModel),
                'forbidden' => response()->json($result->toArray(), 403),
                'not_found' => response()->json($result->toArray(), 404),
                default => response()->json($result->toArray(), 422),
            };
        }

        $html = $this->renderer->render(
            $siteModel,
            $pageModel->id,
            mode: 'admin-edit',
            signedNav: true,
            parentOrigin: EditorParentOrigin::fromRequest($request),
            formPanel: true,
            useDraftAssets: true,
        );

        return response()->json([
            'status' => 'ok',
            'revision_id' => $result->state->draftRevisionId,
            'html' => $html,
        ] + $result->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    protected function currentEditableContent(GeneratedPage $page): array
    {
        $rid = $page->draft_revision_id ?? $page->published_revision_id;

        if ($rid) {
            return PageRevision::find($rid)?->content_data ?? $page->content_data ?? [];
        }

        return $page->content_data ?? [];
    }

    protected function staleRevisionResponse(GeneratedPage $page): JsonResponse
    {
        $currentPage = $page->fresh();

        return response()->json([
            'message' => 'Page revision base is stale.',
            'current_revision_id' => $currentPage->draft_revision_id ?? $currentPage->published_revision_id,
        ], 409);
    }

}

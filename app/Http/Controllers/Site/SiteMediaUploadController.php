<?php

namespace App\Http\Controllers\Site;

use App\Exceptions\UnsupportedImageException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Editor\EditorOperationController;
use App\Http\Requests\Site\Editor\UploadImageRequest;
use App\Models\Site;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\AgentToolsGate;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperationRecorder;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\ResultReceipt;
use App\Services\Site\Editor\SiteMediaIngestService;
use App\Services\Site\Editor\ToolExposure;
use App\Support\EditorParentOrigin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SiteMediaUploadController extends Controller
{
    private const NEUTRAL_STATE = ['site_id' => null, 'page_id' => null, 'draft_revision_id' => null, 'composition_revision' => 0, 'pending_publish' => false, 'structure_epoch' => null];

    public function __construct(
        private readonly SiteMediaIngestService $ingestService,
        private readonly EditorOperations $operations,
        private readonly EditorOperationRecorder $recorder,
        private readonly AgentToolsGate $gate,
        private readonly OperationRegistry $registry,
        private readonly ToolExposure $exposure,
    ) {}

    /**
     * Third of the three legacy routes tools.js targets (with fieldUpdateUrl and formUpdateUrl): the
     * registry maps `upload_image` here, and postOperation sends X-Editor-Channel: webmcp. Hardcoding Ui
     * meant an agent's upload skipped the role allowlist (AgentToolsGate short-circuits to true for Ui)
     * and was audited as a human upload. A declared channel can only ever make the gate stricter.
     */
    private static function channelFor(Request $request): ActorChannel
    {
        return trim((string) $request->header('X-Editor-Channel')) === 'webmcp'
            ? ActorChannel::Webmcp
            : ActorChannel::Ui;
    }

    public function __invoke(Request $request, int $site): JsonResponse
    {
        $siteModel = Site::findOrFail($site);
        $this->authorize('update', $siteModel);

        $channel = self::channelFor($request);

        // Exposure refusal (spec § 8, ruling R1) BEFORE any preflight / validation / gate answer: an
        // agent caller must not learn that upload_image exists but is unexposed for this site — a 409
        // (missing composition_revision on the JSON path) or a 422 returned to such a caller is exactly
        // that oracle, and it would also hide the exposure set behind the wrong literal. Producing the
        // byte-identical unknown-name refusal here (same code, message, and audit row) fixes ORDERING;
        // run() re-checks as the real boundary. Only a permitted caller reaches it — a stranger is
        // refused by the SitePolicy gate above with its own literal.
        if ($channel !== ActorChannel::Ui) {
            $refused = $this->operations->refuseIfUnexposed(
                new EditorContext($request->user(), $siteModel, $channel, self::grantPrincipal($request, $siteModel, $channel)),
                'upload_image',
                $request->all(),
            );
            if ($refused !== null) {
                return response()->json($refused->toArray(), EditorOperationController::statusFor($refused));
            }
        }

        // Only a JSON base64 upload needs a composition_revision; a missing FILE on the legacy multipart
        // path must still fail validation as `file required` (422), not as a stale revision (409).
        if (! $request->hasFile('file') && $request->exists('data_base64') && ! $request->exists('composition_revision')) {
            return EditorOperationController::revisionBaseRequired();
        }

        /*
         * The multipart branch below writes the object and the site_media row DIRECTLY, without entering
         * the operations layer — so unlike the JSON branch it never meets AgentToolsGate on its own.
         * Uploads on the webmcp channel must pass the agent gate like every other write: declaring a
         * channel must never make the gate weaker than not declaring one, so the gate is consulted here
         * before anything is ingested. (The exposure refusal above is the one agent-reachable write that
         * never enters EditorOperations::run() — it calls ingestUpload() directly — so it is consulted
         * here in the same place, before the gate, exactly as run() consults exposure before the gate.)
         */
        $agentMultipartRefused = $request->hasFile('file')
            && $channel !== ActorChannel::Ui
            && (bool) config('editor.agent_approval.enabled');

        // Staff on Ui stays trusted (SitePolicy is the wall). A client never
        // gets that pass: upload_image is gated identically to the SANDBOX
        // writes (deploy flag + Webmcp role gate on both Ui and Webmcp).
        $user = $request->user();
        $gateOpen = $user->isClientUser()
            ? $this->gate->enabledForUserAndOperation($user, $channel, $this->registry->get('upload_image'))
            : ($channel === ActorChannel::Ui || $this->gate->enabledFor($user, $channel));

        if (! $gateOpen) {
            $this->recorder->record(
                $siteModel->id,
                null,
                $user->id,
                $channel,
                'upload_image',
                'forbidden',
            );

            return response()->json([
                'ok' => false,
                'error' => ['code' => 'forbidden', 'message' => 'Agent tools are disabled for this actor.'],
                'state' => ['site_id' => $siteModel->id] + self::NEUTRAL_STATE,
                'receipt' => ResultReceipt::neutral()->toArray(),
            ], 403);
        }

        if ($agentMultipartRefused) {
            $this->recorder->record(
                $siteModel->id,
                null,
                $request->user()->id,
                $channel,
                'upload_image',
                'forbidden',
            );

            return response()->json([
                'ok' => false,
                'error' => ['code' => 'forbidden', 'message' => 'Agent multipart uploads are not available.'],
                'state' => ['site_id' => $siteModel->id] + self::NEUTRAL_STATE,
                'receipt' => ResultReceipt::neutral()->toArray(),
            ], 403);
        }

        $validatedRequest = UploadImageRequest::fromRequest($request);

        if (! $request->hasFile('file')) {
            $input = $validatedRequest->validated();
            $input['parent_origin'] = EditorParentOrigin::fromRequest($request);
            $result = $this->operations->run(
                new EditorContext($request->user(), $siteModel, $channel, self::grantPrincipal($request, $siteModel, $channel)),
                'upload_image',
                $input,
            );

            return response()->json($result->toArray(), EditorOperationController::statusFor($result));
        }

        try {
            $media = $this->ingestService->ingestUpload($siteModel, $request->file('file'), $channel);
        } catch (UnsupportedImageException $exception) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'validation', 'message' => $exception->getMessage()],
                'state' => ['site_id' => $siteModel->id] + self::NEUTRAL_STATE,
                'receipt' => ResultReceipt::neutral()->toArray(),
                'message' => $exception->getMessage(),
            ], 422);
        }

        // The JSON branch above is logged by the layer; this human file-picker branch bypasses it.
        $this->recorder->record($siteModel->id, null, $request->user()->id, $channel, 'upload_image');

        return response()->json([
            'path' => $media->s3_key,
            'url' => $media->url,
            'id' => $media->id,
        ]);
    }

    private static function grantPrincipal(Request $request, Site $site, ActorChannel $channel): ?string
    {
        if ($channel !== ActorChannel::Webmcp) {
            return null;
        }

        $principal = trim((string) $request->header('X-Editor-Agent-Session'));
        $issued = $principal === '' ? null : Cache::get("editor:agent-session:{$principal}");

        return is_array($issued)
            && ($issued['user_id'] ?? null) === $request->user()?->getKey()
            && ($issued['site_id'] ?? null) === $site->getKey()
                ? $principal
                : null;
    }
}

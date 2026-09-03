<?php

namespace App\Http\Controllers\Site\Editor;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Site\EditorAgentApproval;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\ApprovalStore;
use App\Services\Site\Editor\EditorOperationRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class AgentApprovalController extends Controller
{
    public function __construct(
        private readonly ApprovalStore $approvals,
        private readonly EditorOperationRecorder $recorder,
    ) {}

    public function index(Request $request, int $site): JsonResponse
    {
        $siteModel = Site::query()->findOrFail($site);
        $this->authorizeSiteUpdate($request, $siteModel, 'agent_approvals.list');

        $approvals = EditorAgentApproval::query()
            ->where('site_id', $siteModel->getKey())
            ->where('kind', 'operation')
            ->livePending()
            ->oldest('requested_at')
            ->get()
            ->map(fn (EditorAgentApproval $approval): array => [
                'id' => $approval->id,
                'operation' => $approval->operation,
                'channel' => $approval->channel,
                'summary' => $approval->summary,
                'requested_at' => $approval->requested_at->toIso8601String(),
                'expires_at' => $approval->expires_at->toIso8601String(),
            ])
            ->values();

        $this->record($request->user(), $siteModel, 'agent_approvals.list', 'ok');

        return response()->json(['approvals' => $approvals]);
    }

    public function approve(Request $request, int $site, string $approval): JsonResponse
    {
        $siteModel = Site::query()->findOrFail($site);
        $this->authorizeSiteUpdate($request, $siteModel, 'agent_approvals.approve');
        $approvalModel = $this->operationApproval($request->user(), $siteModel, $approval, 'agent_approvals.approve');
        $approved = $this->approvals->approve($approvalModel, $request->user());
        $this->record($request->user(), $siteModel, 'agent_approvals.approve', $approved ? 'ok' : 'conflict');

        return response()->json(['ok' => $approved], $approved ? 200 : 409);
    }

    public function deny(Request $request, int $site, string $approval): JsonResponse
    {
        $siteModel = Site::query()->findOrFail($site);
        $this->authorizeSiteUpdate($request, $siteModel, 'agent_approvals.deny');
        $approvalModel = $this->operationApproval($request->user(), $siteModel, $approval, 'agent_approvals.deny');
        $denied = $this->approvals->deny($approvalModel);
        $this->record($request->user(), $siteModel, 'agent_approvals.deny', $denied ? 'ok' : 'conflict');

        return response()->json(['ok' => $denied], $denied ? 200 : 409);
    }

    public function grant(Request $request, int $site): JsonResponse
    {
        $siteModel = Site::query()->findOrFail($site);
        $this->authorizeSiteUpdate($request, $siteModel, 'agent_approvals.grant');
        $validated = $this->validateGrant($request);
        $grant = $this->approvals->grant(
            $siteModel,
            $request->user(),
            $validated['grant_principal'],
            ActorChannel::from($validated['channel']),
        );
        $this->record($request->user(), $siteModel, 'agent_approvals.grant', 'ok');

        return response()->json([
            'ok' => true,
            'grant' => [
                'id' => $grant->id,
                'channel' => $grant->channel,
                'expires_at' => $grant->expires_at->toIso8601String(),
            ],
        ]);
    }

    public function revokeGrant(Request $request, int $site): JsonResponse
    {
        $siteModel = Site::query()->findOrFail($site);
        $this->authorizeSiteUpdate($request, $siteModel, 'agent_approvals.grant_revoke');
        $validated = $this->validateGrant($request);
        $grants = EditorAgentApproval::query()
            ->where('site_id', $siteModel->getKey())
            ->where('kind', 'grant')
            ->where('grant_principal', $validated['grant_principal'])
            ->where('channel', $validated['channel'])
            ->latest('requested_at')
            ->get();

        if ($grants->isEmpty()) {
            $this->record($request->user(), $siteModel, 'agent_approvals.grant_revoke', 'not_found');
            abort(404);
        }

        $revoked = false;
        foreach ($grants as $grant) {
            $revoked = $this->approvals->revoke($grant) || $revoked;
        }
        $this->record($request->user(), $siteModel, 'agent_approvals.grant_revoke', $revoked ? 'ok' : 'conflict');

        return response()->json(['ok' => $revoked], $revoked ? 200 : 409);
    }

    private function authorizeSiteUpdate(Request $request, Site $site, string $operation): void
    {
        if (Gate::forUser($request->user())->allows('update', $site)) {
            return;
        }

        $this->record($request->user(), $site, $operation, 'forbidden');
        abort(403);
    }

    private function operationApproval(
        User $user,
        Site $site,
        string $approval,
        string $operation,
    ): EditorAgentApproval {
        $approvalModel = Str::isUuid($approval)
            ? EditorAgentApproval::query()
                ->where('site_id', $site->getKey())
                ->where('kind', 'operation')
                ->find($approval)
            : null;

        if ($approvalModel === null) {
            $this->record($user, $site, $operation, 'not_found');
            abort(404);
        }

        return $approvalModel;
    }

    /**
     * @return array{grant_principal: string, channel: string}
     */
    private function validateGrant(Request $request): array
    {
        return $request->validate([
            'grant_principal' => ['required', 'string', 'max:255'],
            'channel' => ['required', Rule::in([ActorChannel::Webmcp->value, ActorChannel::Mcp->value])],
        ]);
    }

    private function record(User $user, Site $site, string $operation, string $resultCode): void
    {
        $this->recorder->record(
            siteId: $site->getKey(),
            pageId: null,
            actorUserId: $user->getKey(),
            channel: ActorChannel::Ui,
            operation: $operation,
            resultCode: $resultCode,
        );
    }
}

<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\AgentToolsGate;
use App\Services\Site\Editor\EditorOperationRecorder;
use App\Services\Site\SitePublishService;
use Illuminate\Http\Request;

class SitePublishController extends Controller
{
    public function __construct(
        protected SitePublishService $publish,
        protected EditorOperationRecorder $recorder,
        protected AgentToolsGate $gate,
    ) {}

    private static function channelFor(Request $request): ActorChannel
    {
        return trim((string) $request->header('X-Editor-Channel')) === 'webmcp'
            ? ActorChannel::Webmcp
            : ActorChannel::Ui;
    }

    /**
     * Front 2 maps the `publish_summary` tool to THIS route (tools.js → publishSummaryUrl), and it had no
     * AgentToolsGate check and wrote no audit row — so an agent's reads of the pending-publish summary were
     * ungated in every flag combination and invisible in the log, while Front 3 ran the same operation
     * through PublishSummaryOperation and got both. The read still returns
     * the same body; only the gate and the log row are new.
     */
    public function summary(Request $request, int $site)
    {
        $siteModel = Site::findOrFail($site);
        $this->authorize('view', $siteModel);

        $channel = self::channelFor($request);

        if ($channel !== ActorChannel::Ui && ! $this->gate->enabledFor($request->user(), $channel)) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'forbidden', 'message' => 'Agent tools are disabled for this actor.'],
            ], 403);
        }

        $this->recorder->record($siteModel->id, null, $request->user()->id, $channel, 'publish_summary');

        return response()->json($this->publish->publishSummary($siteModel));
    }

    public function publish(Request $request, int $site)
    {
        $siteModel = Site::findOrFail($site);
        $this->authorize('update', $siteModel);

        $validated = $request->validate(['publish_note' => 'nullable|string|max:500']);
        $note = $validated['publish_note'] ?? null;
        $version = $this->publish->publishSite(
            $siteModel,
            publishNote: $note,
            userId: $request->user()->id,
            channel: ActorChannel::Ui,
        );

        // Publishing is unreachable from every tool front by design, so the Ui channel here is the truth,
        // not a placeholder. It is the one editor action with no operation behind it, hence no log row.
        $this->recorder->record($siteModel->id, null, $request->user()->id, ActorChannel::Ui, 'publish');

        return response()->json([
            'version' => $version->version,
            'version_id' => $version->id,
            'published_at' => $version->published_at->toIso8601String(),
        ]);
    }

    public function discardAll(Request $request, int $site)
    {
        $siteModel = Site::findOrFail($site);
        $this->authorize('update', $siteModel);

        $this->publish->discardAllDrafts($siteModel);
        $this->recorder->record($siteModel->id, null, $request->user()->id, ActorChannel::Ui, 'discard_all');

        return response()->json(['ok' => true]);
    }
}

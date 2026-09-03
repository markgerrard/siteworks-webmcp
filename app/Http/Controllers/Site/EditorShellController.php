<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\AgentToolsGate;
use App\Services\Site\Editor\ToolExposure;
use App\Support\EditorParentOrigin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Js;
use Illuminate\Support\Str;

class EditorShellController extends Controller
{
    public function __construct(
        private readonly AgentToolsGate $agentToolsGate,
        private readonly ToolExposure $toolExposure,
    ) {}

    public function __invoke(Request $request, int $site, int $page)
    {
        $siteModel = Site::findOrFail($site);
        $this->authorize('view', $siteModel);

        // The iframe-side bridge validates `event.origin` against the parent
        // surface that minted it (agent vs customer). Pass it through the
        // signed URL so the preview controller can echo the right value into
        // the iframe config — the signature covers query params, so this
        // can't be tampered with after minting.
        //
        // fromRequest pins https:// + host in every real environment and
        // yields the true http://host:port origin only under the
        // pest-plugin-browser HTTP server (see EditorParentOrigin).
        $isCustomerSurface = $request->getHost() === config('domains.customer_domain');
        $parentOrigin = EditorParentOrigin::fromRequest($request);

        // Mint a temporarySignedRoute URL — the iframe origin is cross-origin
        // from agents and has no auth cookies, so signature is the only proof
        // of authorization. Same APP_KEY signs/verifies on both sides.
        // 8h expiry covers a reasonable working session; refresh on revisit.
        $iframeUrl = URL::temporarySignedRoute(
            'editor-preview.show',
            now()->addHours(8),
            ['site' => $site, 'page' => $page, 'parent_origin' => $parentOrigin]
        );
        // The parent-side bridge validates `event.origin` against this. Parse
        // it from the URL the iframe actually loads so the two can never
        // disagree — https://<editor_preview_domain> in every real
        // environment (forceScheme), http://<domain>:<port> under the
        // browser-test server.
        $iframeUrlParts = parse_url($iframeUrl);
        $iframeOrigin = $iframeUrlParts['scheme'].'://'.$iframeUrlParts['host']
            .(isset($iframeUrlParts['port']) ? ':'.$iframeUrlParts['port'] : '');
        // closeEditUrl points the toolbar's "Close edit" button at the
        // user's site landing — clients exit to their portal overview,
        // staff exit to the agent sites.show page.
        $closeEditUrl = ($isCustomerSurface || ! \Illuminate\Support\Facades\Route::has('sites.show'))
            ? route('client.portal.site', $siteModel)
            : route('sites.show', $siteModel);

        $pages = $siteModel->generatedPages()
            ->whereNull('archived_at')
            ->get(['id', 'draft_revision_id', 'published_revision_id', 'structure_epoch']);
        $currentRevisionIds = $pages->mapWithKeys(fn (GeneratedPage $sitePage): array => [
            (string) $sitePage->id => $sitePage->draft_revision_id ?? $sitePage->published_revision_id,
        ])->all();
        $structureEpochs = $pages->mapWithKeys(fn (GeneratedPage $sitePage): array => [
            (string) $sitePage->id => (int) ($sitePage->structure_epoch ?? 0),
        ])->all();
        $capabilities = ['edit', 'publish', 'media'];
        $exposureSeed = [];

        $agentTools = $this->agentToolsGate->enabledFor($request->user(), ActorChannel::Webmcp);
        if ($request->user()->isClientUser()) {
            $agentTools = $agentTools && (bool) config('editor.agent_tools.client_portal_enabled', false);
        }

        if ($agentTools) {
            $capabilities[] = 'agent_tools';

            // Exposure sets (spec § 8, ruling R1): Front 2 knows the site at shell render, so the
            // seed carries the per-site allowlist the registry registers from — the tenant's
            // registered surface equals its reachable surface. Seeded only alongside the
            // agent_tools capability, so every other shell config is byte-identical.
            $exposureSeed = [
                'exposureSet' => $this->toolExposure->nameFor($siteModel),
                'agentTools' => $this->toolExposure->setFor($siteModel),
            ];

            if ((bool) config('editor.agent_approval.enabled')) {
                $capabilities[] = 'agent_approval';
            }
        }

        if ($this->agentToolsGate->enabledFor($request->user(), ActorChannel::Ui)) {
            $capabilities[] = 'editor_ui';
        }

        $sectionCatalog = collect(config('section_catalog', []))
            ->map(fn (array $definition, string $type): array => [
                'label' => config("site_sections.{$type}.label") ?? Str::headline($type),
                'page_types' => array_values($definition['page_types'] ?? []),
                'singleton' => ($definition['singleton'] ?? false) === true,
            ])->all();

        $agentSessionId = null;
        if ((bool) config('editor.agent_approval.enabled') && in_array('agent_tools', $capabilities, true)) {
            $agentSessionId = (string) Str::uuid();
            Cache::put("editor:agent-session:{$agentSessionId}", [
                'user_id' => $request->user()->getKey(),
                'site_id' => $siteModel->getKey(),
            ], now()->addMinutes((int) config('editor.agent_approval.grant_ttl_minutes', 60)));
        }

        $config = (string) Js::from([
            'protocol' => 'siteworks-editor-1',
            'siteId' => $siteModel->id,
            'pageId' => $page,
            'siteName' => $siteModel->business_name,
            'iframeOrigin' => $iframeOrigin,
            'csrfToken' => csrf_token(),
            'capabilities' => $capabilities,
            ...$exposureSeed,
            'agentSessionId' => $agentSessionId,
            'currentRevisionIds' => $currentRevisionIds,
            'structureEpochs' => $structureEpochs,
            'compositionRevision' => (int) (SiteDraft::query()
                ->where('site_id', $siteModel->id)
                ->value('admin_revision') ?? 0),
            'sectionCatalog' => $sectionCatalog,
            // Trailing /pages/0/ is a placeholder the parent substitutes
            // with the page id from the inline marker. The iframe can
            // navigate away from the page this shell was opened on.
            // Path-relative on purpose: the shell ships from BOTH surfaces
            // (agents + customer) and these endpoints live on whichever host
            // served the shell, so a path keeps every fetch same-origin —
            // including under the browser-test server, whose forced URL root
            // (127.0.0.1:<port>) would otherwise make absolute URLs
            // cross-origin and trip connect-src 'self'.
            'fieldUpdateUrl' => route('site.admin.field-update', ['site' => $site, 'page' => 0], false),
            'publishSummaryUrl' => route('site.admin.publish-summary', $site, false),
            'publishUrl' => route('site.admin.publish', $site, false),
            'discardAllUrl' => route('site.admin.discard-all', $site, false),
            'mediaUploadUrl' => route('site.admin.media-upload', $site, false),
            'portraitUploadUrl' => \Illuminate\Support\Facades\Route::has('site.admin.portrait-upload')
                ? route('site.admin.portrait-upload', $site, false)
                : null,
            'closeEditUrl' => $closeEditUrl,
            // Trailing /0 is a placeholder the review replaces with the
            // section index from the iframe marker. Generating the URL
            // here keeps the route name as the single source of the path.
            'formDefinitionUrl' => route('site.admin.form-definition', ['site' => $site, 'page' => 0, 'section' => 0], false),
            'formUpdateUrl' => route('site.admin.form-update', ['site' => $site, 'page' => 0, 'section' => 0], false),
            'operationUrl' => route('site.editor.operation', ['site' => $site, 'operation' => '__operation__'], false),
            'previewUrlUrl' => route('site.editor.preview-url', ['site' => $site, 'page' => 0], false),
            'structureUrl' => route('site.editor.structure', ['site' => $site, 'page' => 0], false),
            'sectionsUrl' => route('site.editor.sections', ['site' => $site, 'page' => 0], false),
            'brandContextUrl' => route('site.editor.brand-context', $site, false),
            'imageVersionsUrl' => route('site.editor.image-versions', $site, false),
            'jobStatusUrl' => route('site.editor.job-status', ['site' => $site, 'ref' => 0], false),
            'selectLogoUrl' => route('site.editor.select-logo', $site, false),
            'restoreImageVersionUrl' => route('site.editor.restore-image-version', $site, false),
            'restoreMediaVersionUrl' => route('site.editor.restore-media-version', ['site' => $site, 'page' => 0], false),
        ]);

        return view('site.editor-shell', [
            'site' => $siteModel,
            'pageId' => $page,
            'iframeUrl' => $iframeUrl,
            'iframeOrigin' => $iframeOrigin,
            'config' => $config,
        ]);
    }
}

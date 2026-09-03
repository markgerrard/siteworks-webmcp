<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Services\Site\PageRenderer;
use App\Support\EditorParentOrigin;
use Illuminate\Http\Request;
use Illuminate\Support\Js;

class EditorPreviewController extends Controller
{
    public function __construct(protected PageRenderer $renderer) {}

    public function __invoke(Request $request, int $site, int $page)
    {
        // Authorization is proven by the `signed` middleware on the route —
        // the iframe loads cross-origin from agents and has no auth cookies,
        // so authorize('view') wouldn't have a User to check. The unforgeable
        // signature minted by EditorShellController on the authenticated
        // agents side is the only proof of authorization required here.
        $siteModel = Site::findOrFail($site);

        // The bridge inside the iframe drops any postMessage whose origin
        // doesn't match parentOrigin — so this MUST reflect the surface
        // (agent vs customer) that opened the editor. EditorShellController
        // includes parent_origin in the signed URL; the signature covers
        // it, so a tampered value can't reach this controller. We still
        // allowlist against the configured surface domains as defence in
        // depth, falling back to the agent surface for any legacy signed
        // URLs minted before this param existed.
        $parentOrigin = EditorParentOrigin::resolve((string) $request->query('parent_origin', ''));

        // Render in 'admin-edit' mode so the renderer emits edit-anchor data
        // attributes the iframe-side editor needs (data-editable, data-editable-type
        // etc — see resources/views/site/sections/*.blade.php).
        // signedNav: true makes nav links and cross-page hrefs mint signed
        // editor-preview URLs at render time, so clicking About/Services/etc
        // inside the iframe stays on the editor-preview origin and passes the
        // `signed` middleware. Without this, clicks 404 because the iframe is
        // cross-origin from the agents shell and only the signed editor-preview
        // routes exist on this hostname.
        // parentOrigin is threaded into those signed nav URLs so cross-page
        // navigation inside the iframe keeps the same parent surface — without
        // it, the destination page falls back to agent_domain and the bridge
        // silently drops every save/publish/discard from the customer surface.
        $html = $this->renderer->render($siteModel, $page, mode: 'admin-edit', signedNav: true, parentOrigin: $parentOrigin, formPanel: true, useDraftAssets: true);

        // Iframe-side editor config — NO admin endpoint URLs (writes go via
        // postMessage to parent). Carries only what the iframe needs to talk
        // to the bridge.
        $config = (string) Js::from([
            'protocol' => 'siteworks-editor-1',
            'siteId' => $siteModel->id,
            'pageId' => $page,
            'parentOrigin' => $parentOrigin,
            'currentRevisionId' => optional(GeneratedPage::find($page))->draft_revision_id
                ?? optional(GeneratedPage::find($page))->published_revision_id,
        ]);

        $injection = view('site.partials.editor-iframe-injection', ['config' => $config])->render();
        $html = str_replace('</body>', $injection.'</body>', $html);

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}

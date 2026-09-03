<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Site\SiteVersion;
use App\Services\Site\PageRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SiteVersionPreviewController extends Controller
{
    public function __construct(protected PageRenderer $renderer) {}

    public function __invoke(Request $request, Site $site, SiteVersion $version): Response
    {
        $this->authorize('view', $site);

        if ($version->site_id !== $site->id) {
            abort(404);
        }

        // Determine which page to render. Default to homepage from this version's composition.
        $pageId = (int) $request->query('page', 0);
        if (! $pageId) {
            $pageId = (int) ($version->composition['homepage_page_id'] ?? 0);
        }

        if (! $pageId) {
            // Fall back to first pinned page.
            $first = collect($version->page_revisions)->first();
            $pageId = (int) ($first['page_id'] ?? 0);
        }

        if (! $pageId) {
            abort(404);
        }

        $html = $this->renderer->renderVersion($site, $pageId, $version);

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}

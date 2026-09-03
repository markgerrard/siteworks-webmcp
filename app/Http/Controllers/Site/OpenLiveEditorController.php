<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Services\Site\EditSessionToken;
use App\Services\Site\SiteHostResolver;
use Illuminate\Http\Request;

class OpenLiveEditorController extends Controller
{
    public function __construct(
        protected EditSessionToken $tokens,
        protected SiteHostResolver $hosts,
    ) {}

    public function __invoke(Request $request, int $site, int $page)
    {
        $siteModel = Site::findOrFail($site);
        $this->authorize('update', $siteModel);

        $pageModel = GeneratedPage::where('site_id', $site)
            ->whereNull('archived_at')
            ->findOrFail($page);

        $host = $this->resolvePublicHost($siteModel);
        abort_unless($host, 422, 'This site has no preview domain configured yet.');

        $token = $this->tokens->mint($site, $request->user()->id, $page);

        // homepage → '/', any other page type → '/{page_type}'
        $slug = $pageModel->page_type === 'home' ? '' : $pageModel->page_type;
        $path = $slug === '' ? '/' : "/{$slug}";

        $url = "https://{$host}{$path}?edit_token={$token}";

        return redirect()->away($url);
    }

    /**
     * Prefer the active custom domain; otherwise build the preview FQDN from
     * the site's preview_domain slug + brand suffix.
     */
    private function resolvePublicHost(Site $site): ?string
    {
        if ($site->custom_domain && $site->custom_domain_status === 'active') {
            return $site->custom_domain;
        }

        if ($site->preview_domain) {
            return $this->hosts->previewFqdn(
                $site->preview_domain,
                $site->preview_brand ?? 'a',
            );
        }

        return null;
    }
}

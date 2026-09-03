<?php

namespace App\Http\Controllers\Site;

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\EditSessionCookie;
use App\Services\Site\EditSessionToken;
use App\Services\Site\PageRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Js;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class PublicSiteController
{
    public function __construct(
        protected PageRenderer $renderer,
        protected EditSessionToken $tokens,
        protected EditSessionCookie $editCookie,
        protected \App\Services\Site\PublicPageCache $pageCache,
    ) {}

    /**
     * Resolve the Site from the current request's Host header.
     * Checks request attribute first (set by middleware if present), then
     * falls back to looking up by preview subdomain or custom domain.
     */
    protected function resolveSite(Request $request): ?Site
    {
        return app(\App\Services\Site\SiteHostResolver::class)->resolve($request);
    }

    /**
     * Detect whether this request should be rendered in admin-edit mode.
     *
     * Checks ?edit_token first (initial redirect from admin). If valid,
     * issues the edit_session cookie AND returns a redirect response to strip
     * the token from the URL (preventing Referer leakage to external resources).
     * Otherwise checks the existing edit_session cookie.
     *
     * Returns one of:
     *   - a RedirectResponse (token was valid; redirect to clean URL with cookie set)
     *   - an array payload (cookie already present; proceed to render)
     *   - null (no edit mode)
     *
     * @return \Illuminate\Http\RedirectResponse|array{user_id: int, site_id: int, page_id: int, expires_at: int, cookie: ?SymfonyCookie}|null
     */
    protected function resolveEditMode(Request $request, int $siteId, int $pageId): mixed
    {
        if (! config('site.use_versioned_renderer')) {
            return null;
        }

        // Initial handoff via signed token (first load from admin redirect).
        // Strip the token from the URL immediately to prevent Referer leakage
        // to external resources (CDN, fonts, etc.) on subsequent requests.
        $tokenStr = $request->query('edit_token');
        if ($tokenStr) {
            $payload = $this->tokens->validateForPage((string) $tokenStr, $siteId, $pageId);
            if ($payload) {
                // Single-use token enforcement: hash the raw token and check
                // whether it has already been redeemed. We hash so the raw
                // HMAC-signed string is never stored in cache.
                $tokenHash = hash('sha256', (string) $tokenStr);
                $cacheKey = "edit_token_consumed:{$tokenHash}";

                $ttl = max(1, $payload['expires_at'] - time());
                // Atomic SETNX — returns false if the key already exists (already redeemed).
                // Replaces the previous has()+put() TOCTOU pair.
                if (! Cache::add($cacheKey, true, $ttl)) {
                    // Token already redeemed — fall through to public mode.
                    return null;
                }

                $cookie = $this->editCookie->make($payload, $request->getHost());

                // Rebuild URL without edit_token; preserve any other query params.
                $remaining = $request->except('edit_token');
                $cleanUrl = $request->url();
                if (! empty($remaining)) {
                    $cleanUrl .= '?'.http_build_query($remaining);
                }

                return redirect($cleanUrl, 302)->withCookie($cookie);
            }
        }

        // Subsequent requests via cookie
        $raw = $request->cookie('edit_session');
        if ($raw) {
            $cookiePayload = $this->editCookie->validate((string) $raw);
            if ($cookiePayload && $cookiePayload['site_id'] === $siteId) {
                return array_merge($cookiePayload, ['page_id' => $pageId, 'cookie' => null]);
            }
            // Cookie present but invalid or for a different site — signal the
            // response to forget it so the browser doesn't keep sending it.
            $request->attributes->set('clear_edit_cookie', true);
        }

        return null;
    }

    public function home(Request $request)
    {
        abort_unless(config('site.use_versioned_renderer'), 404);

        $site = $this->resolveSite($request);
        abort_unless($site, 404);

        $current = SiteVersionCurrent::where('site_id', $site->id)->first();
        abort_unless($current, 404);

        $version = SiteVersion::find($current->version_id);
        abort_unless($version, 404);

        $homeId = $version->composition['homepage_page_id'] ?? null;
        abort_unless($homeId, 404);

        $editResult = $this->resolveEditMode($request, $site->id, (int) $homeId);

        if ($editResult instanceof \Illuminate\Http\RedirectResponse) {
            return $editResult;
        }

        if ($editResult !== null) {
            return $this->renderEditMode($request, $site, (int) $homeId, $editResult);
        }

        $isOnePage = $site->preview_layout === \App\Enums\PreviewLayout::OnePage;

        $html = $this->pageCache->get($site, (int) $version->id, (int) $homeId, stacked: $isOnePage);
        if ($html === null) {
            $html = $isOnePage
                ? $this->renderer->renderStacked($site, mode: 'public')
                : $this->renderer->render($site, $homeId, mode: 'public');
            $this->pageCache->put($site, (int) $version->id, (int) $homeId, $isOnePage, $html);
        }

        $publicResponse = response($html)
            ->header('Cache-Tag', "site:{$site->id}")
            ->header('Content-Type', 'text/html; charset=UTF-8');

        if ($request->attributes->get('clear_edit_cookie')) {
            $publicResponse->withCookie(Cookie::forget('edit_session'));
        }

        return $publicResponse;
    }

    public function page(Request $request, string $slug)
    {
        abort_unless(config('site.use_versioned_renderer'), 404);

        $site = $this->resolveSite($request);
        abort_unless($site, 404);

        $current = SiteVersionCurrent::where('site_id', $site->id)->first();
        abort_unless($current, 404);

        $version = SiteVersion::find($current->version_id);
        abort_unless($version, 404);

        // Resolve the requested slug strictly via the published snapshot:
        // load page metadata for the version's pinned page_id set, then match
        // by page_type. This means an unpublished archive does NOT 404 a page
        // that the published version still pins, AND a newly-archived live
        // page can still be served from the published version.
        // One-page sites stack every flat page into the home document; the
        // pre-hierarchy behaviour redirected ANY flat slug (known or not) to
        // /#slug before existence checks. Preserve that exactly (H-T3-r2:
        // the reordering was an undeclared 302->404 change). Nested slugs
        // take the resolve-then-standalone path below.
        if ($site->preview_layout === \App\Enums\PreviewLayout::OnePage && ! str_contains($slug, '/')) {
            return redirect('/#'.$slug, 302);
        }

        $pinnedPageIds = collect($version->page_revisions)->pluck('page_id')->all();
        if (empty($pinnedPageIds)) {
            abort(404);
        }

        $page = GeneratedPage::whereIn('id', $pinnedPageIds)
            ->where('page_type', $slug)
            ->when(! str_contains($slug, '/'), fn ($query) => $query->whereNull('parent_id'))
            ->first();
        abort_unless($page, 404);

        $editResult = $this->resolveEditMode($request, $site->id, $page->id);

        if ($editResult instanceof \Illuminate\Http\RedirectResponse) {
            return $editResult;
        }

        if ($editResult !== null) {
            return $this->renderEditMode($request, $site, $page->id, $editResult);
        }

        $html = $this->pageCache->get($site, (int) $version->id, (int) $page->id);
        if ($html === null) {
            $html = $this->renderer->render($site, $page->id, mode: 'public');
            $this->pageCache->put($site, (int) $version->id, (int) $page->id, false, $html);
        }

        $publicResponse = response($html)
            ->header('Cache-Tag', "site:{$site->id}")
            ->header('Content-Type', 'text/html; charset=UTF-8');

        if ($request->attributes->get('clear_edit_cookie')) {
            $publicResponse->withCookie(Cookie::forget('edit_session'));
        }

        return $publicResponse;
    }

    /**
     * Render a page in admin-edit mode with editor chrome injected.
     *
     * @param  array{user_id: int, site_id: int, page_id: int, expires_at: int, cookie: ?SymfonyCookie}  $editPayload
     */
    private function renderEditMode(Request $request, Site $site, int $pageId, array $editPayload)
    {
        $pageModel = GeneratedPage::find($pageId);
        abort_unless($pageModel, 404);

        $html = $this->renderer->render($site, $pageId, mode: 'admin-edit', publicHostNav: true, formPanel: false);

        $config = (string) Js::from([
            'siteName' => $site->business_name,
            'csrfToken' => '', // session CSRF not used on /_edit/* routes (cookie-auth)
            'editCsrf' => $editPayload['csrf'] ?? '', // per-session CSRF for X-Edit-Csrf header
            'currentRevisionId' => $pageModel->draft_revision_id ?? $pageModel->published_revision_id,
            // Use path-relative URLs so the browser's current scheme/host is used —
            // route() would bake in APP_URL's http:// and cause mixed-content blocks
            // on https pages (preview subdomains + custom domains are always https).
            'fieldUpdateUrl' => "/_edit/fields/{$pageId}",
            'publishSummaryUrl' => '/_edit/publish-summary',
            'publishUrl' => '/_edit/publish',
            'discardAllUrl' => '/_edit/discard-all',
            'exitEditUrl' => '/_edit/exit',
            'mediaUploadUrl' => '/_edit/media',
            // No portrait ingest exists on the site-public surface (editor-shell
            // routes are not registered here, and the admin route would want an
            // admin session, not X-Edit-Csrf). Null makes the picker fail
            // CLOSED client-side (route() here 500'd every public-host
            // edit render).
            'portraitUploadUrl' => null,
            'previewUrl' => $request->getSchemeAndHttpHost().($pageModel->publicPath() === 'home' ? '/' : '/'.$pageModel->publicPath()),
        ]);

        $injection = view('site.partials.editor-injection', ['config' => $config])->render();
        $html = str_replace('</body>', $injection.'</body>', $html);

        $response = response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');

        if ($editPayload['cookie'] !== null) {
            $response->withCookie($editPayload['cookie']);
        }

        return $response;
    }
}

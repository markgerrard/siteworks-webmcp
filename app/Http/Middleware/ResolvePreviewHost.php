<?php

namespace App\Http\Middleware;

use App\Http\Controllers\PreviewController;
use App\Http\Controllers\Site\PublicSiteController;
use App\Models\Site;
use App\Services\Site\SiteHostResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When Host matches a branded preview suffix or an active custom domain,
 * route through PreviewController::showByHost; otherwise pass through to app routes.
 */
class ResolvePreviewHost
{
    private const PAGE_PATH_PATTERN = '/^[a-z0-9-]+(?:\/[a-z0-9-]+){0,3}$/';

    /**
     * Paths that must pass through to their normal routes even when served
     * from a preview/custom host (API calls, assets, Livewire, etc.).
     *
     * @var array<int, string>
     */
    private const PASSTHROUGH_PREFIXES = [
        'livewire/',
        'js/',
        'css/',
        'build/',
        'favicon',
        'images/',
        '_debugbar',
        'up',
        '_edit',
        'shop',
        'products',
        'collections',
        'enquire',
        'news',
        'sitemap.xml',
        'robots.txt',
        // Self-hosted assets: the /fonts/<a>+<b>.css pair route is dynamic and
        // must reach PHP on site hosts; /vendor holds the pinned lucide bundle.
        'fonts/',
        'vendor/',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());

        $site = $this->resolveSite($host);

        if ($site) {
            $request->attributes->set('resolved_site', $site);
            $path = trim($request->path(), '/');

            // GET + HEAD are the public surface; HEAD must follow the same
            // path so uptime monitors and link checkers don't get a spurious
            // 404 (Symfony auto-strips the body for HEAD responses).
            if ($this->isPassthrough($path) || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
                return $next($request);
            }

            $page = $path === '' ? null : $path;

            if ($page !== null && preg_match(self::PAGE_PATH_PATTERN, $page) !== 1) {
                abort(404);
            }

            // When the versioned renderer flag is on, delegate to
            // PublicSiteController (site_versions_current → PageRenderer). The flag
            // defaults to false so this code path is dormant until a human sets
            // SITE_USE_VERSIONED_RENDERER=true in the environment.
            if (config('site.use_versioned_renderer')) {
                $controller = app(PublicSiteController::class);

                return $page === null
                    ? $controller->home($request)
                    : $controller->page($request, $page);
            }

            $view = app(PreviewController::class)->showByHost($request, $page);

            return response($view);
        }

        return $next($request);
    }

    private function resolveSite(string $host): ?Site
    {
        return app(SiteHostResolver::class)->siteForHost($host);
    }

    private function isPassthrough(string $path): bool
    {
        foreach (self::PASSTHROUGH_PREFIXES as $prefix) {
            $prefix = rtrim($prefix, '/');

            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function isPreviewHost(string $host): bool
    {
        return Site::where('preview_domain', $host)->exists();
    }

    private function isActiveCustomDomain(string $host): bool
    {
        return Site::where('custom_domain', $host)
            ->where('custom_domain_status', 'active')
            ->exists();
    }
}

<?php

namespace App\Http\Controllers\Site;

use App\Enums\PreviewLayout;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Shop\SnapshotReader;
use App\Services\Site\PublicPageCache;
use App\Services\Site\SiteHostResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController
{
    public function __construct(
        protected SiteHostResolver $hosts,
        protected PublicPageCache $pageCache,
        protected SnapshotReader $shopSnapshots,
    ) {}

    public function __invoke(Request $request): Response
    {
        abort_unless(config('site.use_versioned_renderer'), 404);

        $site = $this->hosts->resolve($request);
        abort_unless($site, 404);

        // Non-indexable hosts (preview subdomains, inactive custom domains)
        // get Disallow from robots.txt; serving them a sitemap would hand
        // crawlers a URL inventory we just told them not to walk.
        abort_unless($this->hosts->isIndexableHost($request, $site), 404);

        $current = SiteVersionCurrent::where('site_id', $site->id)->first();
        abort_unless($current, 404);

        $version = SiteVersion::find($current->version_id);
        abort_unless($version, 404);

        $base = $request->getScheme().'://'.$request->getHost();

        $xml = $this->pageCache->enabled()
            ? Cache::remember(
                $this->pageCache->namespacedKey($site, 'sitemap:'.$base),
                (int) config('site.public_cache_ttl', 3600),
                fn (): string => $this->buildXml($site, $version, $base),
            )
            : $this->buildXml($site, $version, $base);

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Cache-Tag' => "site:{$site->id}",
        ]);
    }

    protected function buildXml(Site $site, SiteVersion $version, string $base): string
    {
        $pins = collect($version->page_revisions ?? []);
        $pageIds = $pins->pluck('page_id')->filter()->map(fn ($id) => (int) $id)->all();
        $revisionIds = $pins->pluck('revision_id')->filter()->map(fn ($id) => (int) $id)->all();

        $pages = empty($pageIds)
            ? collect()
            : GeneratedPage::query()->whereIn('id', $pageIds)->get()->keyBy('id');
        $revisions = empty($revisionIds)
            ? collect()
            : PageRevision::query()->whereIn('id', $revisionIds)->get()->keyBy('id');

        $homeId = isset($version->composition['homepage_page_id'])
            ? (int) $version->composition['homepage_page_id']
            : null;
        $onePage = $site->preview_layout === PreviewLayout::OnePage;

        $entries = [];
        foreach ($pins as $pin) {
            $page = $pages->get((int) ($pin['page_id'] ?? 0));
            $revision = $revisions->get((int) ($pin['revision_id'] ?? 0));
            if ($page === null || $revision === null) {
                continue;
            }

            $isHome = $homeId !== null && (int) $page->id === $homeId;
            if ($onePage && ! $isHome && $page->parent_id === null) {
                continue;
            }

            $timestamp = $revision->updated_at ?? $revision->created_at;
            $entry = [
                'loc' => $isHome ? $base.'/' : $base.'/'.$page->publicPath(),
            ];
            if ($timestamp !== null) {
                $entry['lastmod'] = $timestamp->toAtomString();
            }
            $entries[] = $entry;
        }

        foreach ($this->shopEntries($site, $base) as $entry) {
            $entries[] = $entry;
        }

        $urls = '';
        foreach ($entries as $entry) {
            $loc = htmlspecialchars($entry['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $urls .= "  <url>\n    <loc>{$loc}</loc>";
            if (isset($entry['lastmod'])) {
                $lastmod = htmlspecialchars($entry['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $urls .= "\n    <lastmod>{$lastmod}</lastmod>";
            }
            $urls .= "\n  </url>\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .$urls
            .'</urlset>'."\n";
    }

    /**
     * @return list<array{loc: string, lastmod?: string}>
     */
    protected function shopEntries(Site $site, string $base): array
    {
        if (! $site->hasPurchasableShop()) {
            return [];
        }

        $snapshot = $this->shopSnapshots->forSite($site->id);
        if (! is_array($snapshot)) {
            return [];
        }

        $lastmod = $snapshot['meta']['published_at'] ?? $snapshot['meta']['built_at'] ?? null;
        $entries = [
            ['loc' => $base.'/shop'],
        ];
        if (is_string($lastmod) && $lastmod !== '') {
            $entries[0]['lastmod'] = $lastmod;
        }

        foreach ($snapshot['categories'] ?? [] as $category) {
            if (! is_array($category) || ($category['visibility'] ?? 'visible') !== 'visible') {
                continue;
            }

            $path = $category['path'] ?? $category['slug'] ?? null;
            if (! is_string($path) || $path === '') {
                continue;
            }

            $entry = ['loc' => $base.\App\Support\Shop\ShopUrls::collection($path)];
            if (is_string($lastmod) && $lastmod !== '') {
                $entry['lastmod'] = $lastmod;
            }
            $entries[] = $entry;
        }

        foreach ($snapshot['products'] ?? [] as $product) {
            if (! is_array($product) || ($product['status'] ?? 'published') !== 'published') {
                continue;
            }

            $slug = $product['slug'] ?? null;
            if (! is_string($slug) || $slug === '') {
                continue;
            }

            $entry = ['loc' => $base.\App\Support\Shop\ShopUrls::product($slug)];
            if (is_string($lastmod) && $lastmod !== '') {
                $entry['lastmod'] = $lastmod;
            }
            $entries[] = $entry;
        }

        return $entries;
    }
}

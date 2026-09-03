<?php

namespace App\Services\Site;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;

/**
 * Public-page rendered-HTML cache.
 *
 * Keyed by (site_id, version_id, page_id[, :stacked]) so:
 *   - a publish (new version_id) auto-misses without explicit flush
 *   - draft edits never leak to public pages (different mode = different path)
 *   - admins can still force-flush a site via invalidate($site)
 *
 * All keys are namespaced under a per-site tag so one site's publish
 * doesn't dump another tenant's cache. We use a version counter stored
 * under 'site:{id}:cache_v' to "tag" without relying on tagged cache
 * stores (Redis tagged cache is noisy about prefixes).
 */
class PublicPageCache
{
    public function enabled(): bool
    {
        return (bool) config('site.public_cache_enabled', false);
    }

    protected function ttl(): int
    {
        return (int) config('site.public_cache_ttl', 3600);
    }

    /**
     * Return cached HTML or null.
     */
    public function get(Site $site, int $versionId, int $pageId, bool $stacked = false): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $html = Cache::get($this->key($site, $versionId, $pageId, $stacked));

        return is_string($html) ? $html : null;
    }

    /**
     * Store HTML. Safe to call when disabled (no-op).
     */
    public function put(Site $site, int $versionId, int $pageId, bool $stacked, string $html): void
    {
        if (! $this->enabled()) {
            return;
        }

        Cache::put($this->key($site, $versionId, $pageId, $stacked), $html, $this->ttl());
    }

    /**
     * Flush every cached page for a given site.
     *
     * Called from SitePublishService after a new version becomes current.
     * We bump the site's internal cache-version counter; future reads
     * compute a fresh key that won't collide with pre-publish entries.
     * Old entries age out on TTL.
     */
    public function invalidate(Site $site): void
    {
        Cache::increment($this->siteCounterKey($site), 1);
    }

    public function generation(Site $site): int
    {
        return (int) Cache::get($this->siteCounterKey($site), 0);
    }

    /**
     * Cache key namespaced under the same per-site counter as rendered HTML.
     * PublicPageCache::invalidate() bumps that counter, so sitemap (and any
     * other suffix) misses for free after publish/unpublish.
     */
    public function namespacedKey(Site $site, string $suffix): string
    {
        $counter = (int) Cache::get($this->siteCounterKey($site), 0);

        return sprintf('site:%d:pubcache:%d:%s', $site->id, $counter, $suffix);
    }

    protected function key(Site $site, int $versionId, int $pageId, bool $stacked): string
    {
        return $this->namespacedKey(
            $site,
            sprintf('v%d:p%d:%s', $versionId, $pageId, $stacked ? 'stacked' : 'single'),
        );
    }

    protected function siteCounterKey(Site $site): string
    {
        return "site:{$site->id}:pubcache_counter";
    }
}

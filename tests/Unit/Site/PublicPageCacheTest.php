<?php

use App\Models\Site;
use App\Services\Site\PublicPageCache;
use Illuminate\Support\Facades\Cache;


beforeEach(function () {
    Cache::flush();
});

test('get returns null when flag is off even if cache has a matching key', function () {
    config(['site.public_cache_enabled' => false]);
    $site = Site::factory()->create();

    $cache = app(PublicPageCache::class);
    // Directly seed the storage to simulate stale content pre-disable.
    Cache::put("site:{$site->id}:pubcache:0:v1:p2:single", '<html>stale</html>', 60);

    expect($cache->get($site, 1, 2))->toBeNull();
});

test('put+get roundtrip returns HTML when flag is on', function () {
    config(['site.public_cache_enabled' => true, 'site.public_cache_ttl' => 60]);
    $site = Site::factory()->create();
    $cache = app(PublicPageCache::class);

    $cache->put($site, 1, 2, false, '<html>cached</html>');

    expect($cache->get($site, 1, 2))->toBe('<html>cached</html>');
});

test('stacked-mode keys are distinct from single-page keys', function () {
    config(['site.public_cache_enabled' => true]);
    $site = Site::factory()->create();
    $cache = app(PublicPageCache::class);

    $cache->put($site, 1, 2, stacked: false, html: '<html>single</html>');
    $cache->put($site, 1, 2, stacked: true, html: '<html>stacked</html>');

    expect($cache->get($site, 1, 2, stacked: false))->toBe('<html>single</html>');
    expect($cache->get($site, 1, 2, stacked: true))->toBe('<html>stacked</html>');
});

test('invalidate bumps the counter so subsequent reads miss', function () {
    config(['site.public_cache_enabled' => true]);
    $site = Site::factory()->create();
    $cache = app(PublicPageCache::class);

    $cache->put($site, 1, 2, false, '<html>v1</html>');
    expect($cache->get($site, 1, 2))->toBe('<html>v1</html>');

    $cache->invalidate($site);

    expect($cache->get($site, 1, 2))->toBeNull();
});

test('namespacedKey embeds the site counter so invalidate remaps it', function () {
    $site = Site::factory()->create();
    $cache = app(PublicPageCache::class);

    $before = $cache->namespacedKey($site, 'sitemap');
    $cache->invalidate($site);
    $after = $cache->namespacedKey($site, 'sitemap');

    expect($before)->toBe("site:{$site->id}:pubcache:0:sitemap");
    expect($after)->toBe("site:{$site->id}:pubcache:1:sitemap");
});

test('different sites do not share cache entries', function () {
    config(['site.public_cache_enabled' => true]);
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    $cache = app(PublicPageCache::class);

    $cache->put($a, 1, 2, false, '<html>A</html>');
    $cache->put($b, 1, 2, false, '<html>B</html>');

    expect($cache->get($a, 1, 2))->toBe('<html>A</html>');
    expect($cache->get($b, 1, 2))->toBe('<html>B</html>');

    $cache->invalidate($a);
    expect($cache->get($a, 1, 2))->toBeNull();
    expect($cache->get($b, 1, 2))->toBe('<html>B</html>');
});

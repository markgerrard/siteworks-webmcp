<?php

use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\SiteHostResolver;
use Illuminate\Http\Request;

it('emits Open Graph tags on a non-indexable preview host', function () {
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'preview_domain' => 'camino-og',
        'preview_brand' => 'a',
        'business_name' => 'Camino Cafe',
        'brand_og_url' => 'https://cdn.example/camino-og.png',
    ]);

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'nav_label' => 'Home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => [
            'sections' => [['type' => 'hero', 'title' => 'Camino']],
            'meta' => ['seo' => ['meta_title' => 'Camino Cafe', 'meta_description' => 'Coffee in Leeds.']],
        ],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [['page_id' => $home->id, 'revision_id' => $homeRev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    $request = Request::create('http://camino-og.d.brand-a.example/', 'GET');
    expect(app(SiteHostResolver::class)->isIndexableHost($request, $site))->toBeFalse();

    $html = $this->get('http://camino-og.d.brand-a.example/')->assertOk()->getContent();

    expect($html)->toContain('<meta property="og:image" content="https://cdn.example/camino-og.png">')
        ->and($html)->toContain('<meta property="og:site_name" content="Camino Cafe">')
        ->and($html)->toContain('<meta property="og:url" content="http://camino-og.d.brand-a.example">')
        ->and($html)->toContain('<meta name="twitter:card" content="summary_large_image">')
        ->and($html)->toContain('<meta property="og:title" content="Camino Cafe">')
        ->and($html)->toContain('<meta property="og:description" content="Coffee in Leeds.">');
});

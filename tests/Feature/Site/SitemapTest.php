<?php

use App\Enums\PageStatus;
use App\Enums\PreviewLayout;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PublicPageCache;

function sitemapLastmod(PageRevision $revision): string
{
    $timestamp = $revision->updated_at ?? $revision->created_at;

    return $timestamp->toAtomString();
}

/**
 * @param  array<int, array{page: GeneratedPage, revision: PageRevision}>  $pins
 */
function sitemapPublishVersion(Site $site, int $versionNumber, GeneratedPage $home, array $pins): SiteVersion
{
    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => $versionNumber,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => collect($pins)
            ->map(fn (array $pin) => [
                'page_id' => $pin['page']->id,
                'revision_id' => $pin['revision']->id,
            ])
            ->all(),
        'published_at' => now(),
    ]);

    SiteVersionCurrent::query()->updateOrCreate(
        ['site_id' => $site->id],
        ['version_id' => $version->id, 'updated_at' => now()],
    );

    return $version;
}

/**
 * @return array{0: Site, 1: GeneratedPage, 2: PageRevision, 3: GeneratedPage, 4: PageRevision, 5: GeneratedPage, 6: PageRevision, 7: GeneratedPage, 8: PageRevision}
 */
function sitemapMultiPageFixture(): array
{
    $site = Site::factory()->create([
        'custom_domain' => 'tiles.example',
        'custom_domain_status' => 'active',
        'preview_layout' => PreviewLayout::MultiPage,
    ]);

    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'status' => PageStatus::Published]);
    $about = GeneratedPage::factory()->for($site)->create(['page_type' => 'about', 'status' => PageStatus::Published]);
    $contact = GeneratedPage::factory()->for($site)->create(['page_type' => 'contact', 'status' => PageStatus::Published]);
    $draft = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'planning-permission-guide',
        'status' => PageStatus::Draft,
    ]);

    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
        'created_at' => now()->subDays(3)->startOfSecond(),
    ]);
    $aboutRev = PageRevision::factory()->for($about, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'About']]],
        'created_at' => now()->subDays(2)->startOfSecond(),
    ]);
    $oldAboutRev = PageRevision::factory()->for($about, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Old About']]],
        'created_at' => now()->subDays(20)->startOfSecond(),
    ]);
    $contactRev = PageRevision::factory()->for($contact, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Contact']]],
        'created_at' => now()->subDay()->startOfSecond(),
    ]);
    $draftRev = PageRevision::factory()->for($draft, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Draft guide']]],
        'created_at' => now()->startOfSecond(),
    ]);

    $home->update(['published_revision_id' => $homeRev->id]);
    $about->update(['published_revision_id' => $aboutRev->id]);
    $contact->update(['published_revision_id' => $contactRev->id]);
    $draft->update(['draft_revision_id' => $draftRev->id]);

    sitemapPublishVersion($site, 1, $home, [
        ['page' => $home, 'revision' => $homeRev],
        ['page' => $about, 'revision' => $oldAboutRev],
        ['page' => $contact, 'revision' => $contactRev],
    ]);

    sitemapPublishVersion($site, 2, $home, [
        ['page' => $home, 'revision' => $homeRev],
        ['page' => $about, 'revision' => $aboutRev],
    ]);

    return [$site, $home, $homeRev, $about, $aboutRev, $contact, $contactRev, $draft, $draftRev];
}

/**
 * @return list<string>
 */
function sitemapLocs(\Illuminate\Testing\TestResponse $response): array
{
    $xml = simplexml_load_string((string) $response->getContent());
    expect($xml)->not->toBeFalse();

    $locs = [];
    foreach ($xml->url as $url) {
        $locs[] = (string) $url->loc;
    }

    return $locs;
}

/**
 * @return array<string, string>
 */
function sitemapLastmods(\Illuminate\Testing\TestResponse $response): array
{
    $xml = simplexml_load_string((string) $response->getContent());
    expect($xml)->not->toBeFalse();

    $lastmods = [];
    foreach ($xml->url as $url) {
        $lastmods[(string) $url->loc] = (string) $url->lastmod;
    }

    return $lastmods;
}

test('published multi-page site sitemap lists exactly the current version pinned pages', function () {
    config(['site.use_versioned_renderer' => true]);

    [$site, , $homeRev, $about, $aboutRev, $contact, , $draft] = sitemapMultiPageFixture();

    $response = $this->get('http://tiles.example/sitemap.xml');

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertHeader('Cache-Tag', 'site:'.$site->id);

    $locs = sitemapLocs($response);
    expect($locs)->toEqualCanonicalizing([
        'http://tiles.example/',
        'http://tiles.example/about',
    ]);
    expect($locs)->not->toContain('http://tiles.example/contact');
    expect($locs)->not->toContain('http://tiles.example/planning-permission-guide');
    expect($locs)->not->toContain('http://tiles.example/home');

    $lastmods = sitemapLastmods($response);
    expect($lastmods['http://tiles.example/'])->toBe(sitemapLastmod($homeRev));
    expect($lastmods['http://tiles.example/about'])->toBe(sitemapLastmod($aboutRev));

    expect($response->getContent())
        ->not->toContain((string) $contact->page_type)
        ->not->toContain((string) $draft->page_type);
});

test('one-page site sitemap contains only the homepage url', function () {
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => 'onepage.example',
        'custom_domain_status' => 'active',
        'preview_layout' => PreviewLayout::OnePage,
    ]);

    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'status' => PageStatus::Published]);
    $about = GeneratedPage::factory()->for($site)->create(['page_type' => 'about', 'status' => PageStatus::Published]);

    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
        'created_at' => now()->subDay()->startOfSecond(),
    ]);
    $aboutRev = PageRevision::factory()->for($about, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'About']]],
        'created_at' => now()->startOfSecond(),
    ]);

    $home->update(['published_revision_id' => $homeRev->id]);
    $about->update(['published_revision_id' => $aboutRev->id]);

    sitemapPublishVersion($site, 1, $home, [
        ['page' => $home, 'revision' => $homeRev],
        ['page' => $about, 'revision' => $aboutRev],
    ]);

    $response = $this->get('http://onepage.example/sitemap.xml');

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'application/xml');

    expect(sitemapLocs($response))->toBe(['http://onepage.example/']);
    expect($response->getContent())->not->toContain('/about');
});

test('unpublished site sitemap returns 404', function () {
    config(['site.use_versioned_renderer' => true]);

    Site::factory()->create([
        'custom_domain' => 'draft.example',
        'custom_domain_status' => 'active',
        'preview_layout' => PreviewLayout::MultiPage,
    ]);

    $this->get('http://draft.example/sitemap.xml')->assertNotFound();
});

test('sitemap cache misses after PublicPageCache invalidation', function () {
    config([
        'site.use_versioned_renderer' => true,
        'site.public_cache_enabled' => true,
    ]);

    [$site] = sitemapMultiPageFixture();

    expect(sitemapLocs($this->get('http://tiles.example/sitemap.xml')))
        ->toEqualCanonicalizing([
            'http://tiles.example/',
            'http://tiles.example/about',
        ]);

    $v1 = SiteVersion::query()
        ->where('site_id', $site->id)
        ->where('version', 1)
        ->first();
    SiteVersionCurrent::query()
        ->where('site_id', $site->id)
        ->update(['version_id' => $v1->id]);

    expect(sitemapLocs($this->get('http://tiles.example/sitemap.xml')))
        ->toEqualCanonicalizing([
            'http://tiles.example/',
            'http://tiles.example/about',
        ]);

    app(PublicPageCache::class)->invalidate($site);

    expect(sitemapLocs($this->get('http://tiles.example/sitemap.xml')))
        ->toEqualCanonicalizing([
            'http://tiles.example/',
            'http://tiles.example/about',
            'http://tiles.example/contact',
        ]);
});

test('disabled public cache never serves a stale sitemap after current version changes', function () {
    config([
        'site.use_versioned_renderer' => true,
        'site.public_cache_enabled' => false,
    ]);

    [$site] = sitemapMultiPageFixture();

    expect(sitemapLocs($this->get('http://tiles.example/sitemap.xml')))
        ->toEqualCanonicalizing([
            'http://tiles.example/',
            'http://tiles.example/about',
        ]);

    $v1 = SiteVersion::query()
        ->where('site_id', $site->id)
        ->where('version', 1)
        ->first();
    SiteVersionCurrent::query()
        ->where('site_id', $site->id)
        ->update(['version_id' => $v1->id]);

    expect(sitemapLocs($this->get('http://tiles.example/sitemap.xml')))
        ->toEqualCanonicalizing([
            'http://tiles.example/',
            'http://tiles.example/about',
            'http://tiles.example/contact',
        ]);
});

test('sitemap.xml does not emit a Set-Cookie header', function () {
    config([
        'site.use_versioned_renderer' => true,
        'session.driver' => 'file',
    ]);

    sitemapMultiPageFixture();

    $this->get('http://tiles.example/sitemap.xml')
        ->assertSuccessful()
        ->assertHeaderMissing('Set-Cookie');
});

test('sitemap cache key and locs ignore the request Host port', function () {
    config([
        'site.use_versioned_renderer' => true,
        'site.public_cache_enabled' => true,
    ]);

    [$site] = sitemapMultiPageFixture();

    $first = $this->get('http://tiles.example:1234/sitemap.xml');
    $first->assertSuccessful();
    expect(sitemapLocs($first))->toEqualCanonicalizing([
        'http://tiles.example/',
        'http://tiles.example/about',
    ]);

    $v1 = SiteVersion::query()
        ->where('site_id', $site->id)
        ->where('version', 1)
        ->first();
    SiteVersionCurrent::query()
        ->where('site_id', $site->id)
        ->update(['version_id' => $v1->id]);

    $second = $this->get('http://tiles.example:5678/sitemap.xml');
    $second->assertSuccessful();
    expect(sitemapLocs($second))->toEqualCanonicalizing([
        'http://tiles.example/',
        'http://tiles.example/about',
    ]);
});

test('sitemap omits lastmod when the revision timestamp is null', function () {
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => 'nolastmod.example',
        'custom_domain_status' => 'active',
        'preview_layout' => PreviewLayout::MultiPage,
    ]);

    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'status' => PageStatus::Published]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
        'created_at' => now()->startOfSecond(),
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    sitemapPublishVersion($site, 1, $home, [
        ['page' => $home, 'revision' => $homeRev],
    ]);

    PageRevision::retrieved(function (PageRevision $revision): void {
        $revision->created_at = null;
    });

    $response = $this->get('http://nolastmod.example/sitemap.xml');

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'application/xml');

    expect(sitemapLocs($response))->toBe(['http://nolastmod.example/']);
    expect((string) $response->getContent())->not->toContain('<lastmod');
});

test('preview-subdomain host sitemap returns 404 even when published', function () {
    config(['site.use_versioned_renderer' => true]);

    [$site, $home, $homeRev] = sitemapMultiPageFixture();
    $site->update([
        'custom_domain' => null,
        'custom_domain_status' => null,
        'preview_domain' => 'preview-sitemap',
        'preview_brand' => 'a',
    ]);

    $this->get('http://preview-sitemap.d.brand-a.example/sitemap.xml')->assertNotFound();
});

test('pending custom domain sitemap returns 404', function () {
    config(['site.use_versioned_renderer' => true]);

    [$site] = sitemapMultiPageFixture();
    $site->update(['custom_domain_status' => 'pending']);

    $this->get('http://tiles.example/sitemap.xml')->assertNotFound();
});

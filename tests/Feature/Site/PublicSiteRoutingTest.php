<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ─── Task 4: route swap via ResolvePreviewHost middleware ────────────────────

test('flag off — custom domain request still routed to legacy PreviewController', function () {
    config(['site.use_versioned_renderer' => false]);
    $site = Site::factory()->create([
        'custom_domain' => 'tiles.example',
        'custom_domain_status' => 'active',
    ]);

    // With flag off and no preview, the middleware routes to PreviewController::showByHost
    // which returns 404 since there's no active preview — that's expected legacy behaviour.
    $this->get('http://tiles.example/')->assertNotFound();
});

test('flag on — custom domain homepage routed to PublicSiteController via middleware', function () {
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => 'tiles.example',
        'custom_domain_status' => 'active',
    ]);
    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Tile World']]],
    ]);
    $home->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => [
            'nav' => ['items' => []], 'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'], 'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [['page_id' => $home->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $this->get('http://tiles.example/')
        ->assertOk()
        ->assertSee('Tile World');
});

test('flag on — HEAD request on custom domain homepage returns 200 (matches GET)', function () {
    // Regression: middleware previously gated on `getMethod() !== 'GET'`,
    // letting HEAD fall through to the router where no '/' route exists →
    // spurious 404 for uptime monitors. HEAD must follow the same path
    // as GET; Symfony auto-strips the body.
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => 'tiles.example',
        'custom_domain_status' => 'active',
    ]);
    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Tile World']]],
    ]);
    $home->update(['published_revision_id' => $rev->id]);
    $version = SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => [
            'nav' => ['items' => []], 'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'], 'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [['page_id' => $home->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $this->call('HEAD', 'http://tiles.example/')->assertOk();
});

test('flag on — custom domain inner page routed to PublicSiteController via middleware', function () {
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => 'tiles.example',
        'custom_domain_status' => 'active',
        'preview_layout' => \App\Enums\PreviewLayout::MultiPage,
    ]);
    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $about = GeneratedPage::factory()->for($site)->create(['page_type' => 'about']);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    ]);
    $aboutRev = PageRevision::factory()->for($about, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'About Tiles']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);
    $about->update(['published_revision_id' => $aboutRev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => [
            'nav' => ['items' => []], 'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'], 'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [
            ['page_id' => $home->id, 'revision_id' => $homeRev->id],
            ['page_id' => $about->id, 'revision_id' => $aboutRev->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $this->get('http://tiles.example/about')
        ->assertOk()
        ->assertSee('About Tiles');
});

// ─── Finding #3: domain hijack prevention ────────────────────────────────────

test('preview-suffix host resolves via preview_domain even if a custom_domain row matches it', function () {
    config(['site.use_versioned_renderer' => true]);
    config(['services.cloudflare.brands.a.subdomain' => 'd']);

    // Legitimate site with a preview domain on our zone
    $legit = Site::factory()->create([
        'preview_domain' => 'legit',
        'preview_brand' => 'a',
    ]);
    $home = GeneratedPage::factory()->for($legit)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Legit Site']]],
    ]);
    $home->update(['published_revision_id' => $rev->id]);
    $version = SiteVersion::create([
        'site_id' => $legit->id, 'version' => 1,
        'composition' => [
            'nav' => ['items' => []], 'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'], 'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [['page_id' => $home->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $legit->id, 'version_id' => $version->id, 'updated_at' => now()]);

    // Attacker site that has set custom_domain to the legitimate site's preview FQDN
    Site::factory()->create([
        'custom_domain' => 'legit.d.brand-a.example',
        'custom_domain_status' => 'active',
    ]);

    // The preview-zone host must always resolve to the legitimate site
    $this->get('http://legit.d.brand-a.example/')
        ->assertOk()
        ->assertSee('Legit Site');
});

// ─── __site_v2 versioned-renderer route tests ───────────────────────────────

test('feature flag off — new public route returns 404 (legacy still serves)', function () {
    config(['site.use_versioned_renderer' => false]);
    $site = Site::factory()->create(['custom_domain' => 'flowers.example']);

    $this->get('http://flowers.example/__site_v2/')->assertNotFound();
});

test('feature flag on — new public route renders homepage from site_versions_current', function () {
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create(['custom_domain' => 'flowers.example', 'business_name' => 'Acme']);
    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome to Acme']]],
    ]);
    $home->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => [
            'nav' => ['items' => []], 'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'], 'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [['page_id' => $home->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $this->get('http://flowers.example/__site_v2/')
        ->assertOk()
        ->assertSee('Welcome to Acme');
});

// ─── Regression: brand-scoped preview_domain lookup ──────────────────────────
//
// Two sites on different brands can legitimately share the same preview_domain
// slug. The host suffix identifies the brand; the public resolver MUST filter
// by both slug + brand, else the older site always wins regardless of which
// brand the request came in on.

test('public site resolution scopes preview_domain lookup by brand', function () {
    config(['site.use_versioned_renderer' => true]);

    $mkSite = function (string $brand, string $heroTitle) {
        $site = Site::factory()->create([
            'business_name' => 'Midlands Plumbing',
            'preview_domain' => 'midlands-plumbing',
            'preview_brand' => $brand,
        ]);
        $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
        $rev = PageRevision::factory()->for($home, 'page')->create([
            'content_data' => ['sections' => [['type' => 'hero', 'title' => $heroTitle]]],
        ]);
        $home->update(['published_revision_id' => $rev->id]);
        $version = SiteVersion::create([
            'site_id' => $site->id, 'version' => 1,
            'composition' => [
                'nav' => ['items' => []], 'footer' => ['columns' => [], 'show_credit' => true],
                'theme' => ['key' => 'trades-bold'], 'homepage_page_id' => $home->id,
            ],
            'page_revisions' => [['page_id' => $home->id, 'revision_id' => $rev->id]],
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

        return $site;
    };

    $phbSite = $mkSite('b', 'PHB brand site');
    $fsSite = $mkSite('a', 'FS brand site');

    $this->get('http://midlands-plumbing.d.brand-b.example/')
        ->assertOk()
        ->assertSee('PHB brand site')
        ->assertDontSee('FS brand site');

    $this->get('http://midlands-plumbing.d.brand-a.example/')
        ->assertOk()
        ->assertSee('FS brand site')
        ->assertDontSee('PHB brand site');
});

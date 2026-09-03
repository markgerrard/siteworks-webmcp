<?php

use App\Enums\PageStatus;
use App\Enums\PreviewLayout;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;

/**
 * @param  array<int, array{page: GeneratedPage, revision: PageRevision}>  $pins
 */
function robotsPublishVersion(Site $site, GeneratedPage $home, array $pins): SiteVersion
{
    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
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

test('published site robots.txt allows crawling and points at the sitemap', function () {
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => 'robots.example',
        'custom_domain_status' => 'active',
        'preview_layout' => PreviewLayout::MultiPage,
    ]);

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    robotsPublishVersion($site, $home, [
        ['page' => $home, 'revision' => $homeRev],
    ]);

    $response = $this->get('http://robots.example:8443/robots.txt');

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeader('Cache-Tag', 'site:'.$site->id);

    $body = (string) $response->getContent();
    expect($body)->toContain("User-agent: *\n")
        ->and($body)->toContain("Allow: /\n")
        ->and($body)->toContain('Sitemap: http://robots.example/sitemap.xml')
        ->and($body)->not->toContain('/shop/p/')
        ->and($body)->not->toContain('/shop/c/');
});

test('one-page published site robots.txt is allowed and points at the sitemap', function () {
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => 'onepage-robots.example',
        'custom_domain_status' => 'active',
        'preview_layout' => PreviewLayout::OnePage,
    ]);

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'status' => PageStatus::Published,
    ]);
    $about = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'about',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    ]);
    $aboutRev = PageRevision::factory()->for($about, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'About']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);
    $about->update(['published_revision_id' => $aboutRev->id]);

    robotsPublishVersion($site, $home, [
        ['page' => $home, 'revision' => $homeRev],
        ['page' => $about, 'revision' => $aboutRev],
    ]);

    $response = $this->get('http://onepage-robots.example/robots.txt');

    $response->assertSuccessful();
    expect((string) $response->getContent())
        ->toContain('Sitemap: http://onepage-robots.example/sitemap.xml');
});

test('robots.txt does not emit a Set-Cookie header', function () {
    config([
        'site.use_versioned_renderer' => true,
        'session.driver' => 'file',
    ]);

    $site = Site::factory()->create([
        'custom_domain' => 'robots.example',
        'custom_domain_status' => 'active',
        'preview_layout' => PreviewLayout::MultiPage,
    ]);

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    robotsPublishVersion($site, $home, [
        ['page' => $home, 'revision' => $homeRev],
    ]);

    $this->get('http://robots.example/robots.txt')
        ->assertSuccessful()
        ->assertHeaderMissing('Set-Cookie');
});

test('unpublished site robots.txt returns 404', function () {
    config(['site.use_versioned_renderer' => true]);

    Site::factory()->create([
        'custom_domain' => 'draft-robots.example',
        'custom_domain_status' => 'active',
        'preview_layout' => PreviewLayout::MultiPage,
    ]);

    $this->get('http://draft-robots.example/robots.txt')->assertNotFound();
});

test('preview-subdomain host robots.txt disallows crawling even when published', function () {
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'preview_domain' => 'preview-robots',
        'preview_brand' => 'a',
        'preview_layout' => PreviewLayout::MultiPage,
    ]);

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    robotsPublishVersion($site, $home, [
        ['page' => $home, 'revision' => $homeRev],
    ]);

    $response = $this->get('http://preview-robots.d.brand-a.example/robots.txt');

    $response->assertSuccessful()
        ->assertHeader('Cache-Tag', 'site:'.$site->id);

    $body = (string) $response->getContent();
    expect($body)->toContain("Disallow: /\n")
        ->and($body)->not->toContain('Allow: /')
        ->and($body)->not->toContain('Sitemap:');
});

test('pending custom domain robots.txt disallows crawling', function () {
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => 'pending-robots.example',
        'custom_domain_status' => 'pending',
        'preview_layout' => PreviewLayout::MultiPage,
    ]);

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    robotsPublishVersion($site, $home, [
        ['page' => $home, 'revision' => $homeRev],
    ]);

    $body = (string) $this->get('http://pending-robots.example/robots.txt')
        ->assertSuccessful()
        ->getContent();

    expect($body)->toContain("Disallow: /\n")
        ->and($body)->not->toContain('Sitemap:');
});

test('custom-domain site fetched via its preview host still gets Disallow', function () {
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => 'dualhost.example',
        'custom_domain_status' => 'active',
        'preview_domain' => 'dual-host',
        'preview_brand' => 'a',
        'preview_layout' => PreviewLayout::MultiPage,
    ]);

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    robotsPublishVersion($site, $home, [
        ['page' => $home, 'revision' => $homeRev],
    ]);

    $previewBody = (string) $this->get('http://dual-host.d.brand-a.example/robots.txt')
        ->assertSuccessful()
        ->getContent();
    expect($previewBody)->toContain("Disallow: /\n")
        ->and($previewBody)->not->toContain('Sitemap:');

    $this->get('http://dual-host.d.brand-a.example/sitemap.xml')->assertNotFound();

    $customBody = (string) $this->get('http://dualhost.example/robots.txt')
        ->assertSuccessful()
        ->getContent();
    expect($customBody)->toContain("Allow: /\n")
        ->and($customBody)->toContain('Sitemap: http://dualhost.example/sitemap.xml');

    $this->get('http://dualhost.example/sitemap.xml')->assertSuccessful();
});

/**
 * @return list<string>
 */
function aiCrawlerAgents(): array
{
    return [
        'GPTBot',
        'ChatGPT-User',
        'Google-Extended',
        'Applebot-Extended',
        'CCBot',
        'PerplexityBot',
        'ClaudeBot',
        'anthropic-ai',
    ];
}

test('indexable shop site robots.txt allows named AI crawlers with the same rule as the wildcard block', function () {
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => 'ai-robots.example',
        'custom_domain_status' => 'active',
        'preview_layout' => PreviewLayout::MultiPage,
        'shop_enabled' => true,
    ]);

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    robotsPublishVersion($site, $home, [
        ['page' => $home, 'revision' => $homeRev],
    ]);

    $body = (string) $this->get('http://ai-robots.example/robots.txt')
        ->assertSuccessful()
        ->getContent();

    foreach (aiCrawlerAgents() as $agent) {
        expect($body)->toContain("User-agent: {$agent}\nAllow: /\n");
    }
    expect($body)->toContain("User-agent: *\nAllow: /\n")
        ->and($body)->toContain('Sitemap: http://ai-robots.example/sitemap.xml');
});

test('disallowed preview-subdomain robots.txt blocks named AI crawlers with the same rule as the wildcard block', function () {
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'preview_domain' => 'ai-robots-preview',
        'preview_brand' => 'a',
        'preview_layout' => PreviewLayout::MultiPage,
    ]);

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'status' => PageStatus::Published,
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);

    robotsPublishVersion($site, $home, [
        ['page' => $home, 'revision' => $homeRev],
    ]);

    $body = (string) $this->get('http://ai-robots-preview.d.brand-a.example/robots.txt')
        ->assertSuccessful()
        ->getContent();

    foreach (aiCrawlerAgents() as $agent) {
        expect($body)->toContain("User-agent: {$agent}\nDisallow: /\n");
    }
    expect($body)->toContain("User-agent: *\nDisallow: /\n")
        ->and($body)->not->toContain('Sitemap:');
});

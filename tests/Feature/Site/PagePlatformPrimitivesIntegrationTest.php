<?php

use App\Enums\PageKind;
use App\Enums\PageOrigin;
use App\Enums\PageStatus;
use App\Enums\PreviewLayout;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Services\Site\CompositionDelta;
use App\Services\Site\SitePublishService;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake([\App\Jobs\GenerateBrandImagesJob::class]);
    config(['site.use_versioned_renderer' => true]);
});

test('publishSinglePage then removePageFromVersion updates public page, footer, and sitemap', function () {
    $svc = app(SitePublishService::class);

    $site = Site::factory()->create([
        'business_name' => 'Acme',
        'theme' => 'trades-bold',
        'preview_layout' => PreviewLayout::MultiPage,
        'custom_domain' => 'primitives.example',
        'custom_domain_status' => 'active',
    ]);

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'status' => PageStatus::Published,
        'nav_label' => 'Home',
    ]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Welcome to Acme'],
        ]],
    ]);
    $home->update(['draft_revision_id' => $homeRev->id]);

    $svc->publishSite($site);

    $guide = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'planning-permission-guide',
        'status' => PageStatus::Draft,
        'kind' => PageKind::Guide,
        'origin' => PageOrigin::Managed,
        'nav_label' => 'Planning Guide',
    ]);
    $guideRev = PageRevision::factory()->for($guide, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Planning permission'],
        ]],
    ]);
    $guide->update(['draft_revision_id' => $guideRev->id]);

    $sibling = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'cost-guide-loft',
        'status' => PageStatus::Draft,
        'kind' => PageKind::CostGuide,
        'origin' => PageOrigin::Managed,
        'nav_label' => 'Loft Costs',
    ]);
    $siblingRev = PageRevision::factory()->for($sibling, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Loft conversion costs'],
        ]],
    ]);
    $sibling->update(['draft_revision_id' => $siblingRev->id]);

    $delta = new CompositionDelta(
        footerColumnEntries: [['column' => 'Guides & Advice', 'page_id' => $guide->id]],
    );

    $published = $svc->publishSinglePage($site, $guide, $delta);
    expect($published->version)->toBe(2);

    $guide->refresh();
    expect($guide->status)->toBe(PageStatus::Published)
        ->and($guide->kind)->toBe(PageKind::Guide)
        ->and($guide->origin)->toBe(PageOrigin::Managed);

    $sibling->refresh();
    expect($sibling->status)->toBe(PageStatus::Draft)
        ->and($sibling->draft_revision_id)->toBe($siblingRev->id)
        ->and($sibling->published_revision_id)->toBeNull();

    $this->get('http://primitives.example/planning-permission-guide')
        ->assertSuccessful()
        ->assertSee('Planning permission');

    $homeHtml = $this->get('http://primitives.example/')->assertSuccessful()->getContent();
    expect($homeHtml)->toContain('data-footer-column="Guides &amp; Advice"')
        ->and($homeHtml)->toContain('href="/planning-permission-guide"')
        ->and($homeHtml)->toContain('Planning Guide');

    $sitemap = $this->get('http://primitives.example/sitemap.xml')->assertSuccessful()->getContent();
    expect($sitemap)->toContain('http://primitives.example/planning-permission-guide')
        ->and($sitemap)->not->toContain('cost-guide-loft');

    $removed = $svc->removePageFromVersion($site, $guide, $delta);
    expect($removed->version)->toBe(3);

    $guide->refresh();
    expect($guide->status)->toBe(PageStatus::Draft);

    $sibling->refresh();
    expect($sibling->status)->toBe(PageStatus::Draft)
        ->and($sibling->draft_revision_id)->toBe($siblingRev->id);

    $this->get('http://primitives.example/planning-permission-guide')->assertNotFound();

    $homeAfter = $this->get('http://primitives.example/')->assertSuccessful()->getContent();
    expect($homeAfter)->not->toContain('data-footer-column="Guides &amp; Advice"')
        ->and($homeAfter)->not->toContain('href="/planning-permission-guide"')
        ->and($homeAfter)->not->toContain('Planning Guide');

    $sitemapAfter = $this->get('http://primitives.example/sitemap.xml')->assertSuccessful()->getContent();
    expect($sitemapAfter)->not->toContain('planning-permission-guide');
});

<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

function setupDisplayScaleSite(array $compositionTheme = []): array
{
    $site = Site::factory()->create([
        'business_name' => 'Acme',
        'theme' => 'trades-bold',
        'design_brief' => null,
    ]);

    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => [
            'sections' => [
                ['type' => 'hero', 'title' => 'Welcome', 'subtitle' => 'Professional services'],
            ],
        ],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => array_merge(['key' => 'trades-bold'], $compositionTheme),
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return [$site, $page, $version];
}

test('grand display_scale emits the wider container, raised hero cap, and stepped chrome padding', function () {
    [$site, $page] = setupDisplayScaleSite(['display_scale_override' => 'grand']);

    $html = app(PageRenderer::class)->render($site, $page->id, mode: 'public');

    expect($html)->toContain('--container-width: 1680px')
        ->toContain('--section-spacing: 8rem')
        ->toContain('text-[clamp(1.875rem,3.5vw,4.5rem)]')
        ->toContain('site-shell-container px-4 sm:px-6 lg:px-8')
        ->not->toContain('lg:px-12')
        ->toContain('padding-top: 8px; padding-bottom: 8px;')
        ->toContain('data-display-scale="grand"')
        ->toContain('@media (min-width: 1280px) { body[data-display-scale="grand"] .site-shell-container { padding-left: 4rem; padding-right: 4rem; } }')
        ->not->toContain('text-[clamp(1.875rem,3.5vw,3.75rem)]')
        ->not->toContain('--container-width: 1280px');
});

test('flipping display_scale back to standard restores the pre-opt-in render', function () {
    [$site, $page, $version] = setupDisplayScaleSite();
    $renderer = app(PageRenderer::class);

    $baseline = $renderer->render($site, $page->id, mode: 'public');

    $composition = $version->composition;
    $composition['theme']['display_scale_override'] = 'grand';
    $version->update(['composition' => $composition]);

    $grand = $renderer->render($site->fresh(), $page->id, mode: 'public');

    unset($composition['theme']['display_scale_override']);
    $version->update(['composition' => $composition]);

    $restored = $renderer->render($site->fresh(), $page->id, mode: 'public');

    expect($grand)->not->toBe($baseline)
        ->and($grand)->toContain('--container-width: 1680px')
        ->and($restored)->toBe($baseline)
        ->and($baseline)->toContain('--container-width: 1280px')
        ->and($baseline)->toContain('text-[clamp(1.875rem,3.5vw,3.75rem)]')
        ->and($baseline)->toContain('site-shell-container px-4 sm:px-6 lg:px-8')
        ->and($baseline)->not->toContain('padding-top: 8px; padding-bottom: 8px;')
        ->and($baseline)->not->toContain('data-display-scale')
        ->and($baseline)->not->toContain('body[data-display-scale');
});

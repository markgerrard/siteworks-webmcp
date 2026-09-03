<?php

use App\Enums\PageStatus;
use App\Enums\PreviewLayout;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

test('layout context drops nested type=shop nav items when the flag is off without mutating composition', function () {
    $site = Site::factory()->shopDisabled()->create([
        'preview_layout' => PreviewLayout::MultiPage,
    ]);
    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'status' => PageStatus::Published,
        'nav_label' => 'Home',
    ]);
    $rev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
    ]);
    $home->update(['published_revision_id' => $rev->id]);

    $nav = [
        ['type' => 'page', 'page_id' => $home->id, 'label' => 'Home'],
        ['type' => 'shop', 'label' => 'Shop'],
        [
            'type' => 'group',
            'label' => 'More',
            'children' => [
                ['type' => 'shop', 'label' => 'Store'],
                ['type' => 'page', 'page_id' => $home->id, 'label' => 'Also home'],
            ],
        ],
    ];

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => $nav],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [['page_id' => $home->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    $ctx = app(PageRenderer::class)->layoutContext($site->fresh());
    $types = collect($ctx['navItems'])->pluck('type')->all();
    $childTypes = collect($ctx['navItems'])
        ->firstWhere('type', 'group')['children'] ?? [];

    $stored = $version->fresh()->composition['nav']['items'];
    $storedGroupChildren = collect($stored)->firstWhere('type', 'group')['children'] ?? [];

    expect($types)->not->toContain('shop')
        ->and(collect($childTypes)->pluck('type')->all())->not->toContain('shop')
        ->and(collect($childTypes)->pluck('label')->all())->toContain('Also home')
        ->and(collect($stored)->pluck('type')->all())->toContain('shop')
        ->and(collect($storedGroupChildren)->pluck('type')->all())->toContain('shop')
        ->and(collect($storedGroupChildren))->toHaveCount(2);
});

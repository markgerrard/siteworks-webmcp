<?php

use App\Enums\PageStatus;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedLegacySiteWithSnapshot(array $snapshotFlags, array $profileFlags = []): Site
{
    $site = Site::factory()->create();
    BusinessProfile::create(['site_id' => $site->id, 'profile_data' => $profileFlags]);

    $content = ['sections' => [['type' => 'hero', 'title' => 'Home']]];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => $content,
        'sort_order' => 0,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $rev = PageRevision::create(['page_id' => $page->id, 'content_data' => $content, 'ai_generated' => false, 'created_at' => now()]);
    $page->update(['published_revision_id' => $rev->id]);

    Preview::factory()->create([
        'site_id' => $site->id,
        'snapshot' => array_merge(['pages' => []], $snapshotFlags),
    ]);

    return $site;
}

test('PageRenderer falls back to Preview.snapshot for display flags missing on BusinessProfile', function () {
    // Legacy site: snapshot has hero_sizes but profile doesn't.
    $site = seedLegacySiteWithSnapshot([
        'hero_sizes' => ['home' => '65vh', 'inner' => '25vh'],
        'watermark_enabled' => false,
        'contact_form_enabled' => false,
        'top_bar_enabled' => false,
    ]);

    $html = app(PageRenderer::class)->render($site, $site->generatedPages()->first()->id, mode: 'admin-preview');

    // hero_sizes[home]=65vh → hero style="height: 65vh;"
    expect($html)->toContain('height: 65vh');
    // watermark_enabled=false + contact_form_enabled=false + top_bar_enabled=false
    // all flow through; can't assert visually without a stable fixture. Minimal
    // assertion: render completes (no fatal from array-key-missing on older sites).
});

test('PageRenderer prefers BusinessProfile over snapshot when both set', function () {
    $site = seedLegacySiteWithSnapshot(
        snapshotFlags: ['hero_sizes' => ['home' => '25vh', 'inner' => '25vh']],
        profileFlags: ['hero_sizes' => ['home' => '65vh', 'inner' => '45vh']],
    );

    $html = app(PageRenderer::class)->render($site, $site->generatedPages()->first()->id, mode: 'admin-preview');

    expect($html)->toContain('height: 65vh');
    expect($html)->not->toContain('height: 25vh');
});

test('PageRenderer uses hardcoded default when neither profile nor snapshot has the key', function () {
    $site = seedLegacySiteWithSnapshot([]);

    $html = app(PageRenderer::class)->render($site, $site->generatedPages()->first()->id, mode: 'admin-preview');

    // Default for home hero is 55vh (per hero.blade.php fallback chain)
    expect($html)->toContain('height: 55vh');
});

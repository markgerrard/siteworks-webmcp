<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->staff = User::factory()->staff(AgentRole::Admin)->create());

function seedSiteWithHero(array $initialPlacement = []): Site
{
    $site = Site::factory()->create();
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
    Preview::factory()->create(['site_id' => $site->id]);

    HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'url' => 'https://example.test/hero.jpg',
        'watermark_url' => null,
        'prompt' => 'test',
        'model' => 'test',
        'placement' => $initialPlacement,
        'is_active' => true,
    ]);

    return $site;
}

test('setTextZone updates the active HeroVersion.placement (versioned renderer path)', function () {
    $site = seedSiteWithHero(['text_zone' => 'middle-left']);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('setTextZone', 'home', 'top-right')
        ->assertDispatched('composition-dirty');

    $active = HeroVersion::where('site_id', $site->id)->where('is_active', true)->first();
    expect($active->placement['text_zone'])->toBe('top-right');
    expect($active->placement['overlay_direction'])->toBe('to-b');
});

test('setTextZone preserves other placement fields (only patches provided keys)', function () {
    $site = seedSiteWithHero([
        'text_zone' => 'middle-left',
        'bg_position_y' => 40,
        'text_color' => 'white',
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('setTextZone', 'home', 'bottom-right');

    $active = HeroVersion::where('site_id', $site->id)->where('is_active', true)->first();
    expect($active->placement['text_zone'])->toBe('bottom-right');
    expect($active->placement['bg_position_y'])->toBe(40);  // preserved
    expect($active->placement['text_color'])->toBe('white'); // preserved
});

test('setHeroCropY updates the active HeroVersion.placement', function () {
    $site = seedSiteWithHero(['text_zone' => 'middle-center']);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('setHeroCropY', 'home', 75)
        ->assertDispatched('composition-dirty');

    $active = HeroVersion::where('site_id', $site->id)->where('is_active', true)->first();
    expect($active->placement['bg_position_y'])->toBe(75);
});

test('activateHeroVersion flips is_active (versioned renderer source of truth)', function () {
    $site = seedSiteWithHero(['text_zone' => 'middle-center']);
    // Seed a second inactive version
    $second = HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'url' => 'https://example.test/alt.jpg',
        'watermark_url' => null,
        'prompt' => 't2',
        'model' => 't2',
        'placement' => [],
        'is_active' => false,
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('activateHeroVersion', $second->id);

    expect(HeroVersion::find($second->id)->is_active)->toBeTrue();
    // One active per (site, page_type, slot) — the slot model allows a hero
    // and an intro to coexist, but only one of each per page_type.
    $activeCount = HeroVersion::where('site_id', $site->id)
        ->where('page_type', 'home')
        ->where('slot', 'hero')
        ->where('is_active', true)
        ->count();
    expect($activeCount)->toBe(1);
});

test('setHeroCropY clamps value to 0-100', function () {
    $site = seedSiteWithHero();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('setHeroCropY', 'home', 150)
        ->call('setHeroCropY', 'home', -20);

    $active = HeroVersion::where('site_id', $site->id)->where('is_active', true)->first();
    // After the second call, expect clamped to 0
    expect($active->placement['bg_position_y'])->toBe(0);
});

test('setTextZone keeps the snapshot hero_images entry seeded with url after first patch', function () {
    // Regression: when the snapshot has no hero_images entry for a given
    // page_type (service pages, projects, anything not seeded by
    // BuildPreviewJob), the first text-zone/crop click used to write a
    // partial entry containing only the patch field. On the next render
    // the page-manager loop saw the entry and skipped merging url from
    // the active HeroVersion — admin thumbnail went blank, picker
    // disappeared. Fix: snapshot mutator seeds url + watermark from the
    // active HeroVersion when creating a new entry.
    $site = Site::factory()->create();
    $content = ['sections' => [['type' => 'hero', 'title' => 'Service']]];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'roof-repairs',
        'content_data' => $content,
        'sort_order' => 1,
        'version' => 1,
        'status' => PageStatus::Published,
        'hero_source' => 'dedicated',
    ]);
    $rev = PageRevision::create(['page_id' => $page->id, 'content_data' => $content, 'ai_generated' => false, 'created_at' => now()]);
    $page->update(['published_revision_id' => $rev->id]);

    // Preview without any hero_images snapshot — mirrors a service page
    // generated AFTER initial BuildPreviewJob (snapshot only ever seeded
    // for core pages).
    $preview = Preview::factory()->create([
        'site_id' => $site->id,
        'snapshot' => ['hero_images' => []],
    ]);

    HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'roof-repairs',
        'url' => 'https://example.test/roof.jpg',
        'watermark_url' => 'https://example.test/roof-wm.jpg',
        'prompt' => 'test',
        'model' => 'test',
        'placement' => ['text_zone' => 'middle-left'],
        'is_active' => true,
        'slot' => 'hero',
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('setTextZone', 'roof-repairs', 'top-right');

    $snap = $preview->fresh()->snapshot;
    expect($snap['hero_images']['roof-repairs']['url'])->toBe('https://example.test/roof.jpg');
    expect($snap['hero_images']['roof-repairs']['watermark_url'])->toBe('https://example.test/roof-wm.jpg');
    // Placement keys live nested under ['placement'] — writing them at the
    // top level would let an edit be silently shadowed by the snapshot's
    // stale nested values.
    expect($snap['hero_images']['roof-repairs']['placement']['text_zone'])->toBe('top-right');
});

// ── fallback badge when no own-page HeroVersion exists ──────────────

test('shows fallback badge and regen button when core page has no own-page HeroVersion but shared hero exists', function () {
    $site = Site::factory()->create();
    $content = ['sections' => [['type' => 'hero', 'title' => 'About']]];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'content_data' => $content,
        'sort_order' => 1,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $rev = PageRevision::create(['page_id' => $page->id, 'content_data' => $content, 'ai_generated' => false, 'created_at' => now()]);
    $page->update(['published_revision_id' => $rev->id]);
    Preview::factory()->create(['site_id' => $site->id]);

    // No own-page HeroVersion for 'about'. Shared service hero exists.
    HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => '__shared_service_hero',
        'url' => 'https://example.test/shared.jpg',
        'watermark_url' => null,
        'prompt' => 'shared',
        'model' => 'demo',
        'placement' => [],
        'is_active' => true,
        'slot' => 'hero',
    ]);

    $component = Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id]);

    // The fallback badge text should appear
    $component->assertSeeHtml('Using site-wide hero');
    // The regen button should be present
    $component->assertSeeHtml("regenerateHero('about')");
});

test('does NOT show fallback badge when own-page HeroVersion exists', function () {
    $site = Site::factory()->create();
    $content = ['sections' => [['type' => 'hero', 'title' => 'About']]];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'content_data' => $content,
        'sort_order' => 1,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $rev = PageRevision::create(['page_id' => $page->id, 'content_data' => $content, 'ai_generated' => false, 'created_at' => now()]);
    $page->update(['published_revision_id' => $rev->id]);
    Preview::factory()->create(['site_id' => $site->id]);

    // Own-page hero exists — no fallback.
    HeroVersion::create([
        'site_id' => $site->id,
        'page_type' => 'about',
        'url' => 'https://example.test/about-hero.jpg',
        'watermark_url' => null,
        'prompt' => 'about hero',
        'model' => 'demo',
        'placement' => [],
        'is_active' => true,
        'slot' => 'hero',
    ]);

    $component = Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id]);

    $component->assertDontSeeHtml('Using site-wide hero');
});

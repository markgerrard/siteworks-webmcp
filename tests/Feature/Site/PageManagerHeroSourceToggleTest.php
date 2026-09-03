<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Models\User;
use Livewire\Livewire;

function makeHeroSourcePage(int $siteId, string $type, string $heroSource = 'shared'): GeneratedPage
{
    $page = GeneratedPage::create([
        'site_id' => $siteId,
        'page_type' => $type,
        'content_data' => [],
        'sort_order' => 0,
        'version' => 1,
        'status' => PageStatus::Published,
        'hero_source' => $heroSource,
    ]);

    $preview = Preview::where('site_id', $siteId)->first()
        ?? Preview::factory()->create(['site_id' => $siteId]);
    $snapshot = $preview->snapshot ?? [];
    $snapshot['pages'][$type] = [];
    $preview->update(['snapshot' => $snapshot]);

    return $page;
}

beforeEach(function () {
    $this->staff = User::factory()->staff(AgentRole::Admin)->create();
});

test('updateHeroSource flips a service page from shared to dedicated', function () {
    $site = Site::factory()->create();
    $page = makeHeroSourcePage($site->id, 'roofing', 'shared');

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('updateHeroSource', 'roofing', 'dedicated');

    expect($page->fresh()->hero_source)->toBe('dedicated');
});

test('updateHeroSource bumps admin_revision for service page hero source changes', function () {
    $site = Site::factory()->create();
    makeHeroSourcePage($site->id, 'roofing', 'shared');
    $before = (int) (SiteDraft::where('site_id', $site->id)->value('admin_revision') ?? 0);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('updateHeroSource', 'roofing', 'dedicated');

    $after = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');

    expect($after)->toBe($before + 1);
});

test('updateHeroSource ignores core pages and invalid values', function () {
    $site = Site::factory()->create();
    $home = makeHeroSourcePage($site->id, 'home', 'shared');
    $service = makeHeroSourcePage($site->id, 'roofing', 'shared');

    $component = Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id]);

    $component->call('updateHeroSource', 'home', 'dedicated');
    $component->call('updateHeroSource', 'roofing', 'quantum');

    expect($home->fresh()->hero_source)->toBe('shared')
        ->and($service->fresh()->hero_source)->toBe('shared');
});

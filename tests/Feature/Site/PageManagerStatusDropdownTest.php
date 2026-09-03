<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeStatusDropdownPage(int $siteId, string $type, PageStatus $status = PageStatus::Published): GeneratedPage
{
    $gp = GeneratedPage::create([
        'site_id' => $siteId,
        'page_type' => $type,
        'content_data' => [],
        'sort_order' => 0,
        'version' => 1,
        'status' => $status,
    ]);
    // page-manager tabs read from Preview.snapshot; mirror content
    $preview = Preview::where('site_id', $siteId)->first()
        ?? Preview::factory()->create(['site_id' => $siteId]);
    $snap = $preview->snapshot ?? [];
    $snap['pages'][$type] = [];
    $preview->update(['snapshot' => $snap]);

    return $gp;
}

beforeEach(function () {
    $this->staff = User::factory()->staff(AgentRole::Admin)->create();
});

test('updatePageStatus flips Published → Draft', function () {
    $site = Site::factory()->create();
    $page = makeStatusDropdownPage($site->id, 'landlord-certs', PageStatus::Published);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('updatePageStatus', 'landlord-certs', 'draft');

    expect($page->fresh()->status)->toBe(PageStatus::Draft);
});

test('updatePageStatus Published → Archived sets archived_at via observer', function () {
    $site = Site::factory()->create();
    $page = makeStatusDropdownPage($site->id, 'old-page', PageStatus::Published);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('updatePageStatus', 'old-page', 'archived');

    $fresh = $page->fresh();
    expect($fresh->status)->toBe(PageStatus::Archived);
    expect($fresh->archived_at)->not->toBeNull();
});

test('updatePageStatus Archived → Draft clears archived_at', function () {
    $site = Site::factory()->create();
    $page = makeStatusDropdownPage($site->id, 'retired', PageStatus::Archived);
    // observer would have set archived_at; confirm baseline
    expect($page->fresh()->archived_at)->not->toBeNull();

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('updatePageStatus', 'retired', 'draft');

    $fresh = $page->fresh();
    expect($fresh->status)->toBe(PageStatus::Draft);
    expect($fresh->archived_at)->toBeNull();
});

test('updatePageStatus bumps admin_revision on the site draft', function () {
    $site = Site::factory()->create();
    makeStatusDropdownPage($site->id, 'p', PageStatus::Published);
    // Pre-bump state
    $before = (int) (SiteDraft::where('site_id', $site->id)->value('admin_revision') ?? 0);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('updatePageStatus', 'p', 'draft');

    $after = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');
    expect($after)->toBe($before + 1);
});

test('updatePageStatus is a no-op when target equals current (no revision bump)', function () {
    $site = Site::factory()->create();
    makeStatusDropdownPage($site->id, 'p', PageStatus::Published);
    $draftBefore = (int) (SiteDraft::where('site_id', $site->id)->value('admin_revision') ?? 0);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('updatePageStatus', 'p', 'published');

    $after = (int) (SiteDraft::where('site_id', $site->id)->value('admin_revision') ?? 0);
    expect($after)->toBe($draftBefore);
});

test('updatePageStatus rejects an invalid status string and leaves the page unchanged', function () {
    $site = Site::factory()->create();
    $page = makeStatusDropdownPage($site->id, 'p', PageStatus::Published);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('updatePageStatus', 'p', 'quantum');

    expect($page->fresh()->status)->toBe(PageStatus::Published);
    // admin_revision should NOT bump for invalid input
    $rev = (int) (SiteDraft::where('site_id', $site->id)->value('admin_revision') ?? 0);
    expect($rev)->toBe(0);
});

test('updatePageStatus ignores non-existent page gracefully', function () {
    $site = Site::factory()->create();
    makeStatusDropdownPage($site->id, 'home', PageStatus::Published);

    // Should not throw, should not bump
    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('updatePageStatus', 'nope', 'draft');

    $after = (int) (SiteDraft::where('site_id', $site->id)->value('admin_revision') ?? 0);
    expect($after)->toBe(0);
});

test('all state-machine transitions work through the Livewire action', function () {
    $site = Site::factory()->create();
    $page = makeStatusDropdownPage($site->id, 'p', PageStatus::Published);

    $component = Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id]);

    // Published → Draft
    $component->call('updatePageStatus', 'p', 'draft');
    expect($page->fresh()->status)->toBe(PageStatus::Draft);

    // Draft → Published
    $component->call('updatePageStatus', 'p', 'published');
    expect($page->fresh()->status)->toBe(PageStatus::Published);

    // Published → Archived
    $component->call('updatePageStatus', 'p', 'archived');
    expect($page->fresh()->status)->toBe(PageStatus::Archived);
    expect($page->fresh()->archived_at)->not->toBeNull();

    // Archived → Published
    $component->call('updatePageStatus', 'p', 'published');
    expect($page->fresh()->status)->toBe(PageStatus::Published);
    expect($page->fresh()->archived_at)->toBeNull();

    // Published → Archived again, then Archived → Draft
    $component->call('updatePageStatus', 'p', 'archived');
    $component->call('updatePageStatus', 'p', 'draft');
    expect($page->fresh()->status)->toBe(PageStatus::Draft);
    expect($page->fresh()->archived_at)->toBeNull();
});

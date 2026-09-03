<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    $this->staff = User::factory()->staff(AgentRole::Admin)->create();
});

function setupSuggestedServicesSite(array $pending = []): Site
{
    $site = Site::factory()->create([
        'location' => 'Bristol',
        'admin_suggestions' => ['pending_services' => $pending],
    ]);
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['geo' => ['scope' => 'local']],
    ]);
    Preview::factory()->create(['site_id' => $site->id]);

    GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => [],
        'sort_order' => 0,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);

    return $site;
}

test('suggested services render as chips when admin_suggestions.pending_services is non-empty', function () {
    $site = setupSuggestedServicesSite([
        ['name' => 'Solar PV', 'confidence' => 0.74, 'suggested_at' => '2026-04-22T10:00:00Z'],
        ['name' => 'EV Chargers', 'confidence' => 0.62, 'suggested_at' => '2026-04-22T10:00:00Z'],
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->assertSee('Suggested service pages')
        ->assertSee('Solar PV')
        ->assertSee('EV Chargers')
        ->assertSee('2 pending');
});

test('mount with no pending_services renders nothing for suggestions card', function () {
    $site = setupSuggestedServicesSite([]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->assertDontSee('Suggested service pages');
});

test('addSuggestedService removes the entry from admin_suggestions and dispatches the batch', function () {
    Bus::fake();
    $site = setupSuggestedServicesSite([
        ['name' => 'Solar PV', 'confidence' => 0.74, 'suggested_at' => '2026-04-22T10:00:00Z'],
        ['name' => 'EV Chargers', 'confidence' => 0.62, 'suggested_at' => '2026-04-22T10:00:00Z'],
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('addSuggestedService', 0);

    $remaining = $site->fresh()->admin_suggestions['pending_services'];
    expect($remaining)->toHaveCount(1);
    expect($remaining[0]['name'])->toBe('EV Chargers');

    Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $batch) {
        return $batch->name === "service-pages-site-".$batch->jobs->first()->site->id;
    });
});

test('addSuggestedService with an invalid index is a no-op', function () {
    Bus::fake();
    $site = setupSuggestedServicesSite([
        ['name' => 'Solar PV', 'confidence' => 0.74, 'suggested_at' => '2026-04-22T10:00:00Z'],
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('addSuggestedService', 99);

    expect($site->fresh()->admin_suggestions['pending_services'])->toHaveCount(1);
    Bus::assertNothingBatched();
});

test('addSuggestedService preserves other keys under admin_suggestions', function () {
    Bus::fake();
    $site = setupSuggestedServicesSite([
        ['name' => 'Solar PV', 'confidence' => 0.74, 'suggested_at' => '2026-04-22T10:00:00Z'],
    ]);
    $site->update(['admin_suggestions' => array_merge(
        $site->admin_suggestions ?? [],
        ['other_key' => 'preserved'],
    )]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('addSuggestedService', 0);

    $suggestions = $site->fresh()->admin_suggestions;
    expect($suggestions['pending_services'])->toBe([]);
    expect($suggestions['other_key'])->toBe('preserved');
});

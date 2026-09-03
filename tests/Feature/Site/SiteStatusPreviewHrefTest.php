<?php

use App\Enums\AgentRole;
use App\Enums\SiteStatus;
use App\Models\Preview;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Admin bypasses the agent per-site ownership check; keeps tests focused
    // on the preview-href logic rather than ACL wiring.
    $this->staff = User::factory()->staff(AgentRole::Admin)->create();
});

test('preview href uses branded preview hostname when available', function () {
    $site = Site::factory()->create([
        'preview_domain' => 'acme-plumbers',
        'preview_brand' => 'a',
        'status' => SiteStatus::Published,
    ]);
    Preview::factory()->create(['site_id' => $site->id, 'slug' => 'acme-plumbers-abc123']);

    Livewire::actingAs($this->staff)
        ->test('site-status', ['siteId' => $site->id])
        ->assertSet('previewHref', fn ($v) => str_starts_with($v, 'https://acme-plumbers.'))
        ->assertSet('previewDisplayUrl', fn ($v) => str_starts_with($v, 'acme-plumbers.'));
});

test('preview href uses custom domain when active', function () {
    $site = Site::factory()->create([
        'preview_domain' => 'acme-plumbers',
        'preview_brand' => 'a',
        'custom_domain' => 'acme-plumbers.co.uk',
        'custom_domain_status' => 'active',
        'status' => SiteStatus::Published,
    ]);
    Preview::factory()->create(['site_id' => $site->id, 'slug' => 'acme-plumbers-abc123']);

    Livewire::actingAs($this->staff)
        ->test('site-status', ['siteId' => $site->id])
        ->assertSet('previewHref', 'https://acme-plumbers.co.uk/_edit/view-live')
        ->assertSet('previewDisplayUrl', 'acme-plumbers.co.uk');
});

test('custom domain that is NOT active is ignored (falls through to preview host)', function () {
    $site = Site::factory()->create([
        'preview_domain' => 'acme-plumbers',
        'preview_brand' => 'a',
        'custom_domain' => 'acme-plumbers.co.uk',
        'custom_domain_status' => 'pending',
        'status' => SiteStatus::Published,
    ]);
    Preview::factory()->create(['site_id' => $site->id, 'slug' => 'acme-plumbers-abc123']);

    Livewire::actingAs($this->staff)
        ->test('site-status', ['siteId' => $site->id])
        ->assertSet('previewHref', fn ($v) => str_starts_with($v, 'https://acme-plumbers.'))
        ->assertSet('previewDisplayUrl', fn ($v) => str_starts_with($v, 'acme-plumbers.'));
});

test('falls back to legacy /preview/{slug} when no preview_domain and no custom_domain', function () {
    // Site::booted() auto-allocates a preview_domain when business_name is
    // set, so construct the row without it then clear the auto-filled slug.
    $site = Site::factory()->create(['custom_domain' => null, 'status' => SiteStatus::Published]);
    $site->preview_domain = null;
    $site->save();

    Preview::factory()->create(['site_id' => $site->id, 'slug' => 'legacy-slug']);

    Livewire::actingAs($this->staff)
        ->test('site-status', ['siteId' => $site->id])
        ->assertSet('previewHref', route('preview.show', 'legacy-slug'));
});

test('preview href is null when no latestPreview exists yet (site still scraping)', function () {
    $site = Site::factory()->create([
        'preview_domain' => 'acme-plumbers',
        'preview_brand' => 'a',
        'status' => SiteStatus::Scraping,
    ]);

    Livewire::actingAs($this->staff)
        ->test('site-status', ['siteId' => $site->id])
        ->assertSet('previewHref', null)
        ->assertSet('previewDisplayUrl', null);
});

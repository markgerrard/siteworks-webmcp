<?php

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->customerHost = config('domains.customer_domain');
    $this->mineClient = Client::factory()->create();
    $this->me = User::factory()->create([
        'client_id' => $this->mineClient->id,
        'role' => null,
        'last_login_at' => now(),
    ]);
    $this->theirClient = Client::factory()->create();
    $this->theirSite = Site::factory()->create([
        'client_id' => $this->theirClient->id,
        'native_reviews_enabled' => true,
    ]);
});

dataset('portal_section_paths', [
    'site (canonical Pages)' => fn () => '',
    'design' => fn () => '/design',
    'navigation' => fn () => '/navigation',
    'personalise' => fn () => '/personalise',
    'chatbot' => fn () => '/chatbot',
    'history' => fn () => '/history',
    'reviews' => fn () => '/reviews',
    'enquiries' => fn () => '/enquiries',
    'business-info' => fn () => '/business-info',
    'domain' => fn () => '/domain',
]);

test('client cannot access another clients site portal sections', function (string $suffix) {
    config(['site.use_versioned_renderer' => true]);
    config(['site.native_reviews_enabled' => true]);

    $this->actingAs($this->me)
        ->get("https://{$this->customerHost}/sites/{$this->theirSite->id}{$suffix}")
        ->assertForbidden();
})->with('portal_section_paths');

test('cross-tenant reviews access is forbidden even when the foreign site has reviews disabled', function () {
    // authorize() must run BEFORE the per-site feature gate — a 404/403
    // split here would leak the foreign tenant's native_reviews_enabled
    // flag to anyone enumerating site ids.
    config(['site.native_reviews_enabled' => true]);
    $this->theirSite->update(['native_reviews_enabled' => false]);

    $this->actingAs($this->me)
        ->get("https://{$this->customerHost}/sites/{$this->theirSite->id}/reviews")
        ->assertForbidden();
});

test('staff users cannot reach client portal sections (client.only blocks them)', function () {
    $staff = User::factory()->create(['role' => \App\Enums\AgentRole::Agent]);

    $this->actingAs($staff)
        ->get("https://{$this->customerHost}/sites/{$this->theirSite->id}")
        ->assertRedirect();
});

test('unauthenticated requests redirect to login', function () {
    $this->get("https://{$this->customerHost}/sites/{$this->theirSite->id}")
        ->assertRedirect();
});

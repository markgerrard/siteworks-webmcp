<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// TODO(sso-future): remove when site management routes move to agent domain.
beforeEach(function () {
    $this->withoutMiddleware(\App\Http\Middleware\EnsureClientUser::class);
});

test('admin preview route renders admin-preview mode for authorised user', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Preview']]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $response = $this->actingAs($user)
        ->get(route('site.admin.preview', ['site' => $site->id, 'page' => $page->id]))
        ->assertOk()
        ->assertSee('Preview');

    // Look for the attribute form (leading space) not the substring — the page's
    // inline rich-text fallback CSS uses [data-editable-type="rich"] selectors.
    expect($response->getContent())->not->toMatch('/\s+data-editable="/');
});

test('admin preview ignores edit query and stays read-only', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create();
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Edit me']]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $response = $this->actingAs($user)
        ->get(route('site.admin.preview', ['site' => $site->id, 'page' => $page->id, 'edit' => 1]))
        ->assertOk()
        ->assertSee('Edit me');

    expect($response->getContent())->not->toMatch('/\s+data-editable="/');
});

test('admin preview with edit query does not reflect XSS payload in business_name', function () {
    $user = User::factory()->staff()->create();
    $xssName = '<img src=x onerror=alert(1)>';
    $site = Site::factory()->create(['created_by_user_id' => $user->id, 'business_name' => $xssName]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Safe']]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $response = $this->actingAs($user)
        ->get(route('site.admin.preview', ['site' => $site->id, 'page' => $page->id, 'edit' => 1]))
        ->assertOk();

    // The raw XSS string must not appear unescaped in the response body.
    expect($response->getContent())->not->toContain('<img src=x onerror');
});

test('admin preview rejects non-staff users at the middleware layer', function () {
    // The route is on the agent subdomain wrapped in agent.only, so a
    // non-staff user never reaches the controller; they're redirected
    // to the agent login. (Previously this asserted 403 from the policy
    // layer, pre-domain-pinning.)
    $client = User::factory()->create(['role' => null]);
    $site = Site::factory()->create(['client_id' => null]);
    $page = GeneratedPage::factory()->for($site)->create();

    $this->actingAs($client)
        ->get(route('site.admin.preview', ['site' => $site->id, 'page' => $page->id]))
        ->assertRedirect(route('agent.login'));
});

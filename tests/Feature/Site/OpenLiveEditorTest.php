<?php

use App\Http\Controllers\Site\OpenLiveEditorController;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use App\Services\Site\EditSessionToken;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// TODO(sso-future): remove when site management routes move to agent domain.
beforeEach(function () {
    $this->withoutMiddleware(\App\Http\Middleware\EnsureClientUser::class);
});

function openEditorSetup(): array
{
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $user->id,
        'preview_domain' => 'test-plumbing',
        'preview_brand' => 'a',
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    test()->actingAs($user);

    return [$user, $site, $page];
}

test('unauthenticated request is redirected to login', function () {
    $site = Site::factory()->create(['preview_domain' => 'test-co', 'preview_brand' => 'a']);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);

    $this->get(route('site.admin.open-live-editor', ['site' => $site->id, 'page' => $page->id]))
        ->assertRedirect(route('agent.login'));
});

test('non-agent user without matching client_id gets 403', function () {
    // Non-agent user with no client_id: belongsToUser returns false.
    $clientUser = User::factory()->create(['client_id' => null, 'role' => null]);

    $otherSite = Site::factory()->create([
        'preview_domain' => 'other-co',
        'preview_brand' => 'a',
    ]);
    $otherPage = GeneratedPage::factory()->for($otherSite)->create(['page_type' => 'home']);

    $this->actingAs($clientUser)
        ->get(route('site.admin.open-live-editor', ['site' => $otherSite->id, 'page' => $otherPage->id]))
        ->assertForbidden(); // role=null users reach the policy layer, which 403s (agent.only only redirects when the route is on the agent subdomain)
});

test('redirects to preview FQDN with edit_token for homepage', function () {
    [$user, $site, $page] = openEditorSetup();

    $response = $this->get(
        route('site.admin.open-live-editor', ['site' => $site->id, 'page' => $page->id]),
    );

    $response->assertRedirect();
    $location = $response->headers->get('Location');

    expect($location)->toStartWith('https://test-plumbing.')
        ->toContain('?edit_token=');

    // Token should be in the URL and validate correctly
    $query = parse_url($location, PHP_URL_QUERY);
    parse_str($query, $params);
    $token = $params['edit_token'] ?? null;
    expect($token)->not->toBeNull();

    $tokens = app(EditSessionToken::class);
    $payload = $tokens->validate($token);
    expect($payload)->not->toBeNull()
        ->and($payload['site_id'])->toBe($site->id)
        ->and($payload['user_id'])->toBe($user->id)
        ->and($payload['page_id'])->toBe($page->id);
});

test('redirects to custom domain when active', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $user->id,
        'preview_domain' => 'test-plumbing',
        'preview_brand' => 'a',
        'custom_domain' => 'www.testplumbing.com',
        'custom_domain_status' => 'active',
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $this->actingAs($user);

    $response = $this->get(
        route('site.admin.open-live-editor', ['site' => $site->id, 'page' => $page->id]),
    );

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->toStartWith('https://www.testplumbing.com/');
});

test('uses preview domain when custom domain is not active', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $user->id,
        'preview_domain' => 'test-plumbing',
        'preview_brand' => 'a',
        'custom_domain' => 'www.testplumbing.com',
        'custom_domain_status' => 'pending',
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $this->actingAs($user);

    $response = $this->get(
        route('site.admin.open-live-editor', ['site' => $site->id, 'page' => $page->id]),
    );

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->toStartWith('https://test-plumbing.');
});

test('inner page redirects to /{page_type} path', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $user->id,
        'preview_domain' => 'test-co',
        'preview_brand' => 'a',
    ]);
    $aboutPage = GeneratedPage::factory()->for($site)->create(['page_type' => 'about']);
    $this->actingAs($user);

    $response = $this->get(
        route('site.admin.open-live-editor', ['site' => $site->id, 'page' => $aboutPage->id]),
    );

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    $path = parse_url($location, PHP_URL_PATH);
    expect($path)->toBe('/about');
});

test('returns 422 when site has no preview domain configured', function () {
    $user = User::factory()->staff()->create();
    // Force no preview_domain by bypassing the booted hook
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $site->preview_domain = null;
    $site->saveQuietly();

    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $this->actingAs($user);

    $this->get(
        route('site.admin.open-live-editor', ['site' => $site->id, 'page' => $page->id]),
    )->assertStatus(422);
});

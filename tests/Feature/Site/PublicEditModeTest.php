<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\User;
use App\Services\Site\EditSessionCookie;
use App\Services\Site\EditSessionToken;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Build a minimal published site and return [site, homePage, homeRevision, version].
 */
function editModeSiteSetup(string $customDomain = 'edit.example'): array
{
    config(['site.use_versioned_renderer' => true]);

    $site = Site::factory()->create([
        'custom_domain' => $customDomain,
        'custom_domain_status' => 'active',
    ]);
    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Hello World']]],
    ]);
    $home->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => [
            'nav' => ['items' => []], 'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'], 'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [['page_id' => $home->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return [$site, $home, $rev, $version];
}

test('valid edit_token renders page in admin-edit mode with editor chrome', function () {
    [$site, $home] = editModeSiteSetup();
    $user = User::factory()->create();

    $tokens = app(EditSessionToken::class);
    $token = $tokens->mint($site->id, $user->id, $home->id, 1800);

    // First request: should redirect to clean URL (token stripped)
    $response = $this->get("http://edit.example/?edit_token={$token}");

    $response->assertRedirect('/');
    expect(parse_url($response->headers->get('Location'), PHP_URL_QUERY))->toBeNull();

    // Cookie must be set on the redirect response
    $cookieNames = array_map(fn ($c) => $c->getName(), $response->headers->getCookies());
    expect($cookieNames)->toContain('edit_session');

    // Following the redirect (with the cookie) renders admin-edit
    $editCookieValue = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === 'edit_session')
        ?->getValue();

    $editResponse = $this->withUnencryptedCookies(['edit_session' => $editCookieValue])
        ->get('http://edit.example/');

    $editResponse->assertOk();
    $editResponse->assertSee('SITE_EDITOR_CONFIG', false);
    // admin-edit responses carry Referrer-Policy: no-referrer
    expect($editResponse->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

test('valid edit_session cookie renders page in admin-edit mode without requiring token', function () {
    [$site, $home] = editModeSiteSetup();
    $user = User::factory()->create();

    $tokens = app(EditSessionToken::class);
    $tokenPayload = $tokens->validate($tokens->mint($site->id, $user->id, $home->id, 1800));

    $editCookie = app(EditSessionCookie::class);
    $cookie = $editCookie->make($tokenPayload, 'edit.example');

    $response = $this->withUnencryptedCookies(['edit_session' => $cookie->getValue()])
        ->get('http://edit.example/');

    $response->assertOk();
    $response->assertSee('SITE_EDITOR_CONFIG', false);
});

test('public-host edit mode does not render dead form selection markers', function () {
    [$site, $home, $revision] = editModeSiteSetup();
    $user = User::factory()->create();
    $revision->update([
        'content_data' => ['sections' => [[
            'type' => 'contact_form',
            'title' => 'Contact us',
        ]]],
    ]);

    $tokens = app(EditSessionToken::class);
    $tokenPayload = $tokens->validate($tokens->mint($site->id, $user->id, $home->id, 1800));
    $cookie = app(EditSessionCookie::class)->make($tokenPayload, 'edit.example');

    $response = $this->withUnencryptedCookies(['edit_session' => $cookie->getValue()])
        ->get('http://edit.example/');

    $response->assertOk()
        ->assertDontSee('data-form-editable', false)
        ->assertSee('data-editable="page.'.$home->id.'.section.0.title"', false);
});

test('no token and no cookie renders in public mode without editor chrome', function () {
    editModeSiteSetup();

    $response = $this->get('http://edit.example/');

    $response->assertOk();
    $response->assertDontSee('SITE_EDITOR_CONFIG', false);
});

test('invalid edit_token renders in public mode', function () {
    editModeSiteSetup();

    $response = $this->get('http://edit.example/?edit_token=totally-invalid-garbage');

    $response->assertOk();
    $response->assertDontSee('SITE_EDITOR_CONFIG', false);
});

test('expired edit_token renders in public mode', function () {
    [$site, $home] = editModeSiteSetup();
    $user = User::factory()->create();

    $tokens = app(EditSessionToken::class);
    $token = $tokens->mint($site->id, $user->id, $home->id, -1); // already expired

    $response = $this->get("http://edit.example/?edit_token={$token}");

    $response->assertOk();
    $response->assertDontSee('SITE_EDITOR_CONFIG', false);
});

test('edit_token for wrong site renders in public mode', function () {
    [$site, $home] = editModeSiteSetup();
    $user = User::factory()->create();

    $tokens = app(EditSessionToken::class);
    // Token for a completely different site_id
    $token = $tokens->mint(99999, $user->id, $home->id, 1800);

    $response = $this->get("http://edit.example/?edit_token={$token}");

    $response->assertOk();
    $response->assertDontSee('SITE_EDITOR_CONFIG', false);
});

test('valid token request 302s and redirect target URL has no edit_token param', function () {
    [$site, $home] = editModeSiteSetup();
    $user = User::factory()->create();

    $tokens = app(EditSessionToken::class);
    $token = $tokens->mint($site->id, $user->id, $home->id, 1800);

    $response = $this->get("http://edit.example/?edit_token={$token}&other=kept");

    $response->assertStatus(302);
    $location = $response->headers->get('Location');
    expect($location)->not->toContain('edit_token');
    // Other non-token query params are preserved
    expect($location)->toContain('other=kept');
});

test('edit_session cookie for wrong site renders in public mode', function () {
    [$site, $home] = editModeSiteSetup();
    $user = User::factory()->create();

    // Create a cookie for a different site
    $differentSite = Site::factory()->create(['custom_domain' => 'other.example', 'custom_domain_status' => 'active']);
    $tokens = app(EditSessionToken::class);
    $tokenPayload = $tokens->validate($tokens->mint($differentSite->id, $user->id, $home->id, 1800));

    $editCookie = app(EditSessionCookie::class);
    $cookie = $editCookie->make($tokenPayload, 'edit.example');

    $response = $this->withUnencryptedCookies(['edit_session' => $cookie->getValue()])
        ->get('http://edit.example/');

    $response->assertOk();
    $response->assertDontSee('SITE_EDITOR_CONFIG', false);
});

test('tampered edit_session cookie renders public mode and response forgets the cookie (H4)', function () {
    editModeSiteSetup();

    $response = $this->withUnencryptedCookies(['edit_session' => 'tampered.garbage.value'])
        ->get('http://edit.example/');

    $response->assertOk();
    $response->assertDontSee('SITE_EDITOR_CONFIG', false);

    // The response must instruct the browser to delete the bad cookie.
    $setCookieHeaders = $response->headers->all('set-cookie');
    $forgotCookie = collect($setCookieHeaders)->first(fn ($h) => str_contains($h, 'edit_session'));
    expect($forgotCookie)->not->toBeNull('expected a Set-Cookie header for edit_session');
    // A "forgotten" cookie has an expiry in the past (Expires=Thu, 01 Jan 1970 or Max-Age=0).
    expect(strtolower($forgotCookie))->toMatch('/expires=.*1970|max-age=0/');
});

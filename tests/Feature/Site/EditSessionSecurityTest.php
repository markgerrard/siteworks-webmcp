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
 * Security tests for /_edit/* route protection:
 * - Origin / Referer cross-origin blocking (finding #1)
 * - Per-session X-Edit-Csrf header validation (finding #1)
 * - Single-use token enforcement (finding #2)
 */

function securitySetup(): array
{
    config(['site.use_versioned_renderer' => true]);

    $user = User::factory()->staff()->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $user->id,
        'custom_domain' => 'victim.example',
        'custom_domain_status' => 'active',
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Title', 'subtitle' => 'sub'],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => [
            'nav' => ['items' => []], 'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'], 'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $tokens = app(EditSessionToken::class);
    $tokenPayload = $tokens->validate($tokens->mint($site->id, $user->id, $page->id, 1800));
    $editCookie = app(EditSessionCookie::class);
    $cookieObject = $editCookie->make($tokenPayload, 'victim.example');
    $cookieValue = $cookieObject->getValue();
    $cookiePayload = $editCookie->validate($cookieValue);
    $csrf = $cookiePayload['csrf'] ?? '';

    return [$user, $site, $page, $rev, $cookieValue, $csrf, $tokens];
}

// ─── Origin / Referer blocking ───────────────────────────────────────────────

test('cross-origin POST is blocked with 403 when Origin header is from a different host', function () {
    [, , $page, , $cookieValue, $csrf] = securitySetup();

    $this->withCredentials()->withUnencryptedCookies(['edit_session' => $cookieValue])
        ->postJson(
            "http://victim.example/_edit/fields/{$page->id}",
            ['section_index' => 0, 'field_path' => 'title', 'value' => 'pwned'],
            [
                'Origin' => 'http://attacker.victim.example', // sibling subdomain
                'X-Edit-Csrf' => $csrf,
            ],
        )
        ->assertStatus(403);
});

test('cross-origin POST via Referer is blocked when referer host differs', function () {
    [, , $page, , $cookieValue, $csrf] = securitySetup();

    $this->withCredentials()->withUnencryptedCookies(['edit_session' => $cookieValue])
        ->postJson(
            "http://victim.example/_edit/fields/{$page->id}",
            ['section_index' => 0, 'field_path' => 'title', 'value' => 'pwned'],
            [
                // No Origin header — browser falls back to Referer only in some cases.
                'Referer' => 'http://attacker.example/evil',
                'X-Edit-Csrf' => $csrf,
            ],
        )
        ->assertStatus(403);
});

test('same-origin POST with correct Origin and CSRF succeeds', function () {
    [, , $page, , $cookieValue, $csrf] = securitySetup();

    $this->withCredentials()->withUnencryptedCookies(['edit_session' => $cookieValue])
        ->postJson(
            "http://victim.example/_edit/fields/{$page->id}",
            ['section_index' => 0, 'field_path' => 'title', 'value' => 'legit update'],
            ['Origin' => 'http://victim.example', 'X-Edit-Csrf' => $csrf],
        )
        ->assertOk();
});

// ─── X-Edit-Csrf header validation ───────────────────────────────────────────

test('POST with missing X-Edit-Csrf header returns 403', function () {
    [, , $page, , $cookieValue] = securitySetup();

    $this->withCredentials()->withUnencryptedCookies(['edit_session' => $cookieValue])
        ->postJson(
            "http://victim.example/_edit/fields/{$page->id}",
            ['section_index' => 0, 'field_path' => 'title', 'value' => 'x'],
            ['Origin' => 'http://victim.example'],
        )
        ->assertStatus(403);
});

test('POST with wrong X-Edit-Csrf value returns 403', function () {
    [, , $page, , $cookieValue] = securitySetup();

    $this->withCredentials()->withUnencryptedCookies(['edit_session' => $cookieValue])
        ->postJson(
            "http://victim.example/_edit/fields/{$page->id}",
            ['section_index' => 0, 'field_path' => 'title', 'value' => 'x'],
            ['Origin' => 'http://victim.example', 'X-Edit-Csrf' => 'completely-wrong-token'],
        )
        ->assertStatus(403);
});

// ─── Single-use token enforcement ────────────────────────────────────────────

test('edit_token can only be redeemed once — second request falls through to public mode', function () {
    [, $site, $page, , , , $tokens] = securitySetup();
    $user = User::factory()->create();

    $token = $tokens->mint($site->id, $user->id, $page->id, 1800);

    // First redemption: should redirect (302) and set the cookie.
    $firstResponse = $this->get("http://victim.example/?edit_token={$token}");
    $firstResponse->assertStatus(302);
    $cookieNames = array_map(fn ($c) => $c->getName(), $firstResponse->headers->getCookies());
    expect($cookieNames)->toContain('edit_session');

    // Second redemption of the same token: must NOT redirect to edit mode.
    $secondResponse = $this->get("http://victim.example/?edit_token={$token}");
    // Falls through to public mode (200) — no cookie set, no editor chrome.
    $secondResponse->assertOk();
    $secondCookies = array_map(fn ($c) => $c->getName(), $secondResponse->headers->getCookies());
    expect($secondCookies)->not->toContain('edit_session');
    $secondResponse->assertDontSee('SITE_EDITOR_CONFIG', false);
});

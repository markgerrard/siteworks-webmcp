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

function publicEditSetup(): array
{
    config(['site.use_versioned_renderer' => true]);

    $user = User::factory()->staff()->create();
    $site = Site::factory()->create([
        'created_by_user_id' => $user->id,
        'custom_domain' => 'pub-edit.example',
        'custom_domain_status' => 'active',
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Old Title', 'subtitle' => 'sub'],
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

    // Build a valid edit session cookie
    $tokens = app(EditSessionToken::class);
    $tokenPayload = $tokens->validate($tokens->mint($site->id, $user->id, $page->id, 1800));
    $editCookie = app(EditSessionCookie::class);
    $cookieObject = $editCookie->make($tokenPayload, 'pub-edit.example');
    $cookieValue = $cookieObject->getValue();
    // Extract the csrf from the validated cookie payload so tests can send it
    $cookiePayload = $editCookie->validate($cookieValue);
    $csrf = $cookiePayload['csrf'] ?? '';

    return [$user, $site, $page, $rev, $cookieValue, $csrf];
}

test('cookie-authed POST updates field and returns rendered html', function () {
    [$user, $site, $page, $rev, $cookieValue, $csrf] = publicEditSetup();

    $response = $this->withCredentials()->withUnencryptedCookies(['edit_session' => $cookieValue])
        ->postJson(
            "http://pub-edit.example/_edit/fields/{$page->id}",
            [
                'section_index' => 0,
                'field_path' => 'title',
                'value' => 'Updated via public host',
            ],
            ['X-Edit-Csrf' => $csrf, 'Origin' => 'http://pub-edit.example'],
        );

    $response->assertOk();
    expect($response->json('html'))->toContain('Updated via public host');

    $page->refresh();
    expect($page->draft_revision_id)->not->toBeNull();
});

test('missing cookie returns 401', function () {
    [, , $page] = publicEditSetup();

    $this->postJson(
        "http://pub-edit.example/_edit/fields/{$page->id}",
        ['section_index' => 0, 'field_path' => 'title', 'value' => 'x'],
        ['Origin' => 'http://pub-edit.example'],
    )->assertStatus(401);
});

test('cookie for wrong site returns 404', function () {
    [$user, $site, $page, $rev, $cookieValue, $csrf] = publicEditSetup();

    // Create another site and page that the user doesn't own
    $otherSite = Site::factory()->create();
    $otherPage = GeneratedPage::factory()->for($otherSite)->create(['page_type' => 'home']);
    PageRevision::factory()->for($otherPage, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'X', 'subtitle' => 's']]],
    ]);

    // Use a cookie for the first site but post to the other site's page —
    // the page will not be found under the authenticated site, so 404 is expected.
    $this->withCredentials()->withUnencryptedCookies(['edit_session' => $cookieValue])
        ->postJson(
            "http://pub-edit.example/_edit/fields/{$otherPage->id}",
            ['section_index' => 0, 'field_path' => 'title', 'value' => 'x'],
            ['X-Edit-Csrf' => $csrf, 'Origin' => 'http://pub-edit.example'],
        )->assertNotFound();
});

test('invalid cookie value returns 401', function () {
    [, , $page] = publicEditSetup();

    // Send a garbled cookie value — EditSessionAuth should reject it
    $this->withCredentials()
        ->withUnencryptedCookies(['edit_session' => 'garbage.notavalidsignature'])
        ->postJson(
            "http://pub-edit.example/_edit/fields/{$page->id}",
            ['section_index' => 0, 'field_path' => 'title', 'value' => 'x'],
            ['X-Edit-Csrf' => 'anything', 'Origin' => 'http://pub-edit.example'],
        )->assertStatus(401);
});

test('publish endpoint requires cookie auth', function () {
    publicEditSetup();

    $this->postJson('http://pub-edit.example/_edit/publish', [], ['Origin' => 'http://pub-edit.example'])
        ->assertStatus(401);
});

test('discard-all endpoint requires cookie auth', function () {
    publicEditSetup();

    $this->postJson('http://pub-edit.example/_edit/discard-all', [], ['Origin' => 'http://pub-edit.example'])
        ->assertStatus(401);
});

test('publish-summary requires cookie auth', function () {
    publicEditSetup();

    $this->getJson('http://pub-edit.example/_edit/publish-summary')
        ->assertStatus(401);
});

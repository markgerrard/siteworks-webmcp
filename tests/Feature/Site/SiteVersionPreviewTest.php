<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

// TODO(sso-future): remove when site management routes move to agent domain.
beforeEach(function () {
    $this->withoutMiddleware(\App\Http\Middleware\EnsureClientUser::class);
});

function makeVersionForPreview(): array
{
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Historic heading']]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => ['homepage_page_id' => $page->id, 'nav' => ['items' => []]],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);

    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    return [$user, $site, $page, $rev, $version];
}

test('GET version preview 200 for owner and renders pinned revision content', function () {
    [$user, $site, $page, $rev, $version] = makeVersionForPreview();

    $this->actingAs($user)
        ->get(route('site.version.preview', ['site' => $site->id, 'version' => $version->id]))
        ->assertOk()
        ->assertSee('Historic heading');
});

test('GET version preview ?page= param selects correct page', function () {
    [$user, $site, $page, $rev, $version] = makeVersionForPreview();

    $this->actingAs($user)
        ->get(route('site.version.preview', ['site' => $site->id, 'version' => $version->id, 'page' => $page->id]))
        ->assertOk()
        ->assertSee('Historic heading');
});

test('GET version preview is forbidden for unauthenticated user', function () {
    [, $site, , , $version] = makeVersionForPreview();

    $this->get(route('site.version.preview', ['site' => $site->id, 'version' => $version->id]))
        ->assertRedirect(route('agent.login'));
});

test('GET version preview is forbidden for user without site access', function () {
    // site.version.preview lives in the surface-shared editor route file,
    // not the agents-only middleware group. Authorization is SitePolicy@view
    // only, so users with no claim get a 403 instead of an agent-login
    // redirect.
    [, $site, , , $version] = makeVersionForPreview();
    $other = User::factory()->create(['client_id' => null, 'role' => null]);

    $this->actingAs($other)
        ->get(route('site.version.preview', ['site' => $site->id, 'version' => $version->id]))
        ->assertForbidden();
});

test('GET version preview returns 404 when version belongs to a different site', function () {
    [$user, $site] = makeVersionForPreview();
    $otherSite = Site::factory()->create(['created_by_user_id' => $user->id]);
    $otherPage = GeneratedPage::factory()->for($otherSite)->create(['page_type' => 'home']);
    $otherRev = PageRevision::factory()->for($otherPage, 'page')->create();
    $otherPage->update(['published_revision_id' => $otherRev->id]);

    $otherVersion = SiteVersion::create([
        'site_id' => $otherSite->id,
        'version' => 1,
        'composition' => ['homepage_page_id' => $otherPage->id, 'nav' => ['items' => []]],
        'page_revisions' => [['page_id' => $otherPage->id, 'revision_id' => $otherRev->id]],
        'published_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('site.version.preview', ['site' => $site->id, 'version' => $otherVersion->id]))
        ->assertNotFound();
});

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

function makeTwoVersionSite(): array
{
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);

    $rev1 = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'v1']]],
    ]);
    $page->update(['published_revision_id' => $rev1->id]);

    $v1 = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => ['homepage_page_id' => $page->id, 'nav' => ['items' => []]],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev1->id]],
        'published_at' => now(),
    ]);

    $rev2 = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'v2']]],
    ]);
    $page->update(['published_revision_id' => $rev2->id]);

    $v2 = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 2,
        'composition' => ['homepage_page_id' => $page->id, 'nav' => ['items' => []]],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev2->id]],
        'published_at' => now(),
    ]);

    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $v2->id,
        'updated_at' => now(),
    ]);

    return [$user, $site, $page, $rev1, $v1, $rev2, $v2];
}

test('POST rollback flips current pointer and persists', function () {
    [$user, $site, $page, $rev1, $v1] = makeTwoVersionSite();

    $this->actingAs($user)
        ->post(route('site.version.rollback', ['site' => $site->id, 'version' => $v1->id]))
        ->assertRedirect();

    $current = SiteVersionCurrent::where('site_id', $site->id)->first();
    expect($current->version_id)->toBe($v1->id);

    $page->refresh();
    expect($page->published_revision_id)->toBe($rev1->id);
});

test('POST rollback is forbidden for unauthenticated user', function () {
    [, $site, , , $v1] = makeTwoVersionSite();

    $this->post(route('site.version.rollback', ['site' => $site->id, 'version' => $v1->id]))
        ->assertRedirect(route('agent.login'));
});

test('POST rollback is forbidden for user without site access', function () {
    // site.version.rollback lives in the surface-shared editor route file,
    // not the agents-only middleware group. Authorization is enforced by
    // SitePolicy@update only, so users with no claim get a 403 instead of
    // an agent-login redirect.
    [, $site, , , $v1] = makeTwoVersionSite();
    $other = User::factory()->create(['client_id' => null, 'role' => null]);

    $this->actingAs($other)
        ->post(route('site.version.rollback', ['site' => $site->id, 'version' => $v1->id]))
        ->assertForbidden();
});

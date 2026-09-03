<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\User;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeHistoryComponentSite(): array
{
    $user = User::factory()->staff(\App\Enums\AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);

    $rev1 = PageRevision::factory()->for($page, 'page')->create();
    $page->update(['published_revision_id' => $rev1->id]);

    $v1 = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => ['homepage_page_id' => $page->id, 'nav' => ['items' => []]],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev1->id]],
        'published_at' => now()->subDay(),
        'publish_note' => 'initial publish',
    ]);

    $rev2 = PageRevision::factory()->for($page, 'page')->create();
    $page->update(['published_revision_id' => $rev2->id]);

    $v2 = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 2,
        'composition' => ['homepage_page_id' => $page->id, 'nav' => ['items' => []]],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev2->id]],
        'published_at' => now(),
        'publish_note' => 'second publish',
    ]);

    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $v2->id,
        'updated_at' => now(),
    ]);

    return [$user, $site, $page, $rev1, $v1, $rev2, $v2];
}

test('version history component renders versions list', function () {
    [$user, $site, , , $v1, , $v2] = makeHistoryComponentSite();
    $this->actingAs($user);

    Livewire::test('site.version-history', ['siteId' => $site->id])
        ->assertSuccessful()
        ->assertSee('v1')
        ->assertSee('v2')
        ->assertSee('initial publish')
        ->assertSee('CURRENT');
});

test('version history restore call flips current version and dispatches event', function () {
    [$user, $site, $page, $rev1, $v1, $rev2, $v2] = makeHistoryComponentSite();
    $this->actingAs($user);

    Livewire::test('site.version-history', ['siteId' => $site->id])
        ->call('restore', $v1->id)
        ->assertDispatched('site-updated');

    $current = SiteVersionCurrent::where('site_id', $site->id)->first();
    expect($current->version_id)->toBe($v1->id);

    $page->refresh();
    expect($page->published_revision_id)->toBe($rev1->id);
});

test('version history shows empty state when only one version exists', function () {
    $user = User::factory()->staff(\App\Enums\AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);

    $rev = PageRevision::factory()->for($page, 'page')->create();
    $page->update(['published_revision_id' => $rev->id]);

    $v1 = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => ['homepage_page_id' => $page->id, 'nav' => ['items' => []]],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);

    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $v1->id, 'updated_at' => now()]);

    $this->actingAs($user);

    Livewire::test('site.version-history', ['siteId' => $site->id])
        ->assertSuccessful()
        ->assertSee('Publish changes to build version history.');
});

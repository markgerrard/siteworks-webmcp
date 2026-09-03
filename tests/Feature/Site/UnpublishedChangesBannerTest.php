<?php

use App\Enums\AgentRole;
use App\Enums\MutationSource;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Models\User;
use App\Services\Site\CompositionService;
use App\Services\Site\SitePublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedPublishedSite(): Site
{
    $site = Site::factory()->create();
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => [],
        'sort_order' => 0,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $rev = PageRevision::create([
        'page_id' => $page->id,
        'content_data' => [],
        'ai_generated' => false,
        'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    // Publish so the draft is in sync with a version — baseline "no pending"
    app(SitePublishService::class)->publishSite($site);

    return $site;
}

beforeEach(fn () => $this->staff = User::factory()->staff(AgentRole::Admin)->create());

test('banner hides when draft is in sync with current version', function () {
    $site = seedPublishedSite();

    Livewire::actingAs($this->staff)
        ->test('site.unpublished-changes-banner', ['siteId' => $site->id])
        ->assertSet('pending', false)
        ->assertSet('pendingCount', 0)
        ->assertDontSee('Publish now')
        ->assertDontSee('Discard draft');
});

test('banner shows when admin changes a page status (Published → Draft)', function () {
    $site = seedPublishedSite();
    $home = $site->generatedPages()->where('page_type', 'home')->first();

    // Simulate an admin status change (bumps admin_revision) — cleanest
    // way to trigger a pending composition delta.
    $home->update(['status' => PageStatus::Draft]);
    app(CompositionService::class)->bumpAdminRevision($site, userId: $this->staff->id);

    Livewire::actingAs($this->staff)
        ->test('site.unpublished-changes-banner', ['siteId' => $site->id])
        ->assertSet('pending', true)
        ->assertSet('pendingCount', fn ($c) => $c >= 1)
        ->assertSee('Publish now');
});

test('banner publish button creates a new SiteVersion and hides the banner', function () {
    $site = seedPublishedSite();
    // create pending composition change via admin-sourced nav update
    $draft = app(CompositionService::class)->getOrCreateDraft($site);
    app(CompositionService::class)->updateNav(
        $draft,
        [['type' => 'shop', 'label' => 'Shop']],
        MutationSource::Admin,
        $this->staff->id,
    );

    $versionsBefore = SiteVersion::where('site_id', $site->id)->count();

    Livewire::actingAs($this->staff)
        ->test('site.unpublished-changes-banner', ['siteId' => $site->id])
        ->assertSet('pending', true)
        ->call('publish')
        ->assertSet('pending', false);

    expect(SiteVersion::where('site_id', $site->id)->count())->toBe($versionsBefore + 1);
    $newest = SiteVersion::where('site_id', $site->id)->latest('id')->first();
    expect($newest->publish_note)->toBe('Manual publish from unpublished-changes banner');
});

test('banner discard button clears draft changes back to the current version', function () {
    $site = seedPublishedSite();
    $draft = app(CompositionService::class)->getOrCreateDraft($site);
    app(CompositionService::class)->updateNav(
        $draft,
        [['type' => 'shop', 'label' => 'Shop']],
        MutationSource::Admin,
        $this->staff->id,
    );

    Livewire::actingAs($this->staff)
        ->test('site.unpublished-changes-banner', ['siteId' => $site->id])
        ->assertSet('pending', true)
        ->call('discard')
        ->assertSet('pending', false);
});

test('banner surfaces an error message when publish throws', function () {
    // A site with no Published pages triggers SitePublishException.
    $site = Site::factory()->create();
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => [],
        'sort_order' => 0,
        'version' => 1,
        'status' => PageStatus::Draft,
    ]);
    PageRevision::create([
        'page_id' => $page->id,
        'content_data' => [],
        'ai_generated' => false,
        'created_at' => now(),
    ]);

    Livewire::actingAs($this->staff)
        ->test('site.unpublished-changes-banner', ['siteId' => $site->id])
        ->call('publish')
        ->assertSet('errorMessage', fn ($m) => is_string($m) && str_contains($m, 'Cannot publish'));
});

<?php

use App\Enums\AgentRole;
use App\Enums\PageKind;
use App\Enums\PageStatus;
use App\Enums\PreviewLayout;
use App\Enums\SitePurpose;
use App\Exceptions\Site\SitePublishException;
use App\Http\Middleware\EnsureClientUser;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\SiteSubscription;
use App\Models\User;
use App\Services\Site\SitePublishService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->withoutMiddleware(EnsureClientUser::class);
});

function purposePublishableSite(array $attributes = []): Site
{
    $site = Site::factory()->create(array_merge([
        'preview_layout' => PreviewLayout::MultiPage,
    ], $attributes));

    $home = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'status' => PageStatus::Published,
        'kind' => PageKind::Core,
        'nav_label' => 'Home',
    ]);
    $revision = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Welcome']]],
    ]);
    $home->update(['draft_revision_id' => $revision->id]);

    return $site;
}

test('sites default to the website purpose', function () {
    $site = Site::factory()->create();

    expect($site->fresh()->purpose)->toBe(SitePurpose::Website);
});

test('publishSite refuses a video-only site', function () {
    $site = purposePublishableSite(['purpose' => SitePurpose::VideoOnly]);

    expect(fn () => app(SitePublishService::class)->publishSite($site))
        ->toThrow(SitePublishException::class, 'video-only');
});

test('publishSinglePage refuses a video-only site', function () {
    $site = purposePublishableSite(['purpose' => SitePurpose::VideoOnly]);
    $page = $site->generatedPages()->firstOrFail();

    expect(fn () => app(SitePublishService::class)->publishSinglePage($site, $page))
        ->toThrow(SitePublishException::class, 'video-only');
});

test('domain verification sweep skips video-only sites', function () {
    $site = Site::factory()->create([
        'purpose' => SitePurpose::VideoOnly,
        'custom_domain' => 'video-only.example',
        'custom_domain_status' => 'verifying',
    ]);

    $this->artisan('site:sweep-domain-verification')->assertSuccessful();

    expect($site->fresh()->custom_domain_status)->toBe('verifying');
});

test('managed content eligibility excludes video-only sites', function () {
    $eligible = purposePublishableSite();
    app(SitePublishService::class)->publishSite($eligible);
    SiteSubscription::factory()->for($eligible)->create(['started_at' => now()]);

    $videoOnly = purposePublishableSite();
    app(SitePublishService::class)->publishSite($videoOnly);
    SiteSubscription::factory()->for($videoOnly)->create(['started_at' => now()]);
    $videoOnly->update(['purpose' => SitePurpose::VideoOnly]);

    $ids = Site::query()->managedContentEligible()->pluck('id');

    expect($ids)->toContain($eligible->id)
        ->not->toContain($videoOnly->id)
        ->and($videoOnly->fresh()->isManagedContentEligible())->toBeFalse();
});

test('sites index hides video-only sites by default and shows them on request', function () {
    $agent = User::factory()->staff(AgentRole::Agent)->create();

    Site::factory()->create(['created_by_user_id' => $agent->id, 'business_name' => 'Website Site']);
    Site::factory()->create([
        'created_by_user_id' => $agent->id,
        'business_name' => 'Video Only Site',
        'purpose' => SitePurpose::VideoOnly,
    ]);

    $this->actingAs($agent)
        ->get(route('sites.index'))
        ->assertOk()
        ->assertSee('Website Site')
        ->assertDontSee('Video Only Site');

    $this->actingAs($agent)
        ->get(route('sites.index', ['purpose' => 'video_only']))
        ->assertOk()
        ->assertSee('Video Only Site')
        ->assertDontSee('Website Site');
});

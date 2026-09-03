<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\SitePublishService;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->withoutMiddleware(\App\Http\Middleware\EnsureClientUser::class);
});

it('adds actor_channel to site versions and records the publish channel', function () {
    expect(Schema::hasColumn('site_versions', 'actor_channel'))->toBeTrue();

    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create();
    $revision = PageRevision::factory()->for($page, 'page')->create();
    $page->update(['published_revision_id' => $revision->id]);

    $version = app(SitePublishService::class)->publishSite(
        $site,
        userId: $user->id,
        channel: ActorChannel::Webmcp,
    );

    expect($version->actor_channel)->toBe('webmcp');

    $this->actingAs($user)
        ->postJson(route('site.admin.publish', $site->id))
        ->assertOk();

    expect(SiteVersion::query()->where('site_id', $site->id)->latest('id')->value('actor_channel'))
        ->toBe('ui');

    expect(app(SitePublishService::class)->publishSite($site->fresh())->actor_channel)->toBeNull();
});

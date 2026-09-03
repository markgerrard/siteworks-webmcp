<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// TODO(sso-future): remove when site management routes move to agent domain.
beforeEach(function () {
    $this->withoutMiddleware(\App\Http\Middleware\EnsureClientUser::class);
});

test('GET publish-summary returns pending pages + composition flag', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create();
    $rev = PageRevision::factory()->for($page, 'page')->create();
    $page->update(['published_revision_id' => $rev->id]);

    app(\App\Services\Site\PageService::class)->editField($page, 'sections.0.title', 'Edit');

    $this->actingAs($user)
        ->getJson(route('site.admin.publish-summary', $site->id))
        ->assertOk()
        ->assertJsonPath('pending_pages.0.page_id', $page->id)
        ->assertJsonPath('next_version', 1);
});

test('POST publish creates new site_version with note', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create();
    $rev = PageRevision::factory()->for($page, 'page')->create();
    $page->update(['published_revision_id' => $rev->id]);

    $this->actingAs($user)
        ->postJson(route('site.admin.publish', $site->id), ['publish_note' => 'first publish'])
        ->assertOk()
        ->assertJsonPath('version', 1);

    expect(SiteVersion::where('site_id', $site->id)->count())->toBe(1);
});

test('POST publish rejects publish_note exceeding 500 characters (M4)', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson(route('site.admin.publish', $site->id), [
            'publish_note' => str_repeat('x', 600),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['publish_note']);
});

test('POST discard-all clears drafts', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create();
    $rev = PageRevision::factory()->for($page, 'page')->create();
    $page->update(['published_revision_id' => $rev->id]);

    app(\App\Services\Site\PageService::class)->editField($page, 'sections.0.title', 'Edit');
    expect($page->fresh()->draft_revision_id)->not->toBeNull();

    $this->actingAs($user)
        ->postJson(route('site.admin.discard-all', $site->id))
        ->assertOk();

    expect($page->fresh()->draft_revision_id)->toBeNull();
});

<?php

use App\Enums\MutationSource;
use App\Models\Site;
use App\Models\Site\SiteDraft;
use App\Services\Site\CompositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->svc = app(CompositionService::class);
    $this->user = \App\Models\User::factory()->create();
});

function currentRev(Site $site): int
{
    return (int) (SiteDraft::where('site_id', $site->id)->value('admin_revision') ?? 0);
}

test('admin-sourced updateNav bumps admin_revision', function () {
    $site = Site::factory()->create();
    $draft = $this->svc->getOrCreateDraft($site);
    expect(currentRev($site))->toBe(0);

    $this->svc->updateNav($draft, [['type' => 'shop', 'label' => 'Shop']], MutationSource::Admin, $this->user->id);

    expect(currentRev($site))->toBe(1);
});

test('pipeline-sourced updateNav does NOT bump admin_revision', function () {
    $site = Site::factory()->create();
    $draft = $this->svc->getOrCreateDraft($site);

    $this->svc->updateNav($draft, [['type' => 'shop', 'label' => 'Shop']], MutationSource::Pipeline);

    expect(currentRev($site))->toBe(0);
});

test('system-sourced updateTheme does NOT bump admin_revision', function () {
    $site = Site::factory()->create();
    $draft = $this->svc->getOrCreateDraft($site);

    $this->svc->updateTheme($draft, 'professional-clean', null, null, MutationSource::System);

    expect(currentRev($site))->toBe(0);
});

test('mixed admin + pipeline mutations only count admin for admin_revision', function () {
    $site = Site::factory()->create();
    $draft = $this->svc->getOrCreateDraft($site);

    $this->svc->updateNav($draft, [], MutationSource::Pipeline);
    $this->svc->updateFooter($draft->fresh(), ['show_credit' => false], MutationSource::Admin, $this->user->id);
    $this->svc->updateFooter($draft->fresh(), ['show_credit' => true], MutationSource::Pipeline);
    $this->svc->updateTheme($draft->fresh(), 't', null, null, MutationSource::Admin, $this->user->id);

    // 4 writes total, 2 admin → admin_revision == 2
    expect(currentRev($site))->toBe(2);
});

test('appendNavPageAtomic respects source — pipeline does not bump', function () {
    $site = Site::factory()->create();
    // Seed home so CompositionDefaults doesn't elect a svc page as homepage,
    // and clear the auto-seeded nav so the append is the only insert.
    \App\Models\GeneratedPage::create(['site_id' => $site->id, 'page_type' => 'home', 'content_data' => [], 'sort_order' => 0, 'version' => 1]);
    $p1 = \App\Models\GeneratedPage::create(['site_id' => $site->id, 'page_type' => 'svc-a', 'content_data' => [], 'sort_order' => 1, 'version' => 1]);
    $p2 = \App\Models\GeneratedPage::create(['site_id' => $site->id, 'page_type' => 'svc-b', 'content_data' => [], 'sort_order' => 2, 'version' => 1]);

    $draft = $this->svc->getOrCreateDraft($site);
    $c = $draft->composition;
    $c['nav']['items'] = [];
    $draft->composition = $c;
    $draft->save();

    $this->svc->appendNavPageAtomic($site, $p1->id, 'A', MutationSource::Pipeline);
    $this->svc->appendNavPageAtomic($site, $p2->id, 'B', MutationSource::Pipeline);

    expect(currentRev($site))->toBe(0);
});

test('appendNavPageAtomic respects source — admin bumps', function () {
    $site = Site::factory()->create();
    \App\Models\GeneratedPage::create(['site_id' => $site->id, 'page_type' => 'home', 'content_data' => [], 'sort_order' => 0, 'version' => 1]);
    $p1 = \App\Models\GeneratedPage::create(['site_id' => $site->id, 'page_type' => 'svc-a', 'content_data' => [], 'sort_order' => 1, 'version' => 1]);
    $p2 = \App\Models\GeneratedPage::create(['site_id' => $site->id, 'page_type' => 'svc-b', 'content_data' => [], 'sort_order' => 2, 'version' => 1]);

    $draft = $this->svc->getOrCreateDraft($site);
    $c = $draft->composition;
    $c['nav']['items'] = [];
    $draft->composition = $c;
    $draft->save();

    $this->svc->appendNavPageAtomic($site, $p1->id, 'A', MutationSource::Admin, $this->user->id);
    $this->svc->appendNavPageAtomic($site, $p2->id, 'B', MutationSource::Admin, $this->user->id);

    expect(currentRev($site))->toBe(2);
});

test('bumpAdminRevision without a composition change still bumps', function () {
    // Used by per-page status transitions: the composition JSON doesn't
    // change but the admin's intent (archive / draft a page) must register.
    $site = Site::factory()->create();
    $this->svc->getOrCreateDraft($site);

    expect(currentRev($site))->toBe(0);

    $this->svc->bumpAdminRevision($site, userId: $this->user->id);

    expect(currentRev($site))->toBe(1);
});

test('bumpAdminRevision creates a draft if none exists', function () {
    $site = Site::factory()->create();
    expect(SiteDraft::where('site_id', $site->id)->count())->toBe(0);

    $this->svc->bumpAdminRevision($site, userId: $this->user->id);

    $draft = SiteDraft::where('site_id', $site->id)->first();
    expect($draft)->not->toBeNull();
    expect($draft->admin_revision)->toBe(1);
});

test('MutationSource::Admin->isAdminIntent() is true, others false', function () {
    expect(MutationSource::Admin->isAdminIntent())->toBeTrue();
    expect(MutationSource::Pipeline->isAdminIntent())->toBeFalse();
    expect(MutationSource::System->isAdminIntent())->toBeFalse();
});

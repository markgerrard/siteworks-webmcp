<?php

use App\Enums\AgentRole;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\User;
use App\Services\Site\AutoPublishCoordinator;
use App\Services\Site\PublishLockContext;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\PageService;
use App\Services\Site\PublicPageCache;
use App\Services\Site\SitePublishService;

beforeEach(function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $this->withoutVite();

    $this->seedEditorSite = function (): array {
        $user = User::factory()->staff(AgentRole::Agent)->create();
        $site = Site::factory()->create(['created_by_user_id' => $user->id]);
        $content = ['sections' => [
            ['type' => 'hero', 'title' => 'A'],
            ['type' => 'cta', 'title' => 'B'],
        ]];
        $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'content_data' => $content]);
        $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
        $page->update(['published_revision_id' => $revision->id]);

        return ['user' => $user, 'site' => $site, 'page' => $page->fresh()];
    };

    $this->runEditor = function (User $user, Site $site, array $input): OperationResult {
        return app(EditorOperations::class)->run(
            new EditorContext($user, $site, ActorChannel::Webmcp),
            'edit_field',
            $input,
        );
    };
});

it('advances the draft and returns html containing data-editable', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $published = $page->published_revision_id;

    $result = ($this->runEditor)($user, $site, [
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'title',
        'value' => 'Agent title',
        'revision_base' => $published,
    ]);

    $page->refresh();

    expect($result->ok)->toBeTrue()
        ->and($result->data['draft_revision_id'])->toBe($page->draft_revision_id)
        ->and($result->data['html'])->toContain('data-editable')
        ->and($result->data['html'])->toContain('Agent title')
        ->and($page->published_revision_id)->toBe($published)
        ->and($page->draft_revision_id)->not->toBeNull()
        ->and($page->draft_revision_id)->not->toBe($published);
});

it('returns stale_revision on a wrong revision_base', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $published = $page->published_revision_id;

    $result = ($this->runEditor)($user, $site, [
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'title',
        'value' => 'Nope',
        'revision_base' => 999999,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('stale_revision')
        ->and($result->error['current_revision_id'])->toBe($published)
        ->and($page->fresh()->draft_revision_id)->toBeNull()
        ->and($page->fresh()->published_revision_id)->toBe($published);
});

it('returns stale_revision on a fresh base but stale structure_epoch', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $published = $page->published_revision_id;

    app(PageService::class)->mutateSections($page, $published, 0, fn (array $sections): array => $sections);
    $page->refresh();

    $result = ($this->runEditor)($user, $site, [
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'title',
        'value' => 'Nope',
        'revision_base' => $page->draft_revision_id,
        'structure_epoch' => 0,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('stale_revision')
        ->and($result->error['current_revision_id'])->toBe($page->draft_revision_id)
        ->and($page->fresh()->published_revision_id)->toBe($published);
});

it('returns stale_revision on a stale epoch for a repeatable list path', function () {
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'A'],
        ['type' => 'cta', 'title' => 'B'],
        ['type' => 'trust', 'title' => 'Why us', 'items' => [
            ['title' => 'One', 'body' => 'First'],
        ]],
    ]];
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'content_data' => $content]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $revision->id]);
    $page = $page->fresh();

    app(PageService::class)->mutateSections($page, $page->published_revision_id, 0, fn (array $sections): array => $sections);
    $page->refresh();

    $result = ($this->runEditor)($user, $site, [
        'page_id' => $page->id,
        'stored_index' => 2,
        'field_path' => 'items',
        'value' => [['title' => 'Two', 'body' => 'Second']],
        'revision_base' => $page->draft_revision_id,
        'structure_epoch' => 0,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('stale_revision')
        ->and($result->error['current_revision_id'])->toBe($page->draft_revision_id);
});

it('returns validation on an over-long title', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $published = $page->published_revision_id;

    $result = ($this->runEditor)($user, $site, [
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'title',
        'value' => str_repeat('x', 200),
        'revision_base' => $published,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['fields'])->toBeArray()
        ->and($page->fresh()->draft_revision_id)->toBeNull()
        ->and($page->fresh()->published_revision_id)->toBe($published);
});

it('returns validation when revision_base is missing and never writes', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $published = $page->published_revision_id;

    $result = ($this->runEditor)($user, $site, [
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'title',
        'value' => 'Agent title',
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('validation')
        ->and($result->error['fields'])->toHaveKey('revision_base')
        ->and($page->fresh()->draft_revision_id)->toBeNull()
        ->and($page->fresh()->published_revision_id)->toBe($published);
});

it('leaves public cache generation unchanged after a real edit_field', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $cache = app(PublicPageCache::class);
    $generationBefore = $cache->generation($site);

    $result = ($this->runEditor)($user, $site, [
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'title',
        'value' => 'Cached title',
        'revision_base' => $page->published_revision_id,
    ]);

    expect($result->ok)->toBeTrue()
        ->and($cache->generation($site))->toBe($generationBefore)
        ->and($page->fresh()->published_revision_id)->toBe($page->published_revision_id);
});

it('declines auto-publish when edit_field lands inside a service-page batch window', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedEditorSite)();
    $preBatchRev = (int) (DB::table('site_drafts')->where('site_id', $site->id)->value('admin_revision') ?? 0);

    $result = ($this->runEditor)($user, $site, [
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'title',
        'value' => 'Batch title',
        'revision_base' => $page->published_revision_id,
    ]);

    expect($result->ok)->toBeTrue();

    $publish = Mockery::mock(SitePublishService::class);
    $publish->shouldReceive('lockForPublish')->once()->andReturnUsing(function (Site $locked) use ($site): PublishLockContext {
        return new PublishLockContext(
            site: $locked,
            draft: SiteDraft::query()->where('site_id', $site->id)->first(),
            selections: collect(),
        );
    });
    $publish->shouldNotReceive('publishSite');
    (new AutoPublishCoordinator($publish))->finalizeAfterBatch($site->id, $preBatchRev, $user->id, 'batch-test', 1);
});

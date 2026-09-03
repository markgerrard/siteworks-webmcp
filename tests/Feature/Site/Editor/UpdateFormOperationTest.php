<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationResult;

beforeEach(function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $this->withoutVite();

    $this->seedFormSite = function (): array {
        $user = User::factory()->staff(AgentRole::Agent)->create();
        $site = Site::factory()->create(['created_by_user_id' => $user->id]);
        $content = ['sections' => [
            ['type' => 'hero', 'title' => 'Hi'],
            ['type' => 'contact_form', 'title' => 'Contact us', 'submit_label' => 'Send', 'fields' => []],
        ]];
        $page = GeneratedPage::create([
            'site_id' => $site->id,
            'page_type' => 'contact',
            'content_data' => $content,
            'sort_order' => 1,
            'version' => 1,
            'status' => PageStatus::Published,
        ]);
        $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
        $page->update(['published_revision_id' => $revision->id]);

        return ['user' => $user, 'site' => $site, 'page' => $page->fresh()];
    };

    $this->runEditor = function (User $user, Site $site, array $input): OperationResult {
        return app(EditorOperations::class)->run(
            new EditorContext($user, $site, ActorChannel::Webmcp),
            'update_form',
            $input,
        );
    };
});

it('replaces a form definition and returns html', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedFormSite)();
    $published = $page->published_revision_id;

    $result = ($this->runEditor)($user, $site, [
        'page_id' => $page->id,
        'stored_index' => 1,
        'title' => 'Get a quote',
        'submit_label' => 'Send it',
        'fields' => [
            ['label' => 'Job postcode', 'type' => 'text', 'required' => true],
        ],
        'revision_base' => $published,
    ]);

    expect($result->ok)->toBeTrue();

    $page->refresh();
    $draft = PageRevision::find($page->draft_revision_id);
    $section = collect($draft->content_data['sections'])->firstWhere('type', 'contact_form');

    expect($result->data['revision_id'])->toBe($page->draft_revision_id)
        ->and($result->data['html'])->toContain('Job postcode')
        ->and($section['fields'][0]['name'])->toBe('job_postcode')
        ->and($section['title'])->toBe('Get a quote')
        ->and($page->published_revision_id)->toBe($published);
});

it('returns stale_revision on a wrong revision_base', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedFormSite)();
    $published = $page->published_revision_id;

    $result = ($this->runEditor)($user, $site, [
        'page_id' => $page->id,
        'stored_index' => 1,
        'fields' => [],
        'revision_base' => 999999,
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->error['code'])->toBe('stale_revision')
        ->and($result->error['current_revision_id'])->toBe($published)
        ->and($page->fresh()->draft_revision_id)->toBeNull()
        ->and($page->fresh()->published_revision_id)->toBe($published);
});

it('legacy form route still 422s an invalid body before the missing-base 409 (pinned order)', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedFormSite)();
    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", ['fields' => 'not-an-array'])
        ->assertStatus(422);
    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", ['fields' => []])
        ->assertStatus(409);
});

it('leaves the public cache generation and the preview snapshot untouched (draft-only)', function () {
    ['user' => $user, 'site' => $site, 'page' => $page] = ($this->seedFormSite)();
    $preview = \App\Models\Preview::factory()->for($site)->create(['snapshot' => ['pages' => ['contact' => ['contact_form' => ['title' => 'LIVE']]]]]);
    $cache = app(\App\Services\Site\PublicPageCache::class);
    $before = $cache->generation($site);
    $result = ($this->runEditor)($user, $site, ['page_id' => $page->id, 'stored_index' => 1, 'revision_base' => $page->published_revision_id, 'fields' => [['label' => 'Postcode', 'type' => 'text']]]);
    expect($result->ok)->toBeTrue()
        ->and($cache->generation($site))->toBe($before)
        ->and($preview->fresh()->snapshot['pages']['contact']['contact_form']['title'])->toBe('LIVE');
});

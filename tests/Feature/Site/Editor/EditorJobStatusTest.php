<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorJobStatus;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\Operations\GetJobStatusOperation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

$seedEditorSite = function (?User $actor = null): array {
    $actor ??= User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $actor->id]);
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Hi'],
        ['type' => 'cta', 'title' => 'Call us'],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => $content,
        'sort_order' => 0,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $revision->id]);

    return [$actor, $site, $page->fresh()];
};

beforeEach(function () use ($seedEditorSite) {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    [$this->actor, $this->site, $this->page] = $seedEditorSite();
    $this->seedEditorSite = $seedEditorSite;
});

it('starts a queued ulid job under editor:job:{ref} and hides it from another site', function () {
    [, $siteB] = ($this->seedEditorSite)($this->actor);
    $jobs = app(EditorJobStatus::class);

    $ref = $jobs->start($this->site, 'generate_image', ['prompt' => 'a van']);

    expect(Str::isUlid($ref))->toBeTrue()
        ->and(Cache::get("editor:job:{$ref}"))->toBe([
            'site_id' => $this->site->id,
            'kind' => 'generate_image',
            'status' => 'queued',
            'meta' => ['prompt' => 'a van'],
            'result' => null,
            'error' => null,
        ])
        ->and($jobs->get($this->site, $ref))->toBe(Cache::get("editor:job:{$ref}"))
        ->and($jobs->get($siteB, $ref))->toBeNull();
});

it('transitions running, done, failed and stale_revision payloads', function () {
    $jobs = app(EditorJobStatus::class);

    $doneRef = $jobs->start($this->site, 'generate_image');
    $jobs->running($doneRef);
    expect($jobs->get($this->site, $doneRef)['status'])->toBe('running')
        ->and($jobs->get($this->site, $doneRef)['result'])->toBeNull()
        ->and($jobs->get($this->site, $doneRef)['error'])->toBeNull();

    $jobs->done($doneRef, ['media_id' => 9, 'url' => 'https://cdn.example/x.webp']);
    expect($jobs->get($this->site, $doneRef))->toMatchArray([
        'site_id' => $this->site->id,
        'kind' => 'generate_image',
        'status' => 'done',
        'result' => ['media_id' => 9, 'url' => 'https://cdn.example/x.webp'],
        'error' => null,
    ]);

    $failedRef = $jobs->start($this->site, 'regenerate_hero');
    $jobs->failed($failedRef, 'provider timeout');
    expect($jobs->get($this->site, $failedRef))->toMatchArray([
        'status' => 'failed',
        'result' => null,
        'error' => 'provider timeout',
    ]);

    $staleRef = $jobs->start($this->site, 'generate_image');
    $jobs->stale($staleRef, 17);
    expect($jobs->get($this->site, $staleRef))->toMatchArray([
        'status' => 'stale_revision',
        'result' => null,
        'error' => null,
        'current_revision_id' => 17,
    ]);
});

it('returns job status through get_job_status and leaves the published revision unchanged', function () {
    expect(app(GetJobStatusOperation::class)->readOnly())->toBeTrue();

    $ref = app(EditorJobStatus::class)->start($this->site, 'generate_image');
    $published = $this->page->published_revision_id;

    $result = app(EditorOperations::class)->run(
        new EditorContext($this->actor, $this->site, ActorChannel::Webmcp),
        'get_job_status',
        ['job_ref' => $ref],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data)->toBe([
            'status' => 'queued',
            'result' => null,
            'error' => null,
        ])
        ->and($this->page->fresh()->published_revision_id)->toBe($published)
        ->and($this->page->fresh()->draft_revision_id)->toBeNull();

    app(EditorJobStatus::class)->done($ref, ['media_id' => 4]);
    $done = app(EditorOperations::class)->run(
        new EditorContext($this->actor, $this->site, ActorChannel::Webmcp),
        'get_job_status',
        ['job_ref' => $ref],
    );
    expect($done->ok)->toBeTrue()
        ->and($done->data)->toBe([
            'status' => 'done',
            'result' => ['media_id' => 4],
            'error' => null,
        ]);
});

it('returns not_found for an unknown or foreign-site job_ref', function () {
    [, $siteB] = ($this->seedEditorSite)($this->actor);
    $foreignRef = app(EditorJobStatus::class)->start($this->site, 'generate_image');

    $unknown = app(EditorOperations::class)->run(
        new EditorContext($this->actor, $this->site, ActorChannel::Webmcp),
        'get_job_status',
        ['job_ref' => (string) Str::ulid()],
    );
    $foreign = app(EditorOperations::class)->run(
        new EditorContext($this->actor, $siteB, ActorChannel::Webmcp),
        'get_job_status',
        ['job_ref' => $foreignRef],
    );
    $missing = app(EditorOperations::class)->run(
        new EditorContext($this->actor, $this->site, ActorChannel::Webmcp),
        'get_job_status',
        [],
    );

    expect($unknown->ok)->toBeFalse()->and($unknown->error['code'])->toBe('not_found')
        ->and($foreign->ok)->toBeFalse()->and($foreign->error['code'])->toBe('not_found')
        ->and($missing->ok)->toBeFalse()->and($missing->error['code'])->toBe('validation')
        ->and($this->page->fresh()->published_revision_id)->toBe($this->page->published_revision_id);
});

it('reports stale_revision with current_revision_id through get_job_status', function () {
    $ref = app(EditorJobStatus::class)->start($this->site, 'generate_image');
    app(EditorJobStatus::class)->stale($ref, 21);

    $result = app(EditorOperations::class)->run(
        new EditorContext($this->actor, $this->site, ActorChannel::Webmcp),
        'get_job_status',
        ['job_ref' => $ref],
    );

    expect($result->ok)->toBeTrue()
        ->and($result->data)->toBe([
            'status' => 'stale_revision',
            'result' => null,
            'error' => null,
            'current_revision_id' => 21,
        ]);
});

it('never demotes a terminal status and clears stale fields on done/failed', function () {
    $site = \App\Models\Site::factory()->create();
    $status = app(\App\Services\Site\Editor\EditorJobStatus::class);
    $ref = $status->start($site, 'image');
    $status->stale($ref, 42);
    $status->done($ref, ['media_id' => 1]);
    expect($status->get($site, $ref)['status'])->toBe('done')
        ->and(array_key_exists('current_revision_id', $status->get($site, $ref)))->toBeFalse();
    $status->running($ref);
    expect($status->get($site, $ref)['status'])->toBe('done');
    $ref2 = $status->start($site, 'image');
    $status->done($ref2, ['media_id' => 2]);
    $status->failed($ref2, 'boom');
    expect($status->get($site, $ref2)['status'])->toBe('failed')->and($status->get($site, $ref2)['result'])->toBeNull();
});

it('rejects a malformed cached site_id under the strict comparison', function () {
    $site = \App\Models\Site::factory()->create();
    $status = app(\App\Services\Site\Editor\EditorJobStatus::class);
    $ref = $status->start($site, 'image');
    $payload = \Illuminate\Support\Facades\Cache::get("editor:job:{$ref}");
    $payload['site_id'] = $site->id.'junk';
    \Illuminate\Support\Facades\Cache::put("editor:job:{$ref}", $payload, 60);
    expect($status->get($site, $ref))->toBeNull();
    expect($status->get($site, 'not-a-ref'))->toBeNull();
});

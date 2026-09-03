<?php

use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\LogoConcept;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\SiteMedia;
use App\Models\User;
use App\Services\Site\Editor\EditorState;
use App\Services\Site\Editor\EditorJobStatus;
use App\Services\Site\Editor\OperationResult;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\EditorSeeds;

beforeEach(function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $this->withoutVite();
    Storage::fake(config('filesystems.default'));
});

function editorRoutePngBytes(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAEAQMAAACTPww9AAAAIGNIUk0AAHomAACAhAAA+gAAAIDoAAB1MAAA6mAAADqYAAAXcJy6UTwAAAAGUExURf8AAP///0EdNBEAAAABYktHRAH/Ai3eAAAAB3RJTUUH6ggcBTkHx5/rUAAAAAtJREFUCNdjYIAAAAAIAAEvIN0xAAAAAElFTkSuQmCC');
}

it('exposes every Front 1 route to staff and dispatches the read routes', function () {
    [$staff, $site, $page] = EditorSeeds::site();
    $jobRef = app(EditorJobStatus::class)->start($site, 'image');

    $this->actingAs($staff)
        ->getJson(route('site.editor.preview-url', [$site, $page]))
        ->assertOk()
        ->assertJsonStructure(['url']);

    $previewUrl = $this->getJson(route('site.editor.preview-url', [$site, $page]))->json('url');
    expect(URL::hasValidSignature(request()->create($previewUrl)))->toBeTrue();

    $this->getJson(route('site.editor.structure', [$site, $page]))
        ->assertOk()
        ->assertJsonPath('ok', true);
    $this->getJson(route('site.editor.brand-context', $site))
        ->assertOk()
        ->assertJsonPath('ok', true);
    $this->getJson(route('site.editor.image-versions', [$site, 'scope' => 'logo']))
        ->assertOk()
        ->assertJsonPath('ok', true);
    $this->getJson(route('site.editor.job-status', [$site, 'ref' => $jobRef]))
        ->assertOk()
        ->assertJsonPath('ok', true);
});

it('runs sections and both restore routes with their distinct revision bases', function () {
    [$staff, $site, $page] = EditorSeeds::site();

    $this->actingAs($staff)
        ->postJson(route('site.editor.sections', [$site, $page]), [
            'op' => 'add',
            'type' => 'trust',
            'position' => 1,
            'revision_base' => $page->published_revision_id,
            'structure_epoch' => 0,
        ])->assertOk()->assertJsonPath('ok', true);

    $hero = HeroVersion::factory()->for($site)->create(['page_type' => 'home', 'slot' => 'hero']);
    $this->postJson(route('site.editor.restore-image-version', $site), [
        'scope' => 'hero',
        'version_id' => $hero->id,
        'page_type' => 'home',
        'slot' => 'hero',
        'composition_revision' => (string) SiteDraft::query()->where('site_id', $site->id)->value('admin_revision'),
    ])->assertOk()->assertJsonPath('ok', true);

    $page->refresh();
    $media = SiteMedia::factory()->for($site)->create();
    $this->withHeader('X-Page-Revision-Base', (string) $page->draft_revision_id)
        ->postJson(route('site.editor.restore-media-version', [$site, $page]), [
            'stored_index' => 0,
            'field_path' => 'background_image',
            'media_id' => $media->id,
            'structure_epoch' => $page->structure_epoch,
        ])->assertOk()->assertJsonPath('ok', true);
});

it('runs every generation and logo selection route for staff', function () {
    Queue::fake();
    Bus::fake();
    [$staff, $site, $page] = EditorSeeds::site();
    $logo = LogoConcept::factory()->for($site)->create();

    $this->actingAs($staff)
        ->postJson(route('site.editor.select-logo', $site), [
            'concept_id' => $logo->id,
            'composition_revision' => 0,
        ])->assertOk()->assertJsonPath('ok', true);

    $compositionRevision = fn (): int => (int) SiteDraft::query()
        ->where('site_id', $site->id)
        ->value('admin_revision');

    $this->postJson(route('site.editor.generate-image', $site), [
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'background_image',
        'prompt_hint' => 'route-'.Str::ulid(),
        'composition_revision' => $compositionRevision(),
    ])->assertOk()->assertJsonPath('ok', true)->assertJsonStructure(['data' => ['job_ref']]);

    $this->postJson(route('site.editor.generate-hero', $site), [
        'page_type' => 'home',
        'custom_prompt' => 'route-'.Str::ulid(),
        'target' => 'hero',
        'composition_revision' => $compositionRevision(),
    ])->assertOk()->assertJsonPath('ok', true)->assertJsonStructure(['data' => ['job_ref']]);

    $this->postJson(route('site.editor.generate-logo', $site), [
        'composition_revision' => $compositionRevision(),
    ])->assertOk()->assertJsonPath('ok', true)->assertJsonStructure(['data' => ['job_ref']]);
});

it('requires a page revision base before dispatching a section write', function () {
    [$staff, $site, $page] = EditorSeeds::site();

    $this->actingAs($staff)
        ->postJson(route('site.editor.sections', [$site, $page]), [
            'op' => 'remove',
            'stored_index' => 1,
            'structure_epoch' => 0,
        ])->assertConflict()->assertExactJson([
            'ok' => false,
            'error' => [
                'code' => 'stale_revision',
                'message' => 'revision base required',
            ],
        ]);
});

it('gates new routes by the UI flag without changing legacy routes', function () {
    [$staff, $site, $page] = EditorSeeds::site();
    // The UI flag is `editor.operations.enabled` since the flags were split. This test toggled
    // `agent_tools.enabled` back when one flag governed both; that now controls agents only, and
    // switching it must NOT take the human layer down with it.
    config(['editor.operations.enabled' => false]);

    $this->actingAs($staff)
        ->getJson(route('site.editor.structure', [$site, $page]))
        ->assertForbidden();

    $legacyResponse = $this->postJson(route('site.admin.field-update', [$site, $page]), [
        'section_index' => 0,
        'field_path' => 'title',
        'value' => 'Legacy path',
    ])->assertOk();

    expect(array_keys($legacyResponse->json()))->toBe(['html', 'page_id', 'draft_revision_id']);
});

it('forbids a client user from another clients site', function () {
    [$staff, $site, $page] = EditorSeeds::site();
    $siteClient = Client::factory()->create();
    $otherClient = Client::factory()->create();
    $site->update(['client_id' => $siteClient->id]);
    $clientUser = User::factory()->create([
        'client_id' => $otherClient->id,
        'role' => null,
        'last_login_at' => now(),
    ]);

    $this->actingAs($clientUser)
        ->getJson(route('site.editor.structure', [$site, $page]))
        ->assertForbidden();
});

it('keeps preview URL page scoping strict', function () {
    [$staff, $site, $page] = EditorSeeds::site();
    $foreignPage = GeneratedPage::factory()->create();

    $this->actingAs($staff)
        ->getJson(route('site.editor.preview-url', [$site, $foreignPage]))
        ->assertNotFound();

    $page->update(['archived_at' => now()]);
    $this->getJson(route('site.editor.preview-url', [$site, $page]))
        ->assertNotFound();
});

it('accepts JSON base64 uploads while preserving the multipart response', function () {
    [$staff, $site] = EditorSeeds::site();

    $this->actingAs($staff)
        ->postJson(route('site.admin.media-upload', $site), [
            'data_base64' => base64_encode(editorRoutePngBytes()),
            'composition_revision' => 0,
        ])->assertOk()->assertJsonPath('ok', true)->assertJsonStructure([
            'data' => ['media_id', 'url'],
        ]);

    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('photo.png', editorRoutePngBytes());
    $this->post(route('site.admin.media-upload', $site), ['file' => $file])
        ->assertOk()
        ->assertJsonStructure(['path', 'url', 'id'])
        ->assertJsonMissingPath('ok');
});

it('passes stored_index through media version queries so the active badge is exact', function () {
    [$staff, $site, $page] = EditorSeeds::site();
    $first = SiteMedia::factory()->for($site)->create();
    $second = SiteMedia::factory()->for($site)->create();
    $content = ['sections' => [
        ['type' => 'hero', 'background_image' => $first->url],
        ['type' => 'hero', 'background_image' => $second->url],
    ]];
    PageRevision::query()->whereKey($page->published_revision_id)->update(['content_data' => $content]);

    $response = $this->actingAs($staff)->getJson(route('site.editor.image-versions', [
        'site' => $site,
        'scope' => 'media',
        'page_id' => $page->id,
        'stored_index' => 1,
        'field_path' => 'background_image',
    ]))->assertOk();

    $versions = collect($response->json('data.versions'));
    expect($versions->firstWhere('id', $second->id)['active'])->toBeTrue()
        ->and($versions->firstWhere('id', $first->id)['active'])->toBeFalse();
});

it('keeps form validation order, response keys, and snapshot mirroring on the legacy route', function () {
    [$staff, $site, $page] = EditorSeeds::site();
    $content = ['sections' => [[
        'type' => 'contact_form',
        'title' => 'Old title',
        'fields' => [],
    ]]];
    PageRevision::query()->whereKey($page->published_revision_id)->update(['content_data' => $content]);
    $page->update(['content_data' => $content]);
    $preview = Preview::factory()->for($site)->create(['snapshot' => ['pages' => []]]);

    $this->actingAs($staff)
        ->postJson(route('site.admin.form-update', [$site, $page, 'section' => 0]), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fields');

    $response = $this->withHeader('X-Page-Revision-Base', (string) $page->published_revision_id)
        ->postJson(route('site.admin.form-update', [$site, $page, 'section' => 0]), [
            'title' => 'Mirrored title',
            'fields' => [],
        ])->assertOk();

    expect(array_keys($response->json()))->toBe(['status', 'revision_id', 'html'])
        ->and($preview->fresh()->snapshot['pages']['home']['contact_form']['title'])->toBe('Mirrored title');
});

it('maps every operation error code to its Front 1 HTTP status', function (string $code, int $status) {
    $state = new EditorState(1, null, null, 0, false);
    $result = OperationResult::fail($code, 'test', $state);

    expect(\App\Http\Controllers\Site\Editor\EditorOperationController::statusFor($result))->toBe($status);
})->with([
    'stale_revision' => ['stale_revision', 409],
    'revision_conflict' => ['revision_conflict', 409],
    'plan_stale' => ['plan_stale', 409],
    'job_running' => ['job_running', 409],
    'forbidden' => ['forbidden', 403],
    'not_found' => ['not_found', 404],
    'validation' => ['validation', 422],
    'unsupported_field' => ['unsupported_field', 422],
    'quota_exceeded' => ['quota_exceeded', 429],
    'internal' => ['internal', 500],
]);

it('never leaks the target site state to an unauthorised actor and refuses archived pages', function () {
    ['user' => $owner, 'site' => $site, 'page' => $page] = ($this->seedEditorRoutesSite ?? null) ? ($this->seedEditorRoutesSite)() : [
        'user' => null, 'site' => null, 'page' => null,
    ];
    if ($owner === null) {
        [$owner, $site, $page] = \Tests\Support\EditorSeeds::site();
    }
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);

    // A verified user from another client gets a forbidden envelope carrying NO state about this site.
    $stranger = \App\Models\User::factory()->staff(\App\Enums\AgentRole::Agent)->create();
    $result = \Tests\Support\EditorSeeds::run($stranger, $site, 'get_page_structure', ['page_id' => $page->id]);
    expect($result->error['code'])->toBe('forbidden')
        ->and($result->state->compositionRevision)->toBe(0)
        ->and($result->state->draftRevisionId)->toBeNull()
        ->and($result->state->pageId)->toBeNull()
        ->and($result->state->pendingPublish)->toBeFalse();

    // Archived pages are not editable through the structure ops.
    $page->update(['archived_at' => now()]);
    $archived = \Tests\Support\EditorSeeds::run($owner, $site, 'move_section', [
        'page_id' => $page->id, 'from' => 0, 'to' => 1, 'revision_base' => $page->published_revision_id, 'structure_epoch' => 0,
    ]);
    expect($archived->ok)->toBeFalse()->and($archived->error['code'])->toBe('not_found');
});

it('answers an unauthorised caller with 403 before any preflight 409 or validation 422', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    [$owner, $site, $page] = \Tests\Support\EditorSeeds::site();
    $stranger = \App\Models\User::factory()->staff(\App\Enums\AgentRole::Agent)->create();

    // A page write with NO revision base would be 409 for the owner; a stranger must get 403 instead.
    $this->actingAs($stranger)
        ->postJson("/sites/{$site->id}/pages/{$page->id}/sections", ['op' => 'move', 'from' => 0, 'to' => 1])
        ->assertForbidden()
        ->assertExactJson([
            'ok' => false,
            'error' => ['code' => 'forbidden', 'message' => 'Not allowed on this site.'],
            'state' => [
                'site_id' => $site->id,
                'page_id' => null,
                'draft_revision_id' => null,
                'composition_revision' => 0,
                'pending_publish' => false,
                'structure_epoch' => null,
            ],
            'receipt' => [
                'new_revision' => null,
                'effective' => null,
                'changed' => [],
                'warnings' => [],
                'publishable' => false,
                'preview' => 'not_applicable',
            ],
        ]);

    // A malformed body would be 422 for the owner; a stranger still gets 403.
    $this->actingAs($stranger)
        ->postJson("/sites/{$site->id}/logo/select", ['concept_id' => 'not-an-int'])
        ->assertStatus(403);

    // The owner still gets the ordinary preflight answer.
    $this->actingAs($owner)
        ->postJson("/sites/{$site->id}/pages/{$page->id}/sections", ['op' => 'move', 'from' => 0, 'to' => 1])
        ->assertStatus(409);
});

it('records an agent-declared write as the webmcp channel and applies the webmcp role gate', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true, 'editor.agent_tools.roles' => ['staff']]);
    [$owner, $site, $page] = \Tests\Support\EditorSeeds::site();

    $move = function (array $headers) use ($owner, $site, $page) {
        $this->flushHeaders(); // withHeaders() is sticky across requests in one test
        $fresh = $page->fresh();

        return $this->actingAs($owner)
            ->withHeaders($headers + ['X-Page-Revision-Base' => (string) ($fresh->draft_revision_id ?? $fresh->published_revision_id)])
            ->postJson("/sites/{$site->id}/pages/{$page->id}/sections", [
                'op' => 'move', 'from' => 0, 'to' => 1, 'structure_epoch' => (int) $fresh->structure_epoch,
            ]);
    };

    // Declared agent write by a staff actor: allowed, and audited as webmcp (not as a human UI edit).
    $move(['X-Editor-Channel' => 'webmcp'])->assertOk();
    expect(\App\Models\Site\EditorOperationLog::query()->where('actor_channel', 'webmcp')->where('result_code', 'ok')->count())->toBe(1);

    // Same header, but the actor's class is not in the agent-tools roles → the webmcp gate refuses.
    config(['editor.agent_tools.roles' => ['client']]);
    $move(['X-Editor-Channel' => 'webmcp'])->assertStatus(403);

    // Without the header the same actor is the UI channel, which needs only the flag — even with roles unchanged.
    $move([])->assertOk();
    expect(\App\Models\Site\EditorOperationLog::query()->where('actor_channel', 'ui')->where('result_code', 'ok')->count())->toBe(1);
});

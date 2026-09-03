<?php

use App\Enums\AgentRole;
use App\Models\Site;
use App\Models\Site\EditorOperationLog;
use App\Models\User;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\OperationResult;

beforeEach(function () {
    config([
        'domains.agent_domain' => 'editor-probe.test',
        'editor.agent_tools.enabled' => true,
        'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);
});

it('dispatches an operation without a v1 route through the generic Front 1 endpoint', function () {
    $operation = new class extends BaseOperation
    {
        public function name(): string
        {
            return 'future_front_probe';
        }

        public function readOnly(): bool
        {
            return true;
        }

        public function wrapInAdminChange(): bool
        {
            return false;
        }

        public function address(): string
        {
            return 'site';
        }

        public function sideEffects(): string
        {
            return 'Returns a fixed probe payload.';
        }

        public function inputSchema(): array
        {
            return ['type' => 'object'];
        }

        public function handle(EditorContext $ctx, array $input): OperationResult
        {
            return OperationResult::ok([
                'probe' => ($input['marker'] ?? null) === 'route-input-913'
                    ? 'front-1-output-271'
                    : 'wrong-input',
                'include_changes' => $ctx->includeChanges,
                'input_keys' => array_keys($input),
                'parent_origin' => $input['parent_origin'] ?? null,
            ], app(EditorStateFactory::class)->for($ctx->site, null));
        }
    };
    app()->instance(OperationRegistry::class, new OperationRegistry([$operation]));

    $actor = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $actor->id]);

    $this->actingAs($actor)
        ->postJson("/sites/{$site->id}/operations/future_front_probe?include_changes=1", [
            'marker' => 'route-input-913',
        ])
        ->assertOk()
        ->assertExactJson([
            'ok' => true,
            'data' => [
                'probe' => 'front-1-output-271',
                'include_changes' => true,
                'input_keys' => ['marker', 'parent_origin'],
                'parent_origin' => 'https://editor-probe.test',
            ],
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

    $this->assertDatabaseHas('editor_operation_log', [
        'site_id' => $site->id,
        'actor_user_id' => $actor->id,
        'actor_channel' => 'ui',
        'operation' => 'future_front_probe',
        'result_code' => 'ok',
    ]);
});

it('route-misses operation names longer than the audit column without returning an operation envelope', function () {
    $actor = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $actor->id]);
    $overlongOperation = str_repeat('x', 65);

    $response = $this->actingAs($actor)
        ->postJson("/sites/{$site->id}/operations/{$overlongOperation}");

    // Independent oracle: the router rejects this syntax before the operations layer can construct an
    // envelope. Wrong implementation caught: no route constraint, which reaches the audit varchar and 500s.
    $response->assertNotFound()
        ->assertJsonMissingPath('ok')
        ->assertJsonMissingPath('error');

    expect(EditorOperationLog::query()->where('site_id', $site->id)->count())->toBe(0);
});

it('lets the operation layer choose a maximum-length unknown-operation response by ability without an existence oracle', function () {
    $viewer = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $viewer->id]);
    $unknownOperation = str_repeat('x', 64);

    $viewerResponse = $this->actingAs($viewer)
        ->postJson("/sites/{$site->id}/operations/{$unknownOperation}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $stranger = User::factory()->staff(AgentRole::Agent)->create();

    $strangerUnknownResponse = $this->actingAs($stranger)
        ->postJson("/sites/{$site->id}/operations/{$unknownOperation}")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    $strangerKnownReadResponse = $this->actingAs($stranger)
        ->postJson("/sites/{$site->id}/operations/get_brand_context")
        ->assertForbidden();

    $strangerKnownWriteResponse = $this->actingAs($stranger)
        ->postJson("/sites/{$site->id}/operations/edit_field")
        ->assertForbidden();

    // Independent oracle: policy ability alone selects 404 for the owner and 403 for the stranger.
    // Wrong implementation caught: a registry-membership route constraint, which route-misses the unknown
    // name before policy evaluation and makes the stranger's known and unknown envelopes distinguishable.
    expect($viewerResponse->status())->toBe(404)
        ->and($strangerUnknownResponse->status())->toBe(403)
        ->and($strangerUnknownResponse->getContent())->toBe($strangerKnownReadResponse->getContent())
        ->and($strangerUnknownResponse->getContent())->toBe($strangerKnownWriteResponse->getContent());

    expect(EditorOperationLog::query()
        ->where('site_id', $site->id)
        ->orderBy('id')
        ->get(['actor_user_id', 'result_code'])
        ->map->toArray()
        ->all())->toBe([
            ['actor_user_id' => $viewer->id, 'result_code' => 'not_found'],
            ['actor_user_id' => $stranger->id, 'result_code' => 'forbidden'],
            ['actor_user_id' => $stranger->id, 'result_code' => 'forbidden'],
            ['actor_user_id' => $stranger->id, 'result_code' => 'forbidden'],
        ]);
});

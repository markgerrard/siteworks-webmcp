<?php

use App\Models\Site;
use App\Models\Site\EditorAgentApproval;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\ApprovalPresentation;
use App\Services\Site\Editor\ApprovalStore;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\MintRefused;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function approvalContext(ActorChannel $channel = ActorChannel::Webmcp): EditorContext
{
    $user = User::factory()->staff()->create();
    $site = Site::factory()->for($user, 'createdBy')->create();

    return new EditorContext($user, $site, $channel);
}

/**
 * @param  Closure(string): string  $work
 * @return list<string>
 */
function runApprovalWorkConcurrently(Closure $work): array
{
    if (! function_exists('pcntl_fork')) {
        throw new RuntimeException('Approval concurrency tests require pcntl.');
    }

    $children = [];

    foreach (['approval_a', 'approval_b'] as $connectionName) {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new RuntimeException('Unable to create concurrency barrier.');
        }

        [$parentSocket, $childSocket] = $sockets;
        $childPid = pcntl_fork();

        if ($childPid === 0) {
            fclose($parentSocket);
            try {
                stream_set_timeout($childSocket, 5);
                if (trim((string) fgets($childSocket)) !== 'go') {
                    throw new RuntimeException('Concurrency barrier was not released.');
                }

                config(["database.connections.{$connectionName}" => config('database.connections.pgsql')]);
                DB::setDefaultConnection($connectionName);
                DB::purge($connectionName);
                fwrite($childSocket, $work($connectionName)."\n");
            } catch (Throwable $exception) {
                @fwrite($childSocket, 'error:'.$exception->getMessage()."\n");
            }

            fclose($childSocket);
            exit(0);
        }

        fclose($childSocket);
        $children[] = [$childPid, $parentSocket];
    }

    foreach ($children as [, $socket]) {
        fwrite($socket, "go\n");
    }

    $results = [];
    foreach ($children as [$childPid, $socket]) {
        stream_set_timeout($socket, 10);
        $results[] = trim((string) fgets($socket));
        fclose($socket);
        pcntl_waitpid($childPid, $status);
    }

    return $results;
}

it('creates the approval table with derived-state columns and indexes', function () {
    $columns = collect(DB::select("select column_name from information_schema.columns where table_name = 'editor_agent_approvals'"))
        ->pluck('column_name')
        ->all();

    expect($columns)->toContain(
        'id', 'kind', 'site_id', 'requested_by_user_id', 'requested_by_identifier',
        'approved_by_user_id', 'approved_by_identifier', 'channel', 'grant_principal',
        'operation', 'args_hash', 'summary', 'requested_at', 'approved_at', 'denied_at',
        'consumed_at', 'expires_at',
    )->not->toContain('status');
});

it('hashes canonical arguments while ignoring only concurrency metadata', function () {
    $store = app(ApprovalStore::class);
    $left = [
        'image_model' => 'demo-image:low',
        'nested' => ['z' => 2, 'a' => ['second' => 2, 'first' => 1]],
        'data_base64' => 'first-bytes',
        'revision_base' => 4,
        'structure_epoch' => 5,
        'composition_revision' => 6,
        'parent_origin' => 'https://one.example',
        'approval_request_id' => Str::uuid()->toString(),
    ];
    $reordered = [
        'approval_request_id' => Str::uuid()->toString(),
        'parent_origin' => 'https://two.example',
        'composition_revision' => 99,
        'structure_epoch' => 98,
        'revision_base' => 97,
        'data_base64' => 'first-bytes',
        'nested' => ['a' => ['first' => 1, 'second' => 2], 'z' => 2],
        'image_model' => 'demo-image:low',
    ];

    $hash = $store->argsHash('upload_image', $left);

    expect($hash)->toBe($store->argsHash('upload_image', $reordered))
        ->not->toBe($store->argsHash('upload_image', [...$reordered, 'data_base64' => 'different-bytes']))
        ->not->toBe($store->argsHash('upload_image', [...$reordered, 'image_model' => 'demo-image:medium']))
        ->not->toBe($store->argsHash('regenerate_hero', $reordered));
});

it('binds structure epochs only for assigning image operations', function () {
    $store = app(ApprovalStore::class);
    $assignment = [
        'page_id' => 14,
        'stored_index' => 3,
        'field_path' => 'items.0.image',
        'revision_base' => 20,
        'structure_epoch' => 5,
    ];

    expect($store->argsHash('upload_image', $assignment))
        ->not->toBe($store->argsHash('upload_image', [...$assignment, 'structure_epoch' => 6]))
        ->and($store->argsHash('upload_image', ['data_base64' => 'bytes', 'structure_epoch' => 5]))
        ->toBe($store->argsHash('upload_image', ['data_base64' => 'bytes', 'structure_epoch' => 6]));
});

it('binds scope and version id into approval argument hashes', function () {
    $store = app(ApprovalStore::class);
    $input = ['scope' => 'hero', 'version_id' => 42];
    $hash = $store->argsHash('restore_image_version', $input);

    expect($hash)
        ->not->toBe($store->argsHash('restore_image_version', [...$input, 'scope' => 'logo']))
        ->not->toBe($store->argsHash('restore_image_version', [...$input, 'version_id' => 43]));
});

it('deduplicates live pending requests and mints after terminal states', function () {
    config()->set('editor.agent_approval.denied_cooldown_minutes', 0);
    $ctx = approvalContext();
    $store = app(ApprovalStore::class);
    $input = ['scope' => 'hero', 'version_id' => 42];

    $pending = $store->mint($ctx, 'principal-a', 'restore_image_version', $input);
    $duplicate = $store->mint($ctx, 'principal-a', 'restore_image_version', $input);
    expect($duplicate->id)->toBe($pending->id);

    $pending->forceFill(['consumed_at' => now()])->save();
    $afterConsumed = $store->mint($ctx, 'principal-a', 'restore_image_version', $input);
    expect($afterConsumed->id)->not->toBe($pending->id);

    $afterConsumed->forceFill(['denied_at' => now()])->save();
    $afterDenied = $store->mint($ctx, 'principal-a', 'restore_image_version', $input);
    expect($afterDenied->id)->not->toBe($afterConsumed->id);

    $afterDenied->forceFill(['expires_at' => now()->subSecond(), 'denied_at' => null])->save();
    expect($store->mint($ctx, 'principal-a', 'restore_image_version', $input)->id)
        ->not->toBe($afterDenied->id);
});

it('refuses denied cooldown and pending limit without inserting', function () {
    config()->set('editor.agent_approval.pending_limit', 1);
    config()->set('editor.agent_approval.denied_cooldown_minutes', 30);
    $ctx = approvalContext();
    $store = app(ApprovalStore::class);

    $denied = $store->mint($ctx, 'principal-a', 'restore_image_version', ['version_id' => 1]);
    $denied->forceFill(['denied_at' => now()])->save();
    $count = EditorAgentApproval::count();
    $cooldown = $store->mint($ctx, 'principal-a', 'restore_image_version', ['version_id' => 1]);
    expect($cooldown)->toBeInstanceOf(MintRefused::class)
        ->and($cooldown->reason)->toBe('denied_cooldown')
        ->and(EditorAgentApproval::count())->toBe($count);

    $store->mint($ctx, 'principal-a', 'restore_image_version', ['version_id' => 2]);
    $count = EditorAgentApproval::count();
    $limited = $store->mint($ctx, 'principal-a', 'restore_image_version', ['version_id' => 3]);
    expect($limited)->toBeInstanceOf(MintRefused::class)
        ->and($limited->reason)->toBe('pending_limit')
        ->and(EditorAgentApproval::count())->toBe($count);
});

it('locks only approval rows that can affect mint decisions', function () {
    config()->set('editor.agent_approval.denied_cooldown_minutes', 30);
    $ctx = approvalContext();
    $lockedQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$lockedQueries): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'editor_agent_approvals') && str_contains($sql, 'for update')) {
            $lockedQueries[] = $sql;
        }
    });

    app(ApprovalStore::class)->mint(
        $ctx,
        'principal-a',
        'restore_image_version',
        ['version_id' => 1],
    );

    expect($lockedQueries)->toHaveCount(1)
        ->and($lockedQueries[0])->toContain(
            '"kind" = ?',
            '"approved_at" is null',
            '"denied_at" is null',
            '"consumed_at" is null',
            '"expires_at" > ?',
            '"denied_at" > ?',
        );
});

it('verifies spendable approvals without exposing why verification failed', function () {
    $ctx = approvalContext();
    $otherCtx = approvalContext();
    $store = app(ApprovalStore::class);
    $input = ['scope' => 'hero', 'version_id' => 42];
    $approval = $store->mint($ctx, 'principal-a', 'restore_image_version', $input);
    $approval->forceFill(['approved_at' => now()])->save();

    expect($store->verify($ctx, 'principal-a', 'restore_image_version', $input, $approval->id)?->id)->toBe($approval->id)
        ->and($store->verify($ctx, 'principal-b', 'restore_image_version', $input, $approval->id))->toBeNull()
        ->and($store->verify(new EditorContext($otherCtx->actor, $ctx->site, $ctx->channel), 'principal-a', 'restore_image_version', $input, $approval->id))->toBeNull()
        ->and($store->verify(new EditorContext($ctx->actor, $otherCtx->site, $ctx->channel), 'principal-a', 'restore_image_version', $input, $approval->id))->toBeNull()
        ->and($store->verify(new EditorContext($ctx->actor, $ctx->site, ActorChannel::Mcp), 'principal-a', 'restore_image_version', $input, $approval->id))->toBeNull()
        ->and($store->verify($ctx, 'principal-a', 'restore_image_version', ['version_id' => 43], $approval->id))->toBeNull()
        ->and($store->verify($ctx, 'principal-a', 'regenerate_hero', $input, $approval->id))->toBeNull()
        ->and($store->verify($ctx, 'principal-a', 'restore_image_version', $input, 'not-a-uuid'))->toBeNull();

    foreach (['denied_at', 'consumed_at'] as $terminalColumn) {
        $approval->forceFill([$terminalColumn => now()])->save();
        expect($store->verify($ctx, 'principal-a', 'restore_image_version', $input, $approval->id))->toBeNull();
        $approval->forceFill([$terminalColumn => null])->save();
    }

    $approval->forceFill(['expires_at' => now()->subSecond()])->save();
    expect($store->verify($ctx, 'principal-a', 'restore_image_version', $input, $approval->id))->toBeNull();
});

it('consumes with a database-decided conditional update', function () {
    $ctx = approvalContext();
    $store = app(ApprovalStore::class);
    $approval = $store->mint($ctx, 'principal-a', 'restore_image_version', ['version_id' => 1]);
    $approval->forceFill(['approved_at' => now()])->save();
    $approvalQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$approvalQueries): void {
        if (str_contains($query->sql, 'editor_agent_approvals')) {
            $approvalQueries[] = strtolower($query->sql);
        }
    });

    expect($store->consume($approval))->toBeTrue()
        ->and($store->consume($approval))->toBeFalse()
        ->and($approvalQueries)->toHaveCount(2)
        ->and($approvalQueries[0])->toStartWith('update')
        ->and($approvalQueries[1])->toStartWith('update');
});

it('refuses to consume a denied operation approval', function () {
    $ctx = approvalContext();
    $store = app(ApprovalStore::class);
    $approval = $store->mint($ctx, 'principal-a', 'restore_image_version', ['version_id' => 1]);
    $approval->forceFill(['approved_at' => now(), 'denied_at' => now()])->save();

    expect($store->consume($approval))->toBeFalse()
        ->and($approval->fresh()->consumed_at)->toBeNull();
});

it('refuses to consume an expired operation approval', function () {
    $ctx = approvalContext();
    $store = app(ApprovalStore::class);
    $approval = $store->mint($ctx, 'principal-a', 'restore_image_version', ['version_id' => 1]);
    $approval->forceFill(['approved_at' => now(), 'expires_at' => now()->subSecond()])->save();

    expect($store->consume($approval))->toBeFalse()
        ->and($approval->fresh()->consumed_at)->toBeNull();
});

it('refuses to consume a standing grant', function () {
    $ctx = approvalContext();
    $store = app(ApprovalStore::class);
    $grant = $store->grant($ctx->site, $ctx->actor, 'principal-a', $ctx->channel);

    expect($store->consume($grant))->toBeFalse()
        ->and($grant->fresh()->consumed_at)->toBeNull();
});

it('allows only one concurrent connection to consume an approval', function () {
    $ctx = approvalContext();
    $approval = app(ApprovalStore::class)->mint($ctx, 'principal-a', 'restore_image_version', ['version_id' => 1]);
    $approval->forceFill(['approved_at' => now()])->save();
    $approvalId = $approval->id;

    DB::commit();
    DB::disconnect();

    $results = runApprovalWorkConcurrently(function (string $connectionName) use ($approvalId): string {
        $approval = EditorAgentApproval::on($connectionName)->findOrFail($approvalId);

        return app(ApprovalStore::class)->consume($approval) ? 'consumed' : 'refused';
    });

    expect($results)->toHaveCount(2)
        ->and($results)->toContain('consumed', 'refused')
        ->and(implode('|', $results))->not->toContain('error:');
});

it('deduplicates two genuinely concurrent identical mints', function () {
    $ctx = approvalContext();
    $actorId = $ctx->actor->id;
    $siteId = $ctx->site->id;

    DB::commit();
    DB::disconnect();
    $this->beforeApplicationDestroyed(function (): void {
        RefreshDatabaseState::$migrated = true;
    });

    $results = runApprovalWorkConcurrently(function (string $connectionName) use ($actorId, $siteId): string {
        $actor = User::on($connectionName)->findOrFail($actorId);
        $site = Site::on($connectionName)->findOrFail($siteId);

        return app(ApprovalStore::class)->mint(
            new EditorContext($actor, $site, ActorChannel::Webmcp),
            'principal-a',
            'restore_image_version',
            ['version_id' => 1],
        )->id;
    });

    config(['database.default' => 'pgsql']);
    DB::purge('pgsql');

    expect($results)->toHaveCount(2)
        ->and($results[0])->toBe($results[1])
        ->and(implode('|', $results))->not->toContain('error:')
        ->and(EditorAgentApproval::query()->where('site_id', $siteId)->count())->toBe(1);
});

it('serialises concurrent mints at the pending cap', function () {
    config()->set('editor.agent_approval.pending_limit', 2);
    $ctx = approvalContext();
    app(ApprovalStore::class)->mint($ctx, 'principal-a', 'restore_image_version', ['version_id' => 1]);
    $actorId = $ctx->actor->id;
    $siteId = $ctx->site->id;

    DB::commit();
    DB::disconnect();
    $this->beforeApplicationDestroyed(function (): void {
        RefreshDatabaseState::$migrated = true;
    });

    $results = runApprovalWorkConcurrently(function (string $connectionName) use ($actorId, $siteId): string {
        $actor = User::on($connectionName)->findOrFail($actorId);
        $site = Site::on($connectionName)->findOrFail($siteId);
        $versionId = $connectionName === 'approval_a' ? 2 : 3;
        $result = app(ApprovalStore::class)->mint(
            new EditorContext($actor, $site, ActorChannel::Webmcp),
            'principal-a',
            'restore_image_version',
            ['version_id' => $versionId],
        );

        return $result instanceof MintRefused ? $result->reason : 'minted';
    });

    config(['database.default' => 'pgsql']);
    DB::purge('pgsql');

    expect($results)->toHaveCount(2)
        ->and($results)->toContain('minted', 'pending_limit')
        ->and(implode('|', $results))->not->toContain('error:')
        ->and(EditorAgentApproval::query()->where('site_id', $siteId)->where('grant_principal', 'principal-a')->count())->toBe(2);
});

it('keeps approval denial expiry and consumption terminal', function () {
    $ctx = approvalContext();
    $approver = User::factory()->staff()->create();
    $store = app(ApprovalStore::class);

    $denied = $store->mint($ctx, 'principal-a', 'restore_image_version', ['version_id' => 1]);
    expect($store->deny($denied, $approver))->toBeTrue()
        ->and($store->approve($denied, $approver))->toBeFalse();

    $expired = $store->mint($ctx, 'principal-a', 'restore_image_version', ['version_id' => 2]);
    $expired->forceFill(['expires_at' => now()->subSecond()])->save();
    expect($store->approve($expired, $approver))->toBeFalse()
        ->and($store->deny($expired, $approver))->toBeFalse();

    $consumed = $store->mint($ctx, 'principal-a', 'restore_image_version', ['version_id' => 3]);
    expect($store->approve($consumed, $approver))->toBeTrue()
        ->and($store->consume($consumed))->toBeTrue()
        ->and($store->deny($consumed, $approver))->toBeFalse();

    $grant = $store->grant($ctx->site, $ctx->actor, 'principal-a', $ctx->channel);
    expect($store->revoke($grant, $approver))->toBeTrue()
        ->and($store->revoke($grant, $approver))->toBeFalse();
});

it('preserves immutable actor snapshots when users are deleted', function () {
    $ctx = approvalContext();
    $approver = User::factory()->staff()->create();
    $store = app(ApprovalStore::class);
    $approval = $store->mint($ctx, 'principal-a', 'restore_image_version', ['version_id' => 1]);
    $store->approve($approval, $approver);
    $approval->refresh();
    $requesterIdentifier = $approval->requested_by_identifier;
    $approverIdentifier = $approval->approved_by_identifier;

    $ctx->actor->forceDelete();
    $approver->forceDelete();
    $approval->refresh();

    expect($approval->requested_by_user_id)->toBeNull()
        ->and($approval->approved_by_user_id)->toBeNull()
        ->and($approval->requested_by_identifier)->toBe($requesterIdentifier)
        ->and($approval->approved_by_identifier)->toBe($approverIdentifier);
});

it('preserves immutable deny and revoke decider snapshots when the user is deleted', function () {
    $ctx = approvalContext();
    $decider = User::factory()->staff()->create();
    $store = app(ApprovalStore::class);
    $denied = $store->mint($ctx, 'principal-a', 'restore_image_version', ['version_id' => 1]);
    $grant = $store->grant($ctx->site, $ctx->actor, 'principal-b', $ctx->channel);
    $this->actingAs($decider);

    expect($store->deny($denied))->toBeTrue()
        ->and($store->revoke($grant))->toBeTrue();

    $denied->refresh();
    $grant->refresh();
    $denierIdentifier = $denied->approved_by_identifier;
    $revokerIdentifier = $grant->approved_by_identifier;

    $decider->forceDelete();
    $denied->refresh();
    $grant->refresh();

    expect($denied->approved_by_user_id)->toBeNull()
        ->and($denied->approved_by_identifier)->toBe($denierIdentifier)->not->toBeNull()
        ->and($grant->approved_by_user_id)->toBeNull()
        ->and($grant->approved_by_identifier)->toBe($revokerIdentifier)->not->toBeNull();
});

it('creates and finds active grants scoped to principal site actor and channel', function () {
    $ctx = approvalContext();
    $store = app(ApprovalStore::class);

    $grant = $store->grant($ctx->site, $ctx->actor, 'principal-a', $ctx->channel);

    expect($grant->kind)->toBe('grant')
        ->and($grant->operation)->toBe('*')
        ->and($grant->args_hash)->toBe('')
        ->and($store->activeGrant($ctx, 'principal-a')?->id)->toBe($grant->id)
        ->and($store->activeGrant(new EditorContext($ctx->actor, $ctx->site, ActorChannel::Mcp), 'principal-a'))->toBeNull();
});

it('composes a structured bidi-safe allowlisted presentation', function () {
    if (! class_exists(Normalizer::class)) {
        $this->markTestSkipped('The intl extension is required for NFC assertions.');
    }

    $ctx = approvalContext();
    $ctx->site->forceFill(['business_name' => "Site\u{202E} Name"]);
    $operation = 'upload_image';
    $input = [
        'scope' => "he\u{0301}ro\u{202E}",
        'version_id' => 12,
        'image_model' => 'demo-image:low',
        'concept_id' => 13,
        'page_id' => 14,
        'stored_index' => 15,
        'field_path' => str_repeat('x', 5000),
        'data_base64' => 'secret bytes',
        'value' => 'site copy payload',
        'custom_prompt' => 'site copy',
    ];

    $fields = app(ApprovalPresentation::class)->for($ctx, $operation, $input);

    expect($fields)->toBeArray()
        ->and(array_keys($fields))->toBe([
            'site', 'side_effects', 'scope', 'version_id', 'image_model',
            'concept_id', 'page_id', 'stored_index', 'field_path',
            'assignment_target_binding',
        ])
        ->and($fields['scope'])->toBe('héro')
        ->and($fields['image_model'])->toBe('demo-image:low')
        ->and(mb_strlen($fields['field_path']))->toBe(120)
        ->and(json_encode($fields))->not->toContain('secret bytes', 'site copy payload', 'custom_prompt', "\u{202E}");
});

it('marks assigning image targets as not bound to stable identifiers', function (string $operation, array $input) {
    $fields = app(ApprovalPresentation::class)->for(approvalContext(), $operation, $input);

    expect($fields['assignment_target_binding'] ?? null)->toBe('not_bound');
})->with([
    'upload image assignment' => ['upload_image', [
        'page_id' => 14,
        'stored_index' => 3,
        'field_path' => 'items.0.image',
    ]],
]);

it('fails closed on non-scalar allowlisted presentation input', function (mixed $value) {
    $ctx = approvalContext();

    $fields = app(ApprovalPresentation::class)->for(
        $ctx,
        'restore_image_version',
        ['scope' => $value],
    );

    expect($fields)->not->toHaveKey('scope');
})->with([
    'array' => [[]],
    'object' => [new stdClass],
    'null' => [null],
]);

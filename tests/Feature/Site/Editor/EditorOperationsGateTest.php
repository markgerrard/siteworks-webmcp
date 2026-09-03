<?php

use App\Enums\AgentRole;
use App\Models\Site;
use App\Models\User;
use App\Models\Site\EditorOperationLog;
use App\Models\Site\SiteDraft;
use App\Services\Site\Editor\{ActorChannel, EditorContext, EditorOperations, Operation, OperationRegistry, OperationResult, EditorStateFactory};
use App\Services\Site\PublishLockContext;

beforeEach(function () {
    $this->noop = new class extends \App\Services\Site\Editor\BaseOperation {
        public function name(): string { return 'noop_write'; }
        public function readOnly(): bool { return false; }
        public function sideEffects(): string { return 'none'; }
        public function inputSchema(): array { return ['type' => 'object']; }
        public function handle(EditorContext $ctx, array $input): OperationResult
        { return OperationResult::ok(['ran' => true], app(EditorStateFactory::class)->for($ctx->site, null)); }
    };
    app()->instance(OperationRegistry::class, new OperationRegistry([$this->noop]));

    // Exposure sets: these plumbing fixtures are synthetic registry operations no exposure set
    // names, and the exposure gate refuses such names for agent channels before the behaviour
    // under test is reached — so declare them exposed exactly as the flags are declared inline.
    config(['editor.exposure.sets.sandbox' => array_merge(
        (array) config('editor.exposure.sets.sandbox'),
        ['noop_write', 'noop_site_write', 'noop_generate', 'noop_generate2', 'noop_deferred'],
    )]);
});

it('denies agent channels when the flag is off — audit row only, no domain side effect', function () {
    config(['editor.agent_tools.enabled' => false]);
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $before = (int) DB::table('site_drafts')->where('site_id', $site->id)->value('admin_revision');

    $result = app(EditorOperations::class)->run(new EditorContext($user, $site, ActorChannel::Webmcp), 'noop_write', []);

    expect($result->ok)->toBeFalse()->and($result->error['code'])->toBe('forbidden')
        ->and((int) DB::table('site_drafts')->where('site_id', $site->id)->value('admin_revision'))->toBe($before)
        ->and(EditorOperationLog::query()->where('operation', 'noop_write')->where('result_code', 'forbidden')->exists())->toBeTrue();
});

it('denies clients when roles is staff, and still denies a non-sandbox mcp op when roles includes client', function () {
    $client = \App\Models\Client::factory()->create();
    $user = User::factory()->create(['client_id' => $client->id, 'role' => null]);
    $site = Site::factory()->create(['client_id' => $client->id]);
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true, 'editor.agent_tools.roles' => ['staff']]);
    expect(app(EditorOperations::class)->run(new EditorContext($user, $site, ActorChannel::Mcp), 'noop_write', [])->error['code'])->toBe('forbidden');
    config(['editor.agent_tools.roles' => ['staff', 'client']]);
    expect(app(EditorOperations::class)->run(new EditorContext($user, $site, ActorChannel::Mcp), 'noop_write', [])->error['code'])->toBe('forbidden');
});

it('declines auto-publish when an editor write lands inside a service-page batch window', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $preBatchRev = (int) (DB::table('site_drafts')->where('site_id', $site->id)->value('admin_revision') ?? 0);
    app(EditorOperations::class)->run(new EditorContext($user, $site, ActorChannel::Webmcp), 'noop_write', []);
    $publish = \Mockery::mock(\App\Services\Site\SitePublishService::class);
    $publish->shouldReceive('lockForPublish')->once()->andReturnUsing(function (Site $locked) use ($site): PublishLockContext {
        return new PublishLockContext(
            site: $locked,
            draft: SiteDraft::query()->where('site_id', $site->id)->first(),
            selections: collect(),
        );
    });
    $publish->shouldNotReceive('publishSite');
    (new \App\Services\Site\AutoPublishCoordinator($publish))->finalizeAfterBatch($site->id, $preBatchRev, $user->id, 'batch-test', 1);
    // finalizeAfterBatch logs auto_publish_decision with reason admin_revision_changed and does not publish
});

it('runs a write for staff inside applyAdminChange, bumps admin_revision, leaves the public cache generation alone, and logs it', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true, 'editor.agent_tools.roles' => ['staff']]);
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $cache = app(\App\Services\Site\PublicPageCache::class);
    $generationBefore = $cache->generation($site); // added in this task (Files) — the counter invalidate() increments
    $result = app(EditorOperations::class)->run(new EditorContext($user, $site, ActorChannel::Webmcp), 'noop_write', []);
    $revAfter = (int) DB::table('site_drafts')->where('site_id', $site->id)->value('admin_revision');
    expect($result->ok)->toBeTrue()
        ->and($revAfter)->toBeGreaterThan(0)
        ->and($result->state->compositionRevision)->toBe($revAfter) // state is re-read AFTER the bump
        ->and($cache->generation($site))->toBe($generationBefore)
        ->and(EditorOperationLog::query()->where('result_code', 'ok')->where('actor_channel', 'webmcp')->count())->toBe(1);
});

it('rejects a stale composition_revision on a site-level write before handle() runs', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $siteLevel = new class extends \App\Services\Site\Editor\BaseOperation {
        public bool $ran = false;
        public function name(): string { return 'noop_site_write'; }
        public function readOnly(): bool { return false; }
        public function address(): string { return 'site'; }
        public function sideEffects(): string { return 'none'; }
        public function inputSchema(): array { return ['type' => 'object']; }
        public function handle(EditorContext $ctx, array $input): OperationResult
        { $this->ran = true; return OperationResult::ok([], app(EditorStateFactory::class)->for($ctx->site, null)); }
    };
    app()->instance(OperationRegistry::class, new OperationRegistry([$siteLevel]));
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $ctx = new EditorContext($user, $site, ActorChannel::Webmcp);
    $stale = app(EditorOperations::class)->run($ctx, 'noop_site_write', ['composition_revision' => 999]);
    expect($stale->error['code'])->toBe('stale_revision')->and($stale->error['current_composition_revision'])->toBe(0)->and($siteLevel->ran)->toBeFalse()
        ->and((int) (DB::table('site_drafts')->where('site_id', $site->id)->value('admin_revision') ?? 0))->toBe(0); // stale never bumps
    expect(app(EditorOperations::class)->run($ctx, 'noop_site_write', [])->error['code'])->toBe('validation');
    expect(app(EditorOperations::class)->run($ctx, 'noop_site_write', ['composition_revision' => 0])->ok)->toBeTrue()->and($siteLevel->ran)->toBeTrue();
});

it('checks the site-level base on the UNWRAPPED path without bumping admin_revision', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $gen = new class extends \App\Services\Site\Editor\BaseOperation {
        public bool $ran = false;
        public function name(): string { return 'noop_generate'; }
        public function readOnly(): bool { return false; }
        public function address(): string { return 'site'; }
        public function wrapInAdminChange(): bool { return false; }
        public function sideEffects(): string { return 'none'; }
        public function inputSchema(): array { return ['type' => 'object']; }
        public function handle(EditorContext $ctx, array $input): OperationResult
        { $this->ran = true; return OperationResult::ok(['job_ref' => 'x'], app(EditorStateFactory::class)->for($ctx->site, null)); }
    };
    app()->instance(OperationRegistry::class, new OperationRegistry([$gen]));
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $ctx = new EditorContext($user, $site, ActorChannel::Mcp);
    expect(app(EditorOperations::class)->run($ctx, 'noop_generate', ['composition_revision' => 5])->error['code'])->toBe('stale_revision')->and($gen->ran)->toBeFalse();
    expect(app(EditorOperations::class)->run($ctx, 'noop_generate', ['composition_revision' => 0])->ok)->toBeTrue()->and($gen->ran)->toBeTrue()
        ->and((int) (DB::table('site_drafts')->where('site_id', $site->id)->value('admin_revision') ?? 0))->toBe(0); // unwrapped path never bumps
});

it('runs a deferred dispatch after the unwrapped transaction returns, and never on a stale base', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $gen = new class extends \App\Services\Site\Editor\BaseOperation {
        public array $calls = [];
        public function name(): string { return 'noop_deferred'; }
        public function readOnly(): bool { return false; }
        public function address(): string { return 'site'; }
        public function wrapInAdminChange(): bool { return false; }
        public function sideEffects(): string { return 'none'; }
        public function inputSchema(): array { return ['type' => 'object']; }
        public function handle(EditorContext $ctx, array $input): OperationResult
        {
            $this->calls[] = ['handle', DB::transactionLevel()];
            $r = OperationResult::ok(['job_ref' => 'r1'], app(EditorStateFactory::class)->for($ctx->site, null));
            $r->deferred = function () use ($r): OperationResult {
                $this->calls[] = ['deferred', DB::transactionLevel()];
                return ($r->data['job_ref'] === 'r1') ? $r : OperationResult::fail('job_running', 'dup', $r->state);
            };
            return $r;
        }
    };
    app()->instance(OperationRegistry::class, new OperationRegistry([$gen]));
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $ctx = new EditorContext($user, $site, ActorChannel::Mcp);
    $base = DB::transactionLevel(); // RefreshDatabase's outer test transaction
    expect(app(EditorOperations::class)->run($ctx, 'noop_deferred', ['composition_revision' => 0])->ok)->toBeTrue();
    expect($gen->calls)->toBe([['handle', $base + 1], ['deferred', $base]]); // deferred ran after the inner transaction closed
    $gen->calls = [];
    expect(app(EditorOperations::class)->run($ctx, 'noop_deferred', ['composition_revision' => 7])->error['code'])->toBe('stale_revision');
    expect($gen->calls)->toBe([]); // neither handle nor deferred on a stale base
});

it('seeds the site_drafts row idempotently — ensureDraftRow twice yields one row and never touches an existing revision', function () {
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    DB::table('site_drafts')->where('site_id', $site->id)->delete();
    $composition = app(\App\Services\Site\CompositionService::class);
    $composition->ensureDraftRow($site, $user->id);
    $composition->ensureDraftRow($site, $user->id); // insert-or-ignore: no unique violation, no second row
    expect(DB::table('site_drafts')->where('site_id', $site->id)->count())->toBe(1)
        ->and((int) DB::table('site_drafts')->where('site_id', $site->id)->value('admin_revision'))->toBe(0);
    DB::table('site_drafts')->where('site_id', $site->id)->update(['admin_revision' => 3]);
    $composition->ensureDraftRow($site, $user->id); // existing row is never modified
    expect((int) DB::table('site_drafts')->where('site_id', $site->id)->value('admin_revision'))->toBe(3);
});

it('leaves no site_drafts row behind when an UNWRAPPED site-level call is stale, and accepts an integer-string base', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $gen = new class extends \App\Services\Site\Editor\BaseOperation {
        public function name(): string { return 'noop_generate2'; }
        public function readOnly(): bool { return false; }
        public function address(): string { return 'site'; }
        public function wrapInAdminChange(): bool { return false; }
        public function sideEffects(): string { return 'none'; }
        public function inputSchema(): array { return ['type' => 'object']; }
        public function handle(EditorContext $ctx, array $input): OperationResult
        { return OperationResult::ok([], app(EditorStateFactory::class)->for($ctx->site, null)); }
    };
    app()->instance(OperationRegistry::class, new OperationRegistry([$gen]));
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    DB::table('site_drafts')->where('site_id', $site->id)->delete();
    $ctx = new EditorContext($user, $site, ActorChannel::Mcp);
    expect(app(EditorOperations::class)->run($ctx, 'noop_generate2', ['composition_revision' => 9])->error['code'])->toBe('stale_revision')
        ->and(DB::table('site_drafts')->where('site_id', $site->id)->exists())->toBeFalse(); // rolled back with the transaction
    expect(app(EditorOperations::class)->run($ctx, 'noop_generate2', ['composition_revision' => '0'])->ok)->toBeTrue();
    expect(app(EditorOperations::class)->run($ctx, 'noop_generate2', ['composition_revision' => true])->error['code'])->toBe('validation');
    expect(app(EditorOperations::class)->run($ctx, 'noop_generate2', ['composition_revision' => '1.5'])->error['code'])->toBe('validation');
});

it('returns not_found for an unknown operation to a viewer, and forbidden to a stranger (no existence oracle)', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    expect(app(EditorOperations::class)->run(new EditorContext($user, $site, ActorChannel::Ui), 'no_such_op', [])->error['code'])->toBe('not_found');
    $stranger = User::factory()->staff(AgentRole::Agent)->create();
    expect(app(EditorOperations::class)->run(new EditorContext($stranger, Site::factory()->create(), ActorChannel::Ui), 'no_such_op', [])->error['code'])->toBe('forbidden');
    expect(EditorOperationLog::query()->where('operation', 'no_such_op')->count())->toBe(2);
});

it('survives two concurrent first-time site-level writes on a site with no site_drafts row', function () {
    // Two connections, both revision 0, both hit ensureDraftRow() → exactly one row, no unique violation, no deadlock.
    // Use a second DB connection (config('database.connections.pgsql') cloned as 'pgsql_b') and run the second
    // applyAdminChange in it while the first holds its lock; both complete within 5 s.
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    DB::table('site_drafts')->where('site_id', $site->id)->delete();
    // …two-connection choreography as in Task 13's overlap test…
    expect(DB::table('site_drafts')->where('site_id', $site->id)->count())->toBe(1);
})->todo('two-connection form: write once Task 13\'s pause seam exists (sequential proof is the test above)');

it('does not bump admin_revision when the operation fails', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $failing = new class extends \App\Services\Site\Editor\BaseOperation {
        public function name(): string { return 'noop_fail'; }
        public function readOnly(): bool { return false; }
        public function sideEffects(): string { return 'none'; }
        public function inputSchema(): array { return ['type' => 'object']; }
        public function handle(EditorContext $ctx, array $input): OperationResult
        { return OperationResult::fail('validation', 'nope', app(EditorStateFactory::class)->for($ctx->site, null)); }
    };
    app()->instance(OperationRegistry::class, new OperationRegistry([$failing]));
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $result = app(EditorOperations::class)->run(new EditorContext($user, $site, ActorChannel::Ui), 'noop_fail', []);
    expect($result->error['code'])->toBe('validation')
        ->and((int) (DB::table('site_drafts')->where('site_id', $site->id)->value('admin_revision') ?? 0))->toBe(0);
});

it('denies a user who fails SitePolicy before touching the gate', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $user = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(); // not created_by / assigned_to this agent
    $result = app(EditorOperations::class)->run(new EditorContext($user, $site, ActorChannel::Ui), 'noop_write', []);
    expect($result->error['code'])->toBe('forbidden');
});

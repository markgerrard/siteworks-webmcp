<?php

use App\Enums\AgentRole;
use App\Models\Client;
use App\Models\Site;
use App\Models\Site\EditorOperationLog;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorOperationLogRepository;
use App\Services\Site\Editor\OperationRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

it('reports the editor MCP server as registered with the flag-eligible tool count for the authenticated user', function () {
    config([
        'editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);

    $actor = User::factory()->staff(AgentRole::Agent)->create([
        'email' => 'secret-operator-email@leak.test',
        'name' => 'Secret Operator Name',
    ]);
    $expected = collect(app(OperationRegistry::class)->all())
        ->keys()
        ->map(fn (string $operation): string => "siteworks.{$operation}")
        ->sort()
        ->values();

    $this->artisan('editor:mcp-doctor', ['--user' => $actor->id])
        ->expectsOutputToContain('registered: true')
        ->expectsOutputToContain('acting_user: true')
        ->expectsOutputToContain('tool_count: '.$expected->count())
        ->expectsOutputToContain('siteworks.edit_field')
        ->expectsOutputToContain('siteworks.get_page_structure')
        ->doesntExpectOutputToContain($actor->email)
        ->doesntExpectOutputToContain($actor->name)
        ->assertSuccessful();
});

it('lists only flag-eligible tools for the authenticated user', function () {
    $staff = User::factory()->staff(AgentRole::Agent)->create([
        'email' => 'staff-leak@leak.test',
    ]);
    $client = Client::factory()->create();
    $clientUser = User::factory()->create([
        'client_id' => $client->id,
        'role' => null,
        'email' => 'client-leak@leak.test',
    ]);

    config([
        'editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);

    $this->artisan('editor:mcp-doctor', ['--user' => $clientUser->id])
        ->expectsOutputToContain('registered: true')
        ->expectsOutputToContain('acting_user: true')
        ->expectsOutputToContain('tool_count: 0')
        ->doesntExpectOutputToContain('siteworks.edit_field')
        ->doesntExpectOutputToContain($clientUser->email)
        ->assertSuccessful();

    config(['editor.agent_tools.enabled' => false]);

    $this->artisan('editor:mcp-doctor', ['--user' => $staff->id])
        ->expectsOutputToContain('registered: true')
        ->expectsOutputToContain('acting_user: true')
        ->expectsOutputToContain('tool_count: 0')
        ->doesntExpectOutputToContain('siteworks.edit_field')
        ->doesntExpectOutputToContain($staff->email)
        ->assertSuccessful();
});

it('lists registered editor MCP tool names', function () {
    config(['editor.agent_tools.enabled' => false]);

    $expected = collect(app(OperationRegistry::class)->all())
        ->keys()
        ->map(fn (string $operation): string => "siteworks.{$operation}")
        ->sort()
        ->values();

    $pending = $this->artisan('editor:mcp-tools');

    foreach ($expected as $name) {
        $pending->expectsOutputToContain($name);
    }

    $pending
        ->doesntExpectOutputToContain('siteworks.publish')
        ->assertSuccessful();
});

it('prunes editor_operation_log rows older than the configured retention', function () {
    $actor = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $actor->id]);
    $days = (int) config('editor.operation_log_retention_days');

    $old = EditorOperationLog::query()->create([
        'site_id' => $site->id,
        'page_id' => null,
        'actor_user_id' => $actor->id,
        'actor_channel' => ActorChannel::Mcp->value,
        'operation' => 'edit_field',
        'result_code' => 'ok',
        'duration_ms' => 4,
        'created_at' => now()->subDays($days + 1),
    ]);
    $fresh = EditorOperationLog::query()->create([
        'site_id' => $site->id,
        'page_id' => null,
        'actor_user_id' => $actor->id,
        'actor_channel' => ActorChannel::Mcp->value,
        'operation' => 'edit_field',
        'result_code' => 'ok',
        'duration_ms' => 4,
        'created_at' => now()->subDays($days - 1),
    ]);

    $this->artisan('editor:prune-operation-log')
        ->expectsOutputToContain('1')
        ->assertSuccessful();

    expect(EditorOperationLog::query()->find($old->id))->toBeNull()
        ->and(EditorOperationLog::query()->find($fresh->id))->not->toBeNull();
});

it('logs prune failures to the auth channel, prints the error, and exits FAILURE', function () {
    $captured = new class implements LoggerInterface
    {
        use LoggerTrait;

        /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = [
                'level' => (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ];
        }
    };

    config([
        'logging.channels.prune_capture' => [
            'driver' => 'custom',
            'via' => fn () => $captured,
        ],
        'logging.auth_channel' => 'prune_capture',
    ]);
    Log::forgetChannel('prune_capture');

    $this->app->bind(EditorOperationLogRepository::class, fn () => new class implements EditorOperationLogRepository
    {
        public function pruneOlderThan(DateTimeInterface $cutoff, int $chunk = 1000): int
        {
            throw new RuntimeException('simulated prune failure');
        }
    });

    $this->artisan('editor:prune-operation-log')
        ->expectsOutputToContain('simulated prune failure')
        ->assertExitCode(Command::FAILURE);

    expect($captured->records)->not->toBeEmpty();

    $error = collect($captured->records)->first(
        fn (array $record): bool => $record['level'] === 'error'
            && $record['message'] === 'editor_operation_log_prune_failed'
            && ($record['context']['event'] ?? null) === 'editor_operation_log_prune_failed',
    );

    expect($error)->not->toBeNull();
});

it('schedules the operation-log prune daily', function () {
    $event = collect(Schedule::events())->first(
        fn ($scheduled): bool => is_string($scheduled->command)
            && str_contains($scheduled->command, 'editor:prune-operation-log'),
    );

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *');
});

it('never prunes when retention is zero, empty, negative or absent — and prunes exactly at the boundary otherwise', function () {
    $site = \App\Models\Site::factory()->create();
    $user = \App\Models\User::factory()->staff(\App\Enums\AgentRole::Agent)->create();
    $row = fn (string $when) => \App\Models\Site\EditorOperationLog::query()->create([
        'site_id' => $site->id, 'page_id' => null, 'actor_user_id' => $user->id, 'actor_channel' => 'webmcp',
        'operation' => 'noop', 'result_code' => 'forbidden', 'duration_ms' => 1, 'created_at' => $when,
    ]);

    foreach ([0, -5, '', null] as $bad) {
        \App\Models\Site\EditorOperationLog::query()->delete();
        $row(now()->subYears(3));
        config(['editor.operation_log_retention_days' => $bad]);
        $this->artisan('editor:prune-operation-log')
            ->expectsOutputToContain('pruning is disabled')
            ->assertExitCode(0);
        expect(\App\Models\Site\EditorOperationLog::query()->count())->toBe(1, 'retention '.var_export($bad, true).' must never delete');
    }

    \App\Models\Site\EditorOperationLog::query()->delete();
    config(['editor.operation_log_retention_days' => 90]);
    $row(now()->subDays(91));   // older than the cutoff → pruned
    $row(now()->subDays(89));   // inside the window → kept
    $this->artisan('editor:prune-operation-log')->assertExitCode(0);
    expect(\App\Models\Site\EditorOperationLog::query()->count())->toBe(1);
});

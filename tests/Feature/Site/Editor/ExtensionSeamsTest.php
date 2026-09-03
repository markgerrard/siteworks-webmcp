<?php

use App\Enums\AgentRole;
use App\Mcp\Servers\EditorMcpServer;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\EditorState;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\RevisionScopes;
use App\Services\Site\Editor\WarningBag;
use App\Services\Site\Editor\WarningCodes;
use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;

beforeEach(function () {
    config([
        'editor.agent_tools.enabled' => true,
        'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['staff', 'client'],
    ]);

});

function extensionOperation(string $name, string $address, ?array $allowedRoles = null): BaseOperation
{
    return new class($name, $address, $allowedRoles) extends BaseOperation
    {
        /**
         * @param  list<string>|null  $operationRoles
         */
        public function __construct(
            private readonly string $operationName,
            private readonly string $operationAddress,
            private readonly ?array $operationRoles,
        ) {}

        public function name(): string
        {
            return $this->operationName;
        }

        public function readOnly(): bool
        {
            return $this->operationRoles !== null;
        }

        public function allowedRoles(): ?array
        {
            return $this->operationRoles;
        }

        public function address(): string
        {
            return $this->operationAddress;
        }

        public function sideEffects(): string
        {
            return 'Extension seam test operation.';
        }

        public function inputSchema(): array
        {
            $revisionKey = match ($this->operationAddress) {
                'site' => 'composition_revision',
                'ledger' => 'ledger_revision',
                default => 'revision_base',
            };

            return [
                'type' => 'object',
                'properties' => [$revisionKey => ['type' => 'integer']],
            ];
        }

        public function handle(EditorContext $ctx, array $input): OperationResult
        {
            return OperationResult::ok([], app(EditorStateFactory::class)->for($ctx->site, null));
        }
    };
}

function extensionListedTools(): array
{
    $server = app()->make(EditorMcpServer::class, ['transport' => new FakeTransporter]);

    return app(ListTools::class)->handle(
        new JsonRpcRequest('tools', 'tools/list', ['per_page' => 50]),
        $server->createContext(),
    )->toArray()['result']['tools'];
}

it('fails discovery for a custom operation whose revision scope is unregistered', function () {
    $operation = extensionOperation('ledger_write', 'ledger');

    // Wrong implementation this catches: OperationRegistry accepts any address and run() later
    // treats an unknown scope as an unchecked non-site write.
    expect(fn () => new OperationRegistry([$operation]))
        ->toThrow(InvalidArgumentException::class, 'Unknown revision scope [ledger].');
});

it('runs a registered custom revision check through EditorOperations', function () {
    $observed = [];
    RevisionScopes::register(
        'ledger',
        'ledger_revision',
        function (EditorContext $context, int $expectedRevision, EditorState $state) use (&$observed): ?OperationResult {
            $observed = [$context->site->id, $expectedRevision];

            return OperationResult::fail('stale_revision', 'Shop revision moved.', $state);
        },
    );

    $actor = User::factory()->staff(AgentRole::Agent)->create();
    $site = Site::factory()->create(['created_by_user_id' => $actor->id]);
    $operation = extensionOperation('ledger_write', 'ledger');
    app()->instance(OperationRegistry::class, new OperationRegistry([$operation]));

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Ui),
        'ledger_write',
        ['ledger_revision' => 41],
    );

    // Wrong implementation this catches: EditorOperations only checks address === 'site'.
    expect($observed)->toBe([$site->id, 41])
        ->and($result->error['code'])->toBe('stale_revision')
        ->and($result->error['message'])->toBe('Shop revision moved.');
});

it('applies allowed roles in real MCP execution and discovery for staff and client users', function () {
    config(['editor.agent_tools.client_portal_enabled' => true]);
    $client = Client::factory()->create();
    $clientUser = User::factory()->create(['client_id' => $client->id, 'role' => null]);
    $clientSite = Site::factory()->create(['client_id' => $client->id]);
    $staffUser = User::factory()->staff(AgentRole::Agent)->create();
    $staffSite = Site::factory()->create(['created_by_user_id' => $staffUser->id]);
    $operation = extensionOperation('draft_product', 'page', ['client']);
    app()->instance(OperationRegistry::class, new OperationRegistry([$operation]));

    $this->actingAs($staffUser);
    $staffTools = collect(extensionListedTools())->pluck('name')->all();
    $staffResult = app(EditorOperations::class)->run(
        new EditorContext($staffUser, $staffSite, ActorChannel::Mcp),
        'draft_product',
        [],
    );

    $this->actingAs($clientUser);
    $clientTools = collect(extensionListedTools())->pluck('name')->all();
    $clientResult = app(EditorOperations::class)->run(
        new EditorContext($clientUser, $clientSite, ActorChannel::Mcp),
        'draft_product',
        [],
    );

    // Wrong implementation this catches: both callers use enabledFor(), so staff sees and runs
    // the client-only operation while the operation-aware method remains dead. Named from
    // SANDBOX so the client allowlist does not hide the allowedRoles() intersection.
    expect($staffTools)->not->toContain('siteworks.draft_product')
        ->and($staffResult->error['code'])->toBe('forbidden')
        ->and($clientTools)->toContain('siteworks.draft_product')
        ->and($clientResult->ok)->toBeTrue();
});

it('makes WarningBag reject a code absent from the sole WarningCodes registry', function () {
    // Wrong implementation this catches: WarningBag validates against its former CODES constant.
    expect(fn () => (new WarningBag)->add('commerce_stock_low', 'Stock is low.'))
        ->toThrow(InvalidArgumentException::class, 'Unknown warning code [commerce_stock_low].')
        ->and((new ReflectionClass(WarningBag::class))->hasConstant('CODES'))->toBeFalse();
});

it('exports the exact independently computed warning-code version in the schema artifact', function () {
    $expectedCodes = [
        'contrast_below_aa',
        'contrast_below_aaa',
        'meta_description_long',
        'meta_title_long',
        'alt_text_missing',
        'accent_ranges_dropped',
        'variant_not_in_recipe',
        'effective_truncated',
        'async_pending',
        'preview_stale',
        'video_mode_conflict',
        'scene_active',
        'asset_unreferenced',
        'spend_near_cap',
        'preview_unavailable',
    ];
    sort($expectedCodes);
    $expectedVersion = sha1(implode(',', $expectedCodes));
    $artifact = json_decode(
        file_get_contents(resource_path('js/site-editor/webmcp/schemas.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    // Wrong implementation this catches: exporting any non-empty registry value without tying it
    // exactly to the independently known code set.
    expect($artifact['warnings_codes_version'] ?? null)->toBe($expectedVersion)
        ->and(array_keys($artifact))->toBe(['warnings_codes_version', 'operations']);
});

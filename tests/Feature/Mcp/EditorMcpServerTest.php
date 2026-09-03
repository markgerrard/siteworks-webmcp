<?php

use App\Enums\AgentRole;
use App\Mcp\Servers\EditorMcpServer;
use App\Mcp\Tools\Editor\EditFieldTool;
use App\Mcp\Tools\Editor\GetBrandContextTool;
use App\Mcp\Tools\Editor\GetPageStructureTool;
use App\Mcp\Tools\Editor\RestoreMediaVersionTool;
use App\Mcp\Tools\Editor\UpdateFormTool;
use App\Mcp\Tools\Editor\UploadImageTool;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\EditorOperationLog;
use App\Models\Site\PageRevision;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\BaseOperation;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\EditorStateFactory;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\OperationResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Tool;

beforeEach(function () {
    config([
        'editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);

    $this->withoutVite();
    $this->actor = User::factory()->staff(AgentRole::Agent)->create();
    $this->site = Site::factory()->create(['created_by_user_id' => $this->actor->id]);
    $this->actingAs($this->actor);
});

function listedEditorTools(): array
{
    $server = app()->make(EditorMcpServer::class, ['transport' => new FakeTransporter]);
    $tools = [];
    $cursor = null;

    do {
        $params = ['per_page' => 50];
        if (is_string($cursor) && $cursor !== '') {
            $params['cursor'] = $cursor;
        }
        $response = app(ListTools::class)->handle(
            new JsonRpcRequest('tools', 'tools/list', $params),
            $server->createContext(),
        );
        $result = $response->toArray()['result'];
        $tools = [...$tools, ...($result['tools'] ?? [])];
        $cursor = $result['nextCursor'] ?? null;
    } while (is_string($cursor) && $cursor !== '');

    return $tools;
}

it('lists every registered operation only while MCP tools are enabled for the authenticated user', function () {
    $expected = collect(app(OperationRegistry::class)->all())
        ->keys()
        ->map(fn (string $operation): string => "siteworks.{$operation}")
        ->sort()
        ->values()
        ->all();

    $listed = collect(listedEditorTools())->pluck('name')->sort()->values()->all();

    expect($listed)->toBe($expected)
        ->and($listed)->not->toContain('siteworks.publish')
        ->and(class_exists('App\\Mcp\\Tools\\Editor\\PublishTool'))->toBeFalse(); // check-fqcn-ignore: asserts the class is absent

    config(['editor.agent_tools.enabled' => false]);

    expect(listedEditorTools())->toBe([]);
});

it('has a concrete MCP tool class for every registered operation', function () {
    $operations = array_keys(app(OperationRegistry::class)->all());
    $actual = collect($operations)->mapWithKeys(function (string $operation): array {
        $tool = 'App\\Mcp\\Tools\\Editor\\'.Str::studly($operation).'Tool';

        return [$operation => is_subclass_of($tool, Tool::class)];
    })->all();

    expect($actual)->toBe(array_fill_keys($operations, true));
});

it('fails MCP server boot when a registered operation has no tool class', function () {
    $operation = new class extends BaseOperation
    {
        public function name(): string
        {
            return 'missing_editor_mcp_tool';
        }

        public function readOnly(): bool
        {
            return true;
        }

        public function sideEffects(): string
        {
            return 'Never runs.';
        }

        public function inputSchema(): array
        {
            return ['type' => 'object'];
        }

        public function handle(EditorContext $ctx, array $input): OperationResult
        {
            return OperationResult::ok([], app(EditorStateFactory::class)->for($ctx->site, null));
        }
    };
    app()->instance(OperationRegistry::class, new OperationRegistry([$operation]));
    config(['editor.exposure.sets.internal' => ['missing_editor_mcp_tool']]);
    app()->forgetInstance(EditorMcpServer::class);
    app()->forgetInstance(\App\Services\Site\Editor\ToolExposure::class);

    expect(fn () => app()->make(EditorMcpServer::class, ['transport' => new FakeTransporter]))
        ->toThrow(
            LogicException::class,
            'Editor MCP tool [App\Mcp\Tools\Editor\MissingEditorMcpToolTool] is missing for operation [missing_editor_mcp_tool].', // check-fqcn-ignore: deliberately absent tool class named in the expected error text
        );
});

it('advances a draft through edit_field and excludes rendered html unless requested', function () {
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Before'],
        ['type' => 'cta', 'title' => 'Call us'],
    ]];
    $page = GeneratedPage::factory()->for($this->site)->create([
        'page_type' => 'home',
        'content_data' => $content,
    ]);
    $published = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $published->id]);

    EditorMcpServer::actingAs($this->actor)
        ->tool(EditFieldTool::class, [
            'site_id' => $this->site->id,
            'page_id' => $page->id,
            'stored_index' => 0,
            'field_path' => 'title',
            'value' => 'First edit',
            'revision_base' => $published->id,
        ])
        ->assertOk()
        ->assertDontSee('"html"');

    $draftRevisionId = $page->fresh()->draft_revision_id;

    expect($draftRevisionId)->toBeInt()->not->toBe($published->id);

    EditorMcpServer::actingAs($this->actor)
        ->tool(EditFieldTool::class, [
            'site_id' => $this->site->id,
            'page_id' => $page->id,
            'stored_index' => 0,
            'field_path' => 'title',
            'value' => 'Second edit',
            'revision_base' => $draftRevisionId,
            'include_html' => true,
        ])
        ->assertOk()
        ->assertSee('"html"')
        ->assertSee('Second edit');

    expect($page->fresh()->draft_revision_id)->toBeInt()->not->toBe($draftRevisionId);
});

it('drops undeclared arguments before calling EditorOperations layer zero', function () {
    $spy = new class(app(EditorStateFactory::class))
    {
        /** @var array<string, mixed> */
        public array $input = [];

        public function __construct(private readonly EditorStateFactory $states) {}

        /**
         * @param  array<string, mixed>  $input
         */
        public function run(EditorContext $context, string $operation, array $input): OperationResult
        {
            $this->input = $input;

            return OperationResult::ok([], $this->states->for($context->site, null));
        }

        /**
         * @param  array<string, mixed>  $input
         */
        public function refuseIfUnexposed(EditorContext $context, string $operation, array $input): ?OperationResult
        {
            return null;
        }
    };
    app()->instance(EditorOperations::class, $spy);

    app(EditFieldTool::class)->handle(new Request([
        'site_id' => $this->site->id,
        'include_html' => false,
        'page_id' => 10,
        'stored_index' => 0,
        'field_path' => 'title',
        'value' => 'Safe',
        'revision_base' => 20,
        'undeclared' => 'must not pass',
    ]));

    expect($spy->input)->toBe([
        'page_id' => 10,
        'stored_index' => 0,
        'field_path' => 'title',
        'value' => 'Safe',
        'revision_base' => 20,
    ]);
});

it('keeps execution gated in layer zero when the flag is disabled', function () {
    config(['editor.agent_tools.enabled' => false]);

    $response = app(GetPageStructureTool::class)->handle(new Request([
        'site_id' => $this->site->id,
        'page_id' => 999,
    ]));
    $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['ok'])->toBeFalse()
        ->and($payload['error']['code'])->toBe('forbidden');
});

it('bridges operation schemas and exposes required MCP guidance', function () {
    $edit = app(EditFieldTool::class)->toArray();
    $form = app(UpdateFormTool::class)->toArray();
    $read = app(GetPageStructureTool::class)->toArray();
    $brand = app(GetBrandContextTool::class)->toArray();
    $upload = app(UploadImageTool::class)->toArray();
    $restoreMedia = app(RestoreMediaVersionTool::class)->toArray();

    expect($edit['inputSchema']['required'])->toContain('site_id', 'value')
        ->and($edit['inputSchema']['required'])->not->toContain('include_html')
        ->and($edit['inputSchema']['properties'])->toHaveKeys(['site_id', 'include_html'])
        ->and($edit['inputSchema']['properties']['value']['anyOf'])->toBe([
            ['type' => 'string'],
            ['type' => 'object'],
        ])
        ->and($edit['inputSchema']['properties']['value']['description'])->toContain('TipTap document object')
        ->and($form['inputSchema']['properties']['fields']['items'])->toBe(['type' => 'object'])
        ->and($read['annotations']['readOnlyHint'])->toBeTrue()
        ->and($edit['description'])->toContain('Result `receipt.preview` of `unconfirmed`')
        ->and($edit['description'])->toContain('error.job_ref === null')
        ->and($edit['description'])->toContain("site's monthly cap")
        ->and($brand['description'])->toContain('__shared_service_hero')
        ->and($upload['description'])->toContain('un-wrapped strict base64 with no line breaks')
        ->and($restoreMedia['description'])->toContain('targets image fields only');
});

it('exports valid JSON for every operation schema and its address', function () {
    Artisan::call('editor:schemas', ['--json' => true]);
    $artifact = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $schemas = $artifact['operations'];

    expect($schemas)->toHaveCount(count(app(OperationRegistry::class)->all()))
        ->and($schemas)->toHaveKeys(array_keys(app(OperationRegistry::class)->all()))
        ->and($artifact['warnings_codes_version'])->toMatch('/^[0-9a-f]{40}$/');

    foreach ($schemas as $schema) {
        expect($schema)->toHaveKeys(['sideEffects', 'readOnly', 'address', 'requiresApproval', 'destructive', 'positionalApprovalGap', 'inputSchema'])
            ->and($schema['address'])->toBeIn(['page', 'site', 'shop']);
    }
});

it('answers an unresolvable site_id as not_found rather than a transport error', function () {
    config(['editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true]);
    $user = \App\Models\User::factory()->staff(\App\Enums\AgentRole::Agent)->create();
    $this->actingAs($user);

    foreach ([null, 0, 'abc', ['x'], 999_999_999] as $bad) {
        $response = app(\App\Mcp\Tools\Editor\GetPageStructureTool::class)->handle(new Request(['site_id' => $bad, 'page_id' => 1]));
        $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
        expect($payload)->toBe([
            'ok' => false,
            'error' => ['code' => 'not_found', 'message' => 'Site not found.'],
            'state' => [
                'site_id' => null,
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
    }
});

it('keeps the committed Front 2 schemas in sync with the registry export', function () {
    Artisan::call('editor:schemas', ['--json' => true]);

    expect(trim(Artisan::output()))
        ->toBe(trim(file_get_contents(resource_path('js/site-editor/webmcp/schemas.json'))));
});

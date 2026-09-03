<?php

use App\Enums\AgentRole;
use App\Mcp\Servers\EditorMcpServer;
use App\Mcp\Tools\Editor\EditorTool;
use App\Models\User;
use App\Services\Site\Editor\OperationRegistry;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Tests\Support\CommerceReads;

beforeEach(function () {
    config([
        'editor.agent_tools.enabled' => true,
        'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);
});

it('has a thin EditorTool subclass for every commerce operation and lists 50 operations', function () {
    $operations = array_keys(app(OperationRegistry::class)->all());

    expect($operations)->toHaveCount(50);

    foreach (CommerceReads::operations() as $operation) {
        expect($operations)->toContain($operation);
        $tool = 'App\\Mcp\\Tools\\Editor\\'.Str::studly($operation).'Tool';
        expect(is_subclass_of($tool, Tool::class))->toBeTrue($operation)
            ->and(is_subclass_of($tool, EditorTool::class))->toBeTrue($operation);
    }

    $this->actingAs(User::factory()->staff(AgentRole::Agent)->create());

    $server = app()->make(EditorMcpServer::class, ['transport' => new FakeTransporter]);
    $listed = app(ListTools::class)->handle(
        new JsonRpcRequest('tools', 'tools/list', ['per_page' => 50]),
        $server->createContext(),
    )->toArray()['result']['tools'];

    $names = array_column($listed, 'name');
    expect($names)->toHaveCount(50);
    foreach (CommerceReads::operations() as $operation) {
        expect($names)->toContain("siteworks.{$operation}");
    }

    $schemas = json_decode(
        (string) file_get_contents(resource_path('js/site-editor/webmcp/schemas.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    expect($schemas['operations'])->toHaveCount(50);
    foreach (CommerceReads::operations() as $operation) {
        expect($schemas['operations'][$operation]['address'] ?? null)->toBe('shop');
    }
});

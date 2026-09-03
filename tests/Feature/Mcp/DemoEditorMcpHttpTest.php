<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;

function demoMcpPortalFlags(): void
{
    config([
        'demo.enabled' => true,
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => true,
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => true,
    ]);
}

/**
 * @return array<string, mixed>
 */
function postEditorMcp(User $user, string $method, array $params = [], int $id = 1): array
{
    $response = test()->actingAs($user)
        ->withHeaders(['Accept' => 'application/json'])
        ->postJson(route('mcp.editor'), [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);

    $response->assertOk();

    return $response->json();
}

it('registers mcp.editor when agent tools are enabled', function () {
    $env = $_ENV;
    $env['SURFACE'] = 'all';
    $env['EDITOR_AGENT_TOOLS'] = 'true';

    $process = new Process(
        ['php', 'artisan', 'route:list', '--name=mcp.editor'],
        base_path(),
        $env,
    );
    $process->mustRun();

    expect($process->getOutput())->toContain('mcp.editor')
        ->and($process->getOutput())->toContain('mcp/editor');
});

it('does not register mcp.editor when agent tools are disabled', function () {
    $env = $_ENV;
    $env['SURFACE'] = 'all';
    $env['EDITOR_AGENT_TOOLS'] = 'false';

    $process = new Process(
        ['php', 'artisan', 'route:list', '--name=mcp.editor'],
        base_path(),
        $env,
    );
    $process->mustRun();

    expect($process->getOutput())->not->toContain('mcp.editor');
});

it('lets a logged-in demo user initialize and list editor MCP tools over the session', function () {
    demoMcpPortalFlags();
    [, $user] = demoSite64();

    expect($user->isClientUser())->toBeTrue()
        ->and(Route::has('mcp.editor'))->toBeTrue();

    $init = postEditorMcp($user, 'initialize', [
        'protocolVersion' => '2025-11-25',
        'capabilities' => new \stdClass,
        'clientInfo' => ['name' => 'demo-dogfood', 'version' => '1.0.0'],
    ]);

    expect($init['result']['serverInfo']['name'] ?? null)->toBe('Siteworks Editor Tools');

    $listed = postEditorMcp($user, 'tools/list', ['per_page' => 100], id: 2);
    $names = collect($listed['result']['tools'] ?? [])->pluck('name')->all();

    expect($names)->toContain('siteworks.get_brand_context')
        ->and($names)->toContain('siteworks.edit_field')
        ->and($names)->toContain('siteworks.get_page_structure')
        ->and($names)->toContain('siteworks.list_products')
        ->and($names)->not->toContain('siteworks.generate_image')
        ->and($names)->not->toContain('siteworks.generate_logo_concepts')
        ->and($names)->not->toContain('siteworks.regenerate_hero')
        ->and($names)->not->toContain('siteworks.manage_video')
        ->and($names)->not->toContain('siteworks.publish');
});

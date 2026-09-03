<?php

use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\AgentToolsGate;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationRegistry;
use Illuminate\Support\Facades\Storage;

function enableDemoPortalEditorTools(): void
{
    config([
        'demo.enabled' => true,
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => true,
        'editor.agent_tools.roles' => ['staff', 'client'],
        'editor.agent_tools.client_portal_enabled' => true,
    ]);
}

it('hides the four AI/video operations from the registry in demo mode', function () {
    enableDemoPortalEditorTools();

    $names = array_keys(app(OperationRegistry::class)->all());

    expect(config('demo.hidden_operations'))->toEqualCanonicalizing([
        'generate_image',
        'generate_logo_concepts',
        'regenerate_hero',
        'manage_video',
    ])
        ->and($names)->not->toContain('generate_image')
        ->and($names)->not->toContain('generate_logo_concepts')
        ->and($names)->not->toContain('regenerate_hero')
        ->and($names)->not->toContain('manage_video');
});

it('lets the demo client run get_brand_context and edit_field when the portal channel is open', function () {
    enableDemoPortalEditorTools();
    Storage::fake('s3');
    [$site, $user] = demoSite64();
    $page = demoSite64HomePage($site);
    $base = $page->draft_revision_id ?? $page->published_revision_id;

    expect($user->isClientUser())->toBeTrue()
        ->and($base)->toBeInt();

    $gate = app(AgentToolsGate::class);
    $registry = app(OperationRegistry::class);

    expect($gate->enabledForUserAndOperation($user, ActorChannel::Webmcp, $registry->get('get_brand_context')))->toBeTrue()
        ->and($gate->enabledForUserAndOperation($user, ActorChannel::Webmcp, $registry->get('edit_field')))->toBeTrue();

    $read = app(EditorOperations::class)->run(
        new EditorContext($user, $site, ActorChannel::Webmcp),
        'get_brand_context',
        [],
    );

    expect($read->ok)->toBeTrue()
        ->and($read->data['business_name'] ?? null)->toBe('Camino Bakehouse');

    $write = test()->actingAs($user)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->postJson(route('site.editor.operation', ['site' => $site->id, 'operation' => 'edit_field']), [
            'page_id' => $page->id,
            'stored_index' => 0,
            'field_path' => 'subtitle',
            'value' => 'T2 demo hero subtitle',
            'revision_base' => $base,
        ]);

    $write->assertOk()
        ->assertJsonPath('ok', true);

    $page->refresh();
    $draft = $page->draftRevision()->first() ?? $page->publishedRevision()->first();
    expect($draft)->not->toBeNull()
        ->and($draft->content_data['sections'][0]['subtitle'] ?? null)->toBe('T2 demo hero subtitle');
});

it('seeds agent_tools on the portal editor shell for the demo client when the portal flag is on', function () {
    enableDemoPortalEditorTools();
    [$site, $user] = demoSite64();
    $page = demoSite64HomePage($site);

    $url = 'http://'.config('domains.customer_domain').route('site.editor-shell', [
        'site' => $site->id,
        'page' => $page->id,
    ], false);

    $html = test()->actingAs($user)
        ->get($url)
        ->assertOk()
        ->getContent();

    expect($html)->toContain('agent_tools')
        ->and($html)->toContain('edit_field')
        ->and($html)->toContain('get_brand_context');
});

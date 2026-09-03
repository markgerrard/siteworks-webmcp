<?php

use App\Enums\AgentRole;
use App\Http\Controllers\Site\Editor\EditorOperationController;
use App\Mcp\Servers\EditorMcpServer;
use App\Models\Site;
use App\Models\Site\EditorOperationLog;
use App\Models\Site\PageRevision;
use App\Models\SiteMedia;
use App\Models\User;
use App\Services\Site\Editor\ActorChannel;
use App\Services\Site\Editor\EditorContext;
use App\Services\Site\Editor\EditorOperations;
use App\Services\Site\Editor\OperationRegistry;
use App\Services\Site\Editor\QueuedJobAuthorization;
use App\Services\Site\Editor\ToolExposure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Tests\Support\CommerceReads;
use Tests\Support\EditorSeeds;

/*
 * The v5 sandbox set, hard-coded from spec § 8 / ruling R1 — NOT read back from config or from the
 * code under test: the shipped 18-operation surface plus B1 (get_effective_hero_state), B4
 * (set_logo_media), C1 (update_brand_theme), C2 (set_title_emphasis), A1
 * (inspect_draft; D1 validate_draft runs behind it through delegation, not standalone) and A2
 * (undo_revision). T21 added set_hero_copy_style (low-risk site-chrome write, same class as
 * set_variant / set_title_emphasis; sandbox+internal only — not commerce). T28 r2 added
 * set_shop_index_blocks (same class: site-addressed storefront list write; sandbox+internal
 * only — not commerce). T33 added set_fulfilment (validated site JSON, same class; sandbox+internal only — not commerce). Everything else — the paid video and capture operations especially —
 * is internal-only. A wrong default, a typo, or a dropped name reds the assertions below.
 *
 * @return list<string>
 */
function sandboxSet(): array
{
    return [
        'add_section', 'edit_field', 'get_brand_context',
        'get_brand_system',
        'get_effective_hero_state', 'get_job_status', 'get_logo_assets', 'get_page_structure', 'get_site_context', 'inspect_draft',
        'list_image_versions', 'move_section', 'publish_summary', 'remove_section',
        'restore_image_version', 'restore_media_version', 'seed_product_reviews', 'select_logo',
        'set_fulfilment', 'set_hero_copy_style', 'set_logo_media', 'set_nav_container', 'set_shop_index_blocks', 'set_title_emphasis', 'set_variant', 'undo_revision', 'update_brand_theme',
        'update_form', 'upload_image',
        'list_products', 'get_product', 'draft_product', 'update_draft_product', 'set_product_image', 'draft_category_content',
        'list_media', 'assign_media',
    ];
}

/**
 * @return list<string>
 */
function sandboxWithout(string ...$operations): array
{
    return array_values(array_diff(sandboxSet(), $operations));
}

/**
 * Enables both editor flags plus the staff role allowlist — without the flags every call is
 * forbidden and an exposure test proves nothing.
 */
function enableEditorFlags(): void
{
    config([
        'editor.operations.enabled' => true,
        'editor.agent_tools.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);
}

/**
 * The live differential this round is decided on, for ONE adapter: the adapter's refusal of an
 * unexposed-but-KNOWN operation, for a fixed (actor, site, channel) caller, must be byte-identical
 * — status, decoded body, and editor_operation_log row delta — to the SAME caller's unknown-name
 * answer, computed live here at runtime. No hard-coded literal: the expected value comes from the
 * unknown-name call, and a live comparison cannot drift the way a literal would.
 *
 * The unknown-name reference is the operations layer's own answer (run() with an unregistered name),
 * which every adapter's refusal orches through; a caller's unknown-name answer is the one the whole
 * exposure set is designed to be indistinguishable from.
 *
 * @param  callable(): array{status: int|null, body: array<string, mixed>}  $adapterCall
 */
function assertExposureRefusalEqualsUnknownName(
    EditorOperations $operations,
    EditorContext $ctx,
    callable $adapterCall,
): void {
    $beforeReference = EditorOperationLog::query()->count();
    $reference = $operations->run($ctx, 'certainly_not_registered', []);
    $referenceRows = EditorOperationLog::query()->count() - $beforeReference;
    $referenceStatus = EditorOperationController::statusFor($reference);
    $referenceBody = $reference->toArray();

    $beforeKnown = EditorOperationLog::query()->count();
    $known = $adapterCall();
    $knownRows = EditorOperationLog::query()->count() - $beforeKnown;

    if ($known['status'] !== null) {
        expect($known['status'])->toBe($referenceStatus);
    }
    expect($known['body'])->toBe($referenceBody)
        ->and($knownRows)->toBe($referenceRows);
}

/**
 * @return array<string, mixed>
 */
function toolExposureShellConfig(string $html): array
{
    preg_match("/window\\.__siteworks_editor_shell_config__ = JSON\\.parse\\('(.*)'\\);/", $html, $matches);
    expect($matches)->toHaveKey(1);

    $json = json_decode('"'.$matches[1].'"', true, 512, JSON_THROW_ON_ERROR);

    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @return list<string>
 */
function listedFrontThreeTools(): array
{
    $server = app()->make(EditorMcpServer::class, ['transport' => new FakeTransporter]);
    $tools = app(ListTools::class)->handle(
        new JsonRpcRequest('tools', 'tools/list', ['per_page' => 50]),
        $server->createContext(),
    )->toArray()['result']['tools'];

    return array_column($tools, 'name');
}

it('gives an unlisted site the sandbox set — the narrowest, fail closed', function () {
    [$actor, $site] = EditorSeeds::site();
    // A site the configuration has never heard of — including one created after the config was
    // written. The classification that widens must be affirmative; the default is the narrow set.
    $brandNew = Site::factory()->create();

    expect(app(ToolExposure::class)->setFor($site))->toBe(sandboxWithout(...CommerceReads::operations()))
        ->and(app(ToolExposure::class)->setFor($brandNew))->toBe(sandboxWithout(...CommerceReads::operations()))
        ->and(app(ToolExposure::class)->nameFor($brandNew))->toBe('sandbox');

    // The paid video and capture operations are NOT reachable on a sandbox tenant, by name.
    foreach (['generate_hero_video', 'manage_video', 'render_preview', 'validate_draft', 'get_draft_diff'] as $internalOnly) {
        expect(sandboxSet())->not->toContain($internalOnly);
        expect(app(ToolExposure::class)->exposes($brandNew, $internalOnly))->toBeFalse();
    }
});

it('refuses manage_video on an ordinary sandbox site exactly as an unknown name', function () {
    enableEditorFlags();
    [$actor, $site] = EditorSeeds::site();

    $beforeRefused = EditorOperationLog::query()->count();
    $refused = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'manage_video',
        ['action' => 'pause', 'composition_revision' => 0],
    );
    $refusedRows = EditorOperationLog::query()->count() - $beforeRefused;

    $beforeUnknown = EditorOperationLog::query()->count();
    $unknown = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'certainly_not_registered',
        [],
    );
    $unknownRows = EditorOperationLog::query()->count() - $beforeUnknown;

    expect($refused->ok)->toBeFalse()
        ->and($refused->error)->toBe($unknown->error)
        ->and($refused->error)->toBe(['code' => 'not_found', 'message' => 'Unknown operation.'])
        ->and($refusedRows)->toBe($unknownRows)
        ->and($refusedRows)->toBe(1);

    expect(EditorOperationLog::query()->where('site_id', $site->id)->where('operation', 'manage_video')->count())->toBe(1)
        ->and(EditorOperationLog::query()->where('site_id', $site->id)->where('operation', 'manage_video')->where('result_code', 'not_found')->count())->toBe(1);
});

it('maps a site listed in internal_sites onto the internal set, and only that site', function () {
    [$actor, $site] = EditorSeeds::site();
    config(['editor.exposure.internal_sites' => ' '.$site->id.' ']);

    // Explicit internal list (no '*') minus shop-addressed names on a site with no shop.
    expect(app(ToolExposure::class)->setFor($site))->toBe(array_values(array_filter(
        (array) config('editor.exposure.sets.internal'),
        fn (string $name): bool => ! in_array($name, CommerceReads::operations(), true),
    )))
        ->and(app(ToolExposure::class)->nameFor($site))->toBe('internal');

    // An unlisted site stays narrow even with the list populated.
    $other = Site::factory()->create();
    expect(app(ToolExposure::class)->setFor($other))->toBe(sandboxWithout(...CommerceReads::operations()))
        ->and(app(ToolExposure::class)->nameFor($other))->toBe('sandbox');
});

it('refuses to boot on an unknown set name or an unparseable site list', function () {
    config(['editor.exposure.default' => 'does_not_exist']);
    expect(fn () => app(ToolExposure::class))->toThrow(InvalidArgumentException::class, 'editor.exposure.default');

    config(['editor.exposure.default' => 'sandbox', 'editor.exposure.internal_sites' => '51,oops']);
    expect(fn () => app(ToolExposure::class))->toThrow(InvalidArgumentException::class, 'editor.exposure.internal_sites');

    config(['editor.exposure.internal_sites' => '51,']);
    expect(fn () => app(ToolExposure::class))->toThrow(InvalidArgumentException::class, 'editor.exposure.internal_sites');

    // internal_sites maps listed sites to the 'internal' set — deleting that set from the map is fatal.
    config(['editor.exposure.internal_sites' => '51', 'editor.exposure.sets' => ['sandbox' => sandboxSet()]]);
    expect(fn () => app(ToolExposure::class))->toThrow(InvalidArgumentException::class, 'internal');
});

it('refuses an unexposed operation exactly as an unknown name, for a viewer and a stranger alike', function () {
    enableEditorFlags();
    config(['editor.exposure.sets.sandbox' => sandboxWithout('generate_image')]);
    Queue::fake();
    [$actor, $site, $page] = EditorSeeds::homeWithHero();

    $input = [
        'page_id' => $page->id,
        'stored_index' => 0,
        'field_path' => 'background_image',
        'composition_revision' => 0,
        'prompt_hint' => 'exposure-refusal-probe',
        'assign' => true,
        'revision_base' => $page->published_revision_id,
        'structure_epoch' => (int) $page->structure_epoch,
    ];

    // The site owner passes `view`: the refusal must be indistinguishable from an unknown name.
    $refused = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'generate_image',
        $input,
    );
    $unknown = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'certainly_not_registered',
        [],
    );

    expect($refused->ok)->toBeFalse()
        ->and($refused->error)->toBe($unknown->error)
        ->and($refused->error)->toBe(['code' => 'not_found', 'message' => 'Unknown operation.']);

    // No domain effect: nothing queued, no draft revision created.
    Queue::assertNothingPushed();
    expect($page->fresh()->draft_revision_id)->toBeNull();

    // Exactly one audit row for the refusal — the evidence the security pass reads.
    expect(EditorOperationLog::query()->where('site_id', $site->id)->where('operation', 'generate_image')->count())->toBe(1)
        ->and(EditorOperationLog::query()->where('site_id', $site->id)->where('operation', 'generate_image')->where('result_code', 'not_found')->count())->toBe(1)
        ->and(EditorOperationLog::query()->where('site_id', $site->id)->count())->toBe(2); // + the unknown-name probe row

    // A stranger fails `view`: both the unexposed name and the unknown name are `forbidden`.
    $stranger = User::factory()->staff(AgentRole::Agent)->create();
    $refusedStranger = app(EditorOperations::class)->run(new EditorContext($stranger, $site, ActorChannel::Webmcp), 'generate_image', $input);
    $unknownStranger = app(EditorOperations::class)->run(new EditorContext($stranger, $site, ActorChannel::Webmcp), 'certainly_not_registered', []);

    expect($refusedStranger->ok)->toBeFalse()
        ->and($refusedStranger->error)->toBe($unknownStranger->error)
        ->and($refusedStranger->error)->toBe(['code' => 'forbidden', 'message' => 'Not allowed on this site.']);
});

it('never exposure-gates the ui channel', function () {
    enableEditorFlags();
    config(['editor.exposure.sets.sandbox' => sandboxWithout('edit_field')]);
    [$actor, $site, $page] = EditorSeeds::homeWithHero();

    $result = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Ui),
        'edit_field',
        ['page_id' => $page->id, 'stored_index' => 0, 'field_path' => 'title', 'value' => 'Human edit', 'revision_base' => $page->published_revision_id],
    );

    // The domain effect is asserted on the reloaded row, not on the object the write returned.
    expect($result->ok)->toBeTrue()
        ->and($page->fresh()->draft_revision_id)->not->toBeNull();
});

it('does not re-gate delegated operations: the composed call runs while the direct call is refused', function () {
    enableEditorFlags();
    // restore_media_version composes edit_field through BaseOperation::delegate() — the exposure
    // control answers "may this agent CALL this tool", not "may this code path execute".
    config(['editor.exposure.sets.sandbox' => sandboxWithout('edit_field')]);
    [$actor, $site, $page] = EditorSeeds::homeWithHero();
    $media = SiteMedia::factory()->for($site)->create();

    $composed = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'restore_media_version',
        ['page_id' => $page->id, 'stored_index' => 0, 'field_path' => 'background_image', 'media_id' => $media->id, 'revision_base' => $page->published_revision_id],
    );

    expect($composed->ok)->toBeTrue();

    // The delegated edit_field really executed: the new draft revision carries the media's url.
    $draftId = $page->fresh()->draft_revision_id;
    expect($draftId)->not->toBeNull();
    $draft = PageRevision::query()->findOrFail($draftId);
    expect($draft->content_data['sections'][0]['background_image'])->toBe($media->url);

    // …while a DIRECT agent call to edit_field on the same site is refused as an unknown name.
    $direct = app(EditorOperations::class)->run(
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        'edit_field',
        ['page_id' => $page->id, 'stored_index' => 0, 'field_path' => 'title', 'value' => 'Agent edit', 'revision_base' => $draftId],
    );

    expect($direct->ok)->toBeFalse()
        ->and($direct->error)->toBe(['code' => 'not_found', 'message' => 'Unknown operation.']);
});

it('gates the agent multipart upload branch on the exposure set and leaves the human path alone', function () {
    enableEditorFlags();
    config(['editor.exposure.sets.sandbox' => sandboxWithout('upload_image')]);
    Storage::fake(config('filesystems.default'));
    Storage::fake('s3');
    [$actor, $site] = EditorSeeds::site();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    $this->actingAs($actor)
        ->withHeaders(['X-Editor-Channel' => 'webmcp'])
        ->post(route('site.admin.media-upload', $site), [
            'file' => UploadedFile::fake()->createWithContent('agent.png', $png),
        ])
        ->assertNotFound()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonPath('error.message', 'Unknown operation.');

    // No ingestion happened and the refusal is audited as the unknown-name row for this caller.
    expect(SiteMedia::query()->where('site_id', $site->id)->count())->toBe(0)
        ->and(EditorOperationLog::query()->where('site_id', $site->id)->where('operation', 'upload_image')->where('actor_channel', 'webmcp')->where('result_code', 'not_found')->count())->toBe(1);

    // The human file-picker branch (ui channel) is not exposure-gated.
    $this->withHeaders(['X-Editor-Channel' => 'ui'])
        ->post(route('site.admin.media-upload', $site), [
            'file' => UploadedFile::fake()->createWithContent('human.png', $png),
        ])->assertSuccessful();

    expect(SiteMedia::query()->where('site_id', $site->id)->count())->toBe(1)
        ->and(EditorOperationLog::query()->where('site_id', $site->id)->where('operation', 'upload_image')->where('actor_channel', 'ui')->where('result_code', 'ok')->count())->toBe(1);
});

it('never widens: an internal site still faces the agent gate and site policy', function () {
    enableEditorFlags();
    [$actor, $site, $page] = EditorSeeds::homeWithHero();
    config(['editor.exposure.internal_sites' => (string) $site->id]);
    $input = ['page_id' => $page->id, 'stored_index' => 0, 'field_path' => 'title', 'value' => 'Widening probe', 'revision_base' => $page->published_revision_id];

    // AgentToolsGate still applies: flag off refuses even on an internal site.
    config(['editor.agent_tools.enabled' => false]);
    $gated = app(EditorOperations::class)->run(new EditorContext($actor, $site, ActorChannel::Webmcp), 'edit_field', $input);
    expect($gated->error)->toBe(['code' => 'forbidden', 'message' => 'Agent tools are disabled for this actor.']);

    // SitePolicy still applies: a stranger is refused before anything else.
    config(['editor.agent_tools.enabled' => true]);
    $stranger = User::factory()->staff(AgentRole::Agent)->create();
    $policy = app(EditorOperations::class)->run(new EditorContext($stranger, $site, ActorChannel::Webmcp), 'edit_field', $input);
    expect($policy->error)->toBe(['code' => 'forbidden', 'message' => 'Not allowed on this site.']);

    // And the owner runs it: internal exposure does not narrow below the shipped surface.
    $owner = app(EditorOperations::class)->run(new EditorContext($actor, $site, ActorChannel::Webmcp), 'edit_field', $input);
    expect($owner->ok)->toBeTrue();
});

it('prints the effective set for a given site from editor:mcp-tools', function () {
    [$actor, $site] = EditorSeeds::site();

    $this->artisan('editor:mcp-tools', ['site' => $site->id])
        ->expectsOutputToContain('exposure set: sandbox')
        ->expectsOutputToContain('siteworks.edit_field')
        ->doesntExpectOutputToContain('siteworks.generate_hero_video')
        ->assertSuccessful();

    // A narrowed sandbox set is reflected: edit_field excluded, another shipped tool still listed.
    enableEditorFlags();
    config(['editor.exposure.sets.sandbox' => sandboxWithout('edit_field')]);
    $this->artisan('editor:mcp-tools', ['site' => $site->id])
        ->expectsOutputToContain('exposure set: sandbox')
        ->doesntExpectOutputToContain('siteworks.edit_field')
        ->expectsOutputToContain('siteworks.select_logo')
        ->assertSuccessful();

    config(['editor.exposure.internal_sites' => (string) $site->id]);
    $this->artisan('editor:mcp-tools', ['site' => $site->id])
        ->expectsOutputToContain('exposure set: internal')
        ->expectsOutputToContain('siteworks.edit_field')
        ->assertSuccessful();

    $this->artisan('editor:mcp-tools', ['site' => 999_999_999])->assertFailed();

    // The no-argument form is unchanged: every registered tool name, from the registry.
    $this->artisan('editor:mcp-tools')
        ->expectsOutputToContain('siteworks.edit_field')
        ->assertSuccessful();
});

it('registers the internal set on Front 3, where the site arrives per call', function () {
    enableEditorFlags();
    // Sandbox sites do not expose edit_field here — Front 3 must still list it, because
    // EditorMcpServer::tools() runs at construction with only Auth::user() and no site.
    config(['editor.exposure.sets.sandbox' => sandboxWithout('edit_field')]);
    [$actor, $site] = EditorSeeds::site();
    $this->actingAs($actor);

    expect(listedFrontThreeTools())->toContain('siteworks.edit_field');
});

it('seeds the per-site allowlist alongside the agent_tools capability in the shell config', function () {
    enableEditorFlags();
    $this->withoutVite();
    [$actor, $site, $page] = EditorSeeds::homeWithHero();

    $config = toolExposureShellConfig(
        $this->actingAs($actor)->get(route('site.editor-shell', [$site, $page]))->assertOk()->getContent(),
    );
    expect($config['exposureSet'])->toBe('sandbox')
        ->and($config['agentTools'])->toBe(sandboxWithout(...CommerceReads::operations()));

    // With agent tools off the keys are absent — the non-agent shell config is unchanged.
    config(['editor.agent_tools.enabled' => false]);
    $flagOffConfig = toolExposureShellConfig(
        $this->actingAs($actor)->get(route('site.editor-shell', [$site, $page]))->assertOk()->getContent(),
    );
    expect($flagOffConfig)->not->toHaveKeys(['exposureSet', 'agentTools'])
        ->and($flagOffConfig['capabilities'])->toBe(['edit', 'publish', 'media', 'editor_ui']);
});

// A second test, not a second render inside the one above: Route::getController() memoizes the
// controller instance across in-test requests, so a controller-injected ToolExposure snapshots
// config at its FIRST resolution. Production is one request per process; tests render once each.
it('seeds the internal allowlist for a site listed in internal_sites', function () {
    enableEditorFlags();
    $this->withoutVite();
    [$actor, $site, $page] = EditorSeeds::homeWithHero();
    config(['editor.exposure.internal_sites' => (string) $site->id]);

    $internalConfig = toolExposureShellConfig(
        $this->actingAs($actor)->get(route('site.editor-shell', [$site, $page]))->assertOk()->getContent(),
    );
    expect($internalConfig['exposureSet'])->toBe('internal')
        ->and($internalConfig['agentTools'])->toBe(array_values(array_filter(
            (array) config('editor.exposure.sets.internal'),
            fn (string $name): bool => ! in_array($name, CommerceReads::operations(), true),
        )));
});

it('re-checks exposure for queued jobs through denialForOperation', function () {
    enableEditorFlags();
    [$actor, $site] = EditorSeeds::site();
    $authorization = app(QueuedJobAuthorization::class);

    // generate_hero_video is internal-only — the hard-coded sandbox set does not name it, and a job
    // enqueued for it must stop if the site's set says no (revocation between enqueue and execution).
    expect(sandboxSet())->not->toContain('generate_hero_video');

    expect($authorization->denialForOperation($actor->id, $site, ActorChannel::Mcp, 'generate_hero_video'))->toBe('exposure')
        ->and($authorization->denialForOperation($actor->id, $site, ActorChannel::Mcp, 'edit_field'))->toBeNull()
        // The ui channel is never exposure-gated, and a null actor is a system dispatch.
        ->and($authorization->denialForOperation($actor->id, $site, ActorChannel::Ui, 'generate_hero_video'))->toBeNull()
        ->and($authorization->denialForOperation(null, $site, ActorChannel::Mcp, 'generate_hero_video'))->toBeNull();

    // Exposure composes with the existing checks rather than replacing them.
    config(['editor.agent_tools.enabled' => false]);
    expect($authorization->denialForOperation($actor->id, $site, ActorChannel::Mcp, 'edit_field'))->toBe('gate');

    // Narrowing the sandbox set denies an EXISTING operation…
    config(['editor.agent_tools.enabled' => true, 'editor.exposure.sets.sandbox' => sandboxWithout('edit_field')]);
    expect($authorization->denialForOperation($actor->id, $site, ActorChannel::Mcp, 'edit_field'))->toBe('exposure');

    // …and listing the site in internal_sites lifts exactly that narrowing.
    config(['editor.exposure.internal_sites' => (string) $site->id]);
    expect($authorization->denialForOperation($actor->id, $site, ActorChannel::Mcp, 'edit_field'))->toBeNull();
});

it('refuses an unexposed known operation before the EditorOperationController preflight answers (owner, no revision base)', function () {
    enableEditorFlags();
    config(['editor.exposure.sets.sandbox' => sandboxWithout('generate_image')]);
    Queue::fake();
    [$actor, $site, $page] = EditorSeeds::homeWithHero();

    // The evidence case: owner, unexposed-but-known write, NO revision base. Without the ordering
    // fix `__invoke` answers `revisionBaseRequired()` (409 stale_revision, 0 audit rows) before it
    // reaches run(); with the fix the exposure refusal is byte-identical to the unknown-name answer.
    assertExposureRefusalEqualsUnknownName(
        app(EditorOperations::class),
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        function () use ($actor, $site): array {
            $response = $this->actingAs($actor)
                ->withHeaders(['X-Editor-Channel' => 'webmcp'])
                ->postJson(route('site.editor.generate-image', $site), []);

            return ['status' => $response->getStatusCode(), 'body' => $response->json()];
        },
    );
});

it('refuses an unexposed known operation to a stranger exactly as an unknown name, before the preflight', function () {
    enableEditorFlags();
    config(['editor.exposure.sets.sandbox' => sandboxWithout('generate_image')]);
    Queue::fake();
    [$actor, $site, $page] = EditorSeeds::homeWithHero();
    $stranger = User::factory()->staff(AgentRole::Agent)->create();

    // A stranger fails `view`: the unknown-name answer is forbidden / "Not allowed on this site.",
    // and the unexposed-known call must be byte-identical — status, body and audit row. This is what
    // fixes the ordering AND the stranger literal on this adapter.
    assertExposureRefusalEqualsUnknownName(
        app(EditorOperations::class),
        new EditorContext($stranger, $site, ActorChannel::Webmcp),
        function () use ($stranger, $site): array {
            $response = $this->actingAs($stranger)
                ->withHeaders(['X-Editor-Channel' => 'webmcp'])
                ->postJson(route('site.editor.generate-image', $site), []);

            return ['status' => $response->getStatusCode(), 'body' => $response->json()];
        },
    );
});

it('refuses an unexposed known operation before the field-update preflight answers (stale base)', function () {
    enableEditorFlags();
    config(['editor.exposure.sets.sandbox' => sandboxWithout('edit_field')]);
    [$actor, $site, $page] = EditorSeeds::homeWithHero();

    // PageFieldUpdateController's optimistic-concurrency pre-check is a fast-path 409 (stale base)
    // before it reaches run(). For an unexposed edit_field the exposure refusal must answer first.
    assertExposureRefusalEqualsUnknownName(
        app(EditorOperations::class),
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        function () use ($actor, $site, $page): array {
            $response = $this->actingAs($actor)
                ->withHeaders([
                    'X-Editor-Channel' => 'webmcp',
                    'X-Page-Revision-Base' => '999999', // stale — the preflight would 409 before run()
                ])
                ->post(route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]), [
                    'section_index' => 0,
                    'field_path' => 'title',
                    'value' => 'Probe',
                ]);

            return ['status' => $response->getStatusCode(), 'body' => $response->json()];
        },
    );
});

it('refuses an unexposed known operation before the form-update preflight answers (no revision base)', function () {
    enableEditorFlags();
    config(['editor.exposure.sets.sandbox' => sandboxWithout('update_form')]);
    [$actor, $site, $page] = EditorSeeds::homeWithHero();

    // FormUpdateController answers `staleRevisionResponse()` (409 "revision base is stale") before it
    // reaches run(). For an unexposed update_form the exposure refusal must answer first.
    assertExposureRefusalEqualsUnknownName(
        app(EditorOperations::class),
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        function () use ($actor, $site, $page): array {
            $response = $this->actingAs($actor)
                ->withHeaders(['X-Editor-Channel' => 'webmcp'])
                ->post(route('site.admin.form-update', ['site' => $site->id, 'page' => $page->id, 'section' => 2]), []);

            return ['status' => $response->getStatusCode(), 'body' => $response->json()];
        },
    );
});

it('refuses an unexposed known operation before the media-upload JSON preflight answers (no revision base)', function () {
    enableEditorFlags();
    config(['editor.exposure.sets.sandbox' => sandboxWithout('upload_image')]);
    Storage::fake(config('filesystems.default'));
    Storage::fake('s3');
    [$actor, $site] = EditorSeeds::site();

    // The JSON branch answers `revisionBaseRequired()` (409 "revision base required") for a base64
    // body with no composition_revision, before it reaches run(). For an unexposed upload_image the
    // exposure refusal must answer first.
    assertExposureRefusalEqualsUnknownName(
        app(EditorOperations::class),
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        function () use ($actor, $site): array {
            $response = $this->actingAs($actor)
                ->withHeaders(['X-Editor-Channel' => 'webmcp'])
                ->postJson(route('site.admin.media-upload', $site), [
                    'data_base64' => 'AAA', // no composition_revision → the preflight would 409
                ]);

            return ['status' => $response->getStatusCode(), 'body' => $response->json()];
        },
    );
});

it('refuses an unexposed known operation as the unknown-name answer on the multipart upload branch (owner)', function () {
    enableEditorFlags();
    config(['editor.exposure.sets.sandbox' => sandboxWithout('upload_image')]);
    Storage::fake(config('filesystems.default'));
    Storage::fake('s3');
    [$actor, $site] = EditorSeeds::site();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    // The second mismatch: the multipart branch refused an unexposed OWNER with 403 forbidden /
    // "Agent tools are not available on this site." instead of the unknown-name 404 not_found /
    // "Unknown operation.". The differential test asserts the refusal is byte-identical to the
    // unknown-name answer for the same caller.
    assertExposureRefusalEqualsUnknownName(
        app(EditorOperations::class),
        new EditorContext($actor, $site, ActorChannel::Webmcp),
        function () use ($actor, $site, $png): array {
            $response = $this->actingAs($actor)
                ->withHeaders(['X-Editor-Channel' => 'webmcp'])
                ->post(route('site.admin.media-upload', $site), [
                    'file' => UploadedFile::fake()->createWithContent('agent.png', $png),
                ]);

            return ['status' => $response->getStatusCode(), 'body' => $response->json()];
        },
    );
});

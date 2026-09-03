<?php

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// TODO(sso-future): remove when site management routes move to agent domain.
beforeEach(function () {
    $this->withoutMiddleware(\App\Http\Middleware\EnsureClientUser::class);
    $this->withoutVite();
});

function setupEditableSite(): array
{
    $user = \App\Models\User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Old', 'subtitle' => 'sub'],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    test()->actingAs($user);

    return [$site, $page, $rev, $user];
}

test('valid plain field update creates draft revision and returns rendered HTML', function () {
    [$site, $page] = setupEditableSite();

    $response = $this->postJson(
        route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]),
        [
            'section_index' => 0,
            'field_path' => 'title',
            'value' => 'New title',
        ],
    );

    $response->assertOk();
    expect($response->json('html'))->toContain('New title');

    $page->refresh();
    expect($page->draft_revision_id)->not->toBeNull();
    $draft = PageRevision::find($page->draft_revision_id);
    expect($draft->content_data['sections'][0]['title'])->toBe('New title');
});

test('inline field update rerender keeps form selection markers for the review', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'contact']);
    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [[
            'type' => 'contact_form',
            'title' => 'Old title',
        ]]],
    ]);
    $page->update(['published_revision_id' => $revision->id]);

    $response = $this->actingAs($user)->postJson(
        route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]),
        [
            'section_index' => 0,
            'field_path' => 'title',
            'value' => 'New title',
        ],
    );

    $response->assertOk();
    expect($response->json('html'))
        ->toContain('data-form-editable="page.'.$page->id.'.section.0"');
});

test('invalid field path returns 422', function () {
    [$site, $page] = setupEditableSite();

    $this->postJson(route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]), [
        'section_index' => 0,
        'field_path' => 'nonexistent',
        'value' => 'X',
    ])->assertStatus(422);
});

test('value over max length returns 422', function () {
    [$site, $page] = setupEditableSite();

    $this->postJson(route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]), [
        'section_index' => 0,
        'field_path' => 'title',
        'value' => str_repeat('x', 200),
    ])->assertStatus(422);
});

test('unauthorised user returns 403', function () {
    $owner = User::factory()->staff()->create();
    $other = User::factory()->create(['client_id' => null, 'role' => null]);
    $site = Site::factory()->create(['client_id' => null]);
    $page = GeneratedPage::factory()->for($site)->create();

    $this->actingAs($other)
        ->postJson(route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]), [
            'section_index' => 0, 'field_path' => 'title', 'value' => 'X',
        ])
        ->assertForbidden(); // role=null users reach the policy layer, which 403s (agent.only only redirects when the route is on the agent subdomain)
});

test('rich field update accepts TipTap doc', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create();
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [['type' => 'about-text', 'title' => 'X', 'body' => '']]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    $this->actingAs($user);

    $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'New body']]]]];

    $response = $this->postJson(route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]), [
        'section_index' => 0,
        'field_path' => 'body',
        'value' => $doc,
    ]);

    $response->assertOk();
    $page->refresh();
    $draft = PageRevision::find($page->draft_revision_id);
    expect($draft->content_data['sections'][0]['body'])->toEqual($doc);
});

test('cost table money cells and disclaimer dates cannot be edited via field update', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'extension-costs',
        'kind' => \App\Enums\PageKind::CostGuide,
        'origin' => \App\Enums\PageOrigin::Managed,
    ]);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            [
                'type' => 'cost_table',
                'title' => 'Typical costs',
                'rows' => [[
                    'job' => 'Extension',
                    'low' => 2450.50,
                    'high' => 8000.25,
                    'basis' => 'Per job',
                    'vat_note' => 'Ex VAT',
                ]],
            ],
            [
                'type' => 'cost_disclaimer',
                'body' => 'These are typical ranges, not a quote.',
                'valid_until' => '2027-03-31',
            ],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    $this->actingAs($user);

    $this->postJson(route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]), [
        'section_index' => 0,
        'field_path' => 'rows.0.low',
        'value' => '9999',
    ])->assertStatus(422);

    $this->postJson(route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]), [
        'section_index' => 1,
        'field_path' => 'valid_until',
        'value' => '2030-01-01',
    ])->assertStatus(422);

    $this->postJson(route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]), [
        'section_index' => 0,
        'field_path' => 'title',
        'value' => 'Updated costs title',
    ])->assertOk();

    $page->refresh();
    $draft = PageRevision::find($page->draft_revision_id);
    expect($draft->content_data['sections'][0]['title'])->toBe('Updated costs title')
        ->and((float) $draft->content_data['sections'][0]['rows'][0]['low'])->toBe(2450.50);
});

test('guide page hero_compact title update succeeds instead of 500', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'planning-permission-rear-extension',
        'kind' => \App\Enums\PageKind::Guide,
        'origin' => \App\Enums\PageOrigin::Managed,
    ]);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            [
                'type' => 'hero_compact',
                'title' => 'Old guide title',
                'subtitle' => 'sub',
                'eyebrow' => 'Planning guide',
                'accent_word' => 'guide',
            ],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    $this->actingAs($user);

    $response = $this->postJson(
        route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]),
        [
            'section_index' => 0,
            'field_path' => 'title',
            'value' => 'New guide title',
        ],
    );

    $response->assertOk();
    $page->refresh();
    expect($page->draft_revision_id)->not->toBeNull();
    $draft = PageRevision::find($page->draft_revision_id);
    expect($draft->content_data['sections'][0]['title'])->toBe('New guide title');
});

test('optimistic concurrency — stale base_revision_id returns 409', function () {
    [$site, $page, $publishedRev] = setupEditableSite();

    // First edit — creates draft revision with id = X
    $this->postJson(route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]), [
        'section_index' => 0, 'field_path' => 'title', 'value' => 'First',
    ])->assertOk();

    // Second edit with stale base — 409 with JSON body including current revision id
    $response = $this->postJson(
        route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]),
        ['section_index' => 0, 'field_path' => 'title', 'value' => 'Second'],
        ['X-Page-Revision-Base' => (string) $publishedRev->id]   // base stale (not the new draft)
    );
    $response->assertStatus(409);
    $response->assertJsonStructure(['message', 'current_revision_id']);
    expect($response->json('current_revision_id'))->toBeGreaterThan($publishedRev->id);
});

test('an update posted for page B writes page B, not the page the shell opened on', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);

    $pageA = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $revA = PageRevision::factory()->for($pageA, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Page A title', 'subtitle' => 'sub']]],
    ]);
    $pageA->update(['published_revision_id' => $revA->id]);

    $pageB = GeneratedPage::factory()->for($site)->create(['page_type' => 'about']);
    $revB = PageRevision::factory()->for($pageB, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Page B title', 'subtitle' => 'sub']]],
    ]);
    $pageB->update(['published_revision_id' => $revB->id]);

    $this->actingAs($user);
    $this->withoutVite();

    $shell = $this->get(route('site.editor-shell', [
        'site' => $site->id,
        'page' => $pageA->id,
    ]));
    $shell->assertOk();
    expect($shell->getContent())->toContain('\\\\\/pages\\\\\/0\\\\\/fields');

    $this->postJson(
        route('site.admin.field-update', ['site' => $site->id, 'page' => $pageB->id]),
        [
            'section_index' => 0,
            'field_path' => 'title',
            'value' => 'Edited on B',
        ],
    )->assertOk();

    $pageA->refresh();
    $pageB->refresh();
    $draftA = $pageA->draft_revision_id ? PageRevision::find($pageA->draft_revision_id) : PageRevision::find($pageA->published_revision_id);
    $draftB = PageRevision::find($pageB->draft_revision_id);

    expect($draftB->content_data['sections'][0]['title'])->toBe('Edited on B')
        ->and($draftA->content_data['sections'][0]['title'])->toBe('Page A title');
});

test('an inline field update returns signed editor-preview nav hrefs, not agents-host paths', function () {
    $user = User::factory()->staff()->create();
    $site = Site::factory()->create(['created_by_user_id' => $user->id]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'contact']);
    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [[
            'type' => 'contact_form',
            'title' => 'Old title',
        ]]],
    ]);
    $page->update(['published_revision_id' => $revision->id]);

    $aboutContent = ['sections' => [['type' => 'hero', 'title' => 'About us']]];
    $about = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'about',
        'content_data' => $aboutContent,
    ]);
    $aboutRevision = PageRevision::factory()->for($about, 'page')->create(['content_data' => $aboutContent]);
    $about->update(['published_revision_id' => $aboutRevision->id]);

    \App\Models\Site\SiteDraft::create([
        'site_id' => $site->id,
        'composition' => [
            'homepage_page_id' => $page->id,
            'nav' => ['items' => [
                ['type' => 'page', 'page_id' => $page->id, 'label' => 'Contact'],
                ['type' => 'page', 'page_id' => $about->id, 'label' => 'About'],
            ]],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
        ],
        'updated_at' => now(),
    ]);

    $html = $this->actingAs($user)->postJson(
        route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]),
        [
            'section_index' => 0,
            'field_path' => 'title',
            'value' => 'New title',
        ],
    )->assertOk()->json('html');

    $editorPreviewHost = config('domains.editor_preview_domain');
    $parentOrigin = 'https://'.config('domains.agent_domain');

    expect($html)
        ->toContain($editorPreviewHost)
        ->toContain('/pages/'.$about->id)
        ->toContain('signature=')
        ->toContain('parent_origin='.rawurlencode($parentOrigin))
        ->not->toContain("/sites/{$site->id}/pages/{$about->id}/preview");
});

/*
 * Front 2 (WebMCP) reaches `edit_field` through THIS legacy route — tools.js maps it to
 * `fieldUpdateUrl`. The controller used to hardcode ActorChannel::Ui, which laundered agent writes
 * into human ones: AgentToolsGate short-circuits to true for Ui but enforces
 * editor.agent_tools.roles for Webmcp, so the role allowlist was unreachable on the primary agent
 * write path and the audit row said `ui`. Only the browser suite caught it, and the browser suite
 * is expensive and rarely run — these pin it at the HTTP layer.
 */
test('a declared webmcp write is audited as webmcp, not laundered into a ui edit', function () {
    config([
        'editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);
    [$site, $page, $rev] = setupEditableSite();

    $response = $this->withHeaders([
        'X-Editor-Channel' => 'webmcp',
        'X-Page-Revision-Base' => (string) $rev->id,
    ])->postJson(
        route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]),
        ['section_index' => 0, 'field_path' => 'title', 'value' => 'Agent title'],
    );

    $response->assertOk();

    $this->assertDatabaseHas('editor_operation_log', [
        'site_id' => $site->id,
        'operation' => 'edit_field',
        'actor_channel' => 'webmcp',
        'result_code' => 'ok',
    ]);
});

test('a declared webmcp write returns the operations envelope so an agent can read ok/state', function () {
    config([
        'editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);
    [$site, $page, $rev] = setupEditableSite();

    $response = $this->withHeaders([
        'X-Editor-Channel' => 'webmcp',
        'X-Page-Revision-Base' => (string) $rev->id,
    ])->postJson(
        route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]),
        ['section_index' => 0, 'field_path' => 'title', 'value' => 'Agent title'],
    );

    $response->assertOk();
    expect($response->json('ok'))->toBeTrue()
        ->and($response->json('state.draft_revision_id'))->toBeInt()
        // legacy keys stay put — the human UI and two pinned tests read them
        ->and($response->json('page_id'))->toBe($page->id)
        ->and($response->json('html'))->toContain('Agent title');
});

test('a human write keeps the legacy body byte-for-byte (no envelope keys leak in)', function () {
    config([
        'editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);
    [$site, $page, $rev] = setupEditableSite();

    $response = $this->withHeaders(['X-Page-Revision-Base' => (string) $rev->id])->postJson(
        route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]),
        ['section_index' => 0, 'field_path' => 'title', 'value' => 'Human title'],
    );

    $response->assertOk();
    expect(array_keys($response->json()))->toEqualCanonicalizing(['html', 'page_id', 'draft_revision_id']);

    $this->assertDatabaseHas('editor_operation_log', [
        'site_id' => $site->id,
        'operation' => 'edit_field',
        'actor_channel' => 'ui',
    ]);
});

test('a declared webmcp write is refused 403 when the actor role is outside agent_tools.roles', function () {
    config([
        'editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['client'],
    ]);
    [$site, $page, $rev] = setupEditableSite();

    $response = $this->withHeaders([
        'X-Editor-Channel' => 'webmcp',
        'X-Page-Revision-Base' => (string) $rev->id,
    ])->postJson(
        route('site.admin.field-update', ['site' => $site->id, 'page' => $page->id]),
        ['section_index' => 0, 'field_path' => 'title', 'value' => 'Agent title'],
    );

    $response->assertForbidden();
    expect($response->json('ok'))->toBeFalse()
        ->and($response->json('error.code'))->toBe('forbidden');

    // the write must not have landed
    $page->refresh();
    expect($page->draft_revision_id)->toBeNull();
});

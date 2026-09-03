<?php

use App\Enums\AgentRole;
use App\Support\FormFieldDefinition;
use App\Enums\PageStatus;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedFormUpdateSite(): array
{
    $site = Site::factory()->create();
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Hi'],
        ['type' => 'contact_form', 'title' => 'Contact us', 'submit_label' => 'Send',
            'fields' => [['name' => 'service', 'label' => 'Service', 'type' => 'select', 'options' => ['Boiler']]]],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id, 'page_type' => 'contact',
        'content_data' => $content,
        'sort_order' => 1, 'version' => 1, 'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $revision->id]);

    return [$site, $page];
}

function seedLeadFormUpdateSite(array $extraFields = []): array
{
    $site = Site::factory()->create();
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['home_lead_form_enabled' => true],
    ]);
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Hi'],
        ['type' => 'lead_form', 'title' => 'Get a quote', 'submit_label' => 'Send', 'extra_fields' => $extraFields],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id, 'page_type' => 'home',
        'content_data' => $content,
        'sort_order' => 0, 'version' => 1, 'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $revision->id]);

    return [$site, $page];
}

function leadFormOperatorFields(int $count): array
{
    return collect(range(1, $count))
        ->map(fn (int $number): array => [
            'name' => "field_{$number}",
            'label' => "Field {$number}",
            'type' => 'text',
        ])
        ->all();
}

function validFormRevisionHeader(GeneratedPage $page): array
{
    return ['X-Page-Revision-Base' => (string) ($page->draft_revision_id ?? $page->published_revision_id)];
}

function attachEditorNavDraft(Site $site, GeneratedPage $current, GeneratedPage $other): void
{
    SiteDraft::create([
        'site_id' => $site->id,
        'composition' => [
            'homepage_page_id' => $current->id,
            'nav' => ['items' => [
                ['type' => 'page', 'page_id' => $current->id, 'label' => 'Contact'],
                ['type' => 'page', 'page_id' => $other->id, 'label' => 'About'],
            ]],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
        ],
        'updated_at' => now(),
    ]);
}

function seedSiblingAboutPage(Site $site): GeneratedPage
{
    $content = ['sections' => [['type' => 'hero', 'title' => 'About us']]];
    $page = GeneratedPage::create([
        'site_id' => $site->id, 'page_type' => 'about',
        'content_data' => $content,
        'sort_order' => 2, 'version' => 1, 'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $revision->id]);

    return $page;
}

test('a form definition can be replaced', function () {
    [$site, $page] = seedFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page))
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", [
            'title' => 'Get a quote',
            'submit_label' => 'Send it',
            'fields' => [
                ['label' => 'Job postcode', 'type' => 'text', 'required' => true],
            ],
        ])->assertOk();

    $draft = PageRevision::find($page->fresh()->draft_revision_id);
    $section = collect($draft->content_data['sections'])->firstWhere('type', 'contact_form');

    // Key derived from the label — the client never types one.
    expect($section['fields'][0]['name'])->toBe('job_postcode')
        ->and($section['title'])->toBe('Get a quote');
});

test('a form update returns server-rendered preview html with form markers', function () {
    [$site, $page] = seedFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $response = $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page))
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", [
            'fields' => [
                ['label' => 'Job postcode', 'type' => 'text', 'required' => true],
            ],
        ]);

    $response->assertOk();

    expect($response->json('html'))
        ->toContain('Job postcode')
        ->toContain('data-form-editable="page.'.$page->id.'.section.1"');
});

test('blank form copy is stored as unset rather than an empty string', function () {
    [$site, $page] = seedFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page))
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", [
            'title' => '',
            'submit_label' => '',
            'fields' => [],
        ])->assertOk();

    $draft = PageRevision::find($page->fresh()->draft_revision_id);
    $section = collect($draft->content_data['sections'])->firstWhere('type', 'contact_form');

    expect($section['title'])->toBeNull()
        ->and($section['submit_label'])->toBeNull();
});

test('deleting every contact form field sticks when the review is reopened', function () {
    [$site, $page] = seedFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page))
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", ['fields' => []])
        ->assertOk();

    $draft = PageRevision::find($page->fresh()->draft_revision_id);
    $section = collect($draft->content_data['sections'])->firstWhere('type', 'contact_form');

    expect($section['fields'])->toBe([])
        ->and($section['fields_migrated'])->toBeTrue();

    $this->actingAs($staff)
        ->getJson("/sites/{$site->id}/pages/{$page->id}/form/1")
        ->assertOk()
        ->assertJsonCount(0, 'fields');
});

test('name and email cannot be removed by omitting them', function () {
    // They are injected by the template, so "removal" means a client sending
    // a list that tries to claim those keys for something else.
    [$site, $page] = seedFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page))
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", [
            'fields' => [['label' => 'Email', 'type' => 'email']],
        ])->assertStatus(422);
});

test('an unknown field type is refused', function () {
    [$site, $page] = seedFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page))
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", [
            'fields' => [['label' => 'Upload', 'type' => 'file']],
        ])->assertStatus(422);
});

test('more fields than the cap is refused', function () {
    [$site, $page] = seedFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $fields = [];
    for ($i = 0; $i < FormFieldDefinition::MAX_FIELDS + 1; $i++) {
        $fields[] = ['label' => "Field {$i}", 'type' => 'text'];
    }

    $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page))
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", ['fields' => $fields])
        ->assertStatus(422);
});

test('a lead form can save five operator fields plus Message', function () {
    [$site, $page] = seedLeadFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);
    $fields = [
        ...leadFormOperatorFields(5),
        [
            'name' => 'message',
            'label' => 'How can we help?',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Tell us about the job',
        ],
    ];

    $response = $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page))
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", ['fields' => $fields])
        ->assertOk();

    $draft = PageRevision::find($page->fresh()->draft_revision_id);
    $section = collect($draft->content_data['sections'])->firstWhere('type', 'lead_form');

    expect($section['extra_fields'])->toHaveCount(6)
        ->and($section['extra_fields'][5]['name'])->toBe('message')
        ->and($section['message_field_migrated'])->toBeTrue()
        ->and(substr_count($response->json('html'), 'name="message"'))->toBe(1)
        ->and($response->json('html'))->toContain('placeholder="Tell us about the job"');
});

test('a lead form refuses one operator field beyond the cap even when Message is present', function () {
    // Message is an exemption from the cap, not a raised cap. Expressed
    // relative to MAX_FIELDS so raising the cap cannot silently void it.
    [$site, $page] = seedLeadFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);
    $fields = [
        ...leadFormOperatorFields(FormFieldDefinition::MAX_FIELDS + 1),
        ['name' => 'message', 'label' => 'Message', 'type' => 'textarea'],
    ];

    $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page))
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", ['fields' => $fields])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fields');
});

test('saving a lead form without Message makes its deletion stick', function () {
    [$site, $page] = seedLeadFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $response = $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page))
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", ['fields' => []])
        ->assertOk();

    expect($response->json('html'))->not->toContain('name="message"');

    $draft = PageRevision::find($page->fresh()->draft_revision_id);
    $section = collect($draft->content_data['sections'])->firstWhere('type', 'lead_form');
    expect($section['message_field_migrated'])->toBeTrue();

    $this->actingAs($staff)
        ->getJson("/sites/{$site->id}/pages/{$page->id}/form/1")
        ->assertOk()
        ->assertJsonCount(0, 'fields');
});

test('a stale revision base is refused with 409', function () {
    [$site, $page] = seedFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $this->actingAs($staff)
        ->withHeaders(['X-Page-Revision-Base' => '999999'])
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", ['fields' => []])
        ->assertStatus(409);
});

test('an omitted revision base is refused with the current revision', function () {
    [$site, $page] = seedFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $this->actingAs($staff)
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", ['fields' => []])
        ->assertStatus(409)
        ->assertJson([
            'message' => 'Page revision base is stale.',
            'current_revision_id' => $page->published_revision_id,
        ]);
});

test('a form update based on the newest draft revision succeeds', function () {
    [$site, $page] = seedFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $newestRevisionId = $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page))
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", [
            'fields' => [['label' => 'Phone', 'type' => 'tel']],
        ])
        ->assertOk()
        ->json('revision_id');

    $this->actingAs($staff)
        ->withHeaders(['X-Page-Revision-Base' => (string) $newestRevisionId])
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", [
            'fields' => [['label' => 'Job postcode', 'type' => 'text']],
        ])
        ->assertOk();

    $draft = PageRevision::find($page->fresh()->draft_revision_id);
    $section = collect($draft->content_data['sections'])->firstWhere('type', 'contact_form');

    expect($section['fields'][0]['name'])->toBe('job_postcode');
});

test('a client user can update their own site form', function () {
    [$site, $page] = seedFormUpdateSite();
    $client = Client::factory()->create();
    $site->update(['client_id' => $client->id]);
    $user = User::factory()->create(['client_id' => $client->id, 'role' => null, 'last_login_at' => now()]);

    $this->actingAs($user)
        ->withHeaders(validFormRevisionHeader($page))
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", ['fields' => []])
        ->assertOk();
});

test('a stranger cannot update it', function () {
    [$site, $page] = seedFormUpdateSite();

    $this->actingAs(User::factory()->create())
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", ['fields' => []])
        ->assertForbidden();
});

test('a generated dropdown with more options than the old cap can still be saved', function () {
    // Regression: AI generation produced a 13-option service dropdown, which the
    // original MAX_OPTIONS of 10 refused on every save. The operator was locked
    // out of their own form by data the platform itself created, and the error
    // named "fields.0.options" — meaningless to them.
    [$site, $page] = seedFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $options = collect(range(1, 13))->map(fn ($n) => "Service {$n}")->all();

    $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page))
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", [
            'title' => 'Contact us',
            'submit_label' => 'Send',
            'fields' => [
                ['label' => 'Service type', 'type' => 'select', 'options' => $options],
            ],
        ])->assertOk();

    $draft = PageRevision::find($page->fresh()->draft_revision_id);
    $section = collect($draft->content_data['sections'])->firstWhere('type', 'contact_form');

    // Stored in full — not refused, and not silently truncated either.
    expect($section['fields'][0]['options'])->toHaveCount(13);
});

test('a lead form accepts the full ten operator fields and renders every one', function () {
    // The cap lives in one place now. It previously appeared as a literal in
    // the endpoint, the renderer clamp and the admin editor, and they drifted:
    // fields could save and then never render, or render and then be refused
    // by the other editor. This pins save AND render together.
    $site = Site::factory()->create();
    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Hi'],
        ['type' => 'lead_form', 'title' => 'Quote', 'submit_label' => 'Send', 'extra_fields' => []],
    ]];
    $page = GeneratedPage::create([
        'site_id' => $site->id, 'page_type' => 'home',
        'content_data' => $content,
        'sort_order' => 1, 'version' => 1, 'status' => PageStatus::Published,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create(['content_data' => $content]);
    $page->update(['published_revision_id' => $revision->id]);

    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $fields = collect(range(1, 10))
        ->map(fn ($n) => ['label' => "Question {$n}", 'type' => 'text', 'required' => false])
        ->all();

    $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page))
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", [
            'title' => 'Quote',
            'submit_label' => 'Send',
            'fields' => $fields,
        ])->assertOk();

    $draft = PageRevision::find($page->fresh()->draft_revision_id);
    $section = collect($draft->content_data['sections'])->firstWhere('type', 'lead_form');
    expect($section['extra_fields'])->toHaveCount(10);

    // Rendered, not merely stored — the renderer had its own clamp that used to
    // silently drop the tail. Rendered through the section view directly, the
    // way the other lead-form tests do: a full-page render would be gated by
    // the site's lead_form_policy, which is not what this test is about.
    $html = view('site.sections.lead_form', [
        'section' => $section,
        'sectionIndex' => 1,
        'pageId' => $page->id,
        'emitMarkers' => false,
    ])->render();

    foreach (range(1, 10) as $n) {
        expect($html)->toContain("Question {$n}");
    }
});

test('a form still refuses one field beyond the cap', function () {
    [$site, $page] = seedFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $fields = collect(range(1, 11))
        ->map(fn ($n) => ['label' => "Question {$n}", 'type' => 'text'])
        ->all();

    $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page))
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", [
            'title' => 'Contact us', 'submit_label' => 'Send', 'fields' => $fields,
        ])->assertStatus(422);
});

test('a form update returns signed editor-preview nav hrefs, not agents-host paths', function () {
    [$site, $page] = seedFormUpdateSite();
    $about = seedSiblingAboutPage($site);
    attachEditorNavDraft($site, $page, $about);

    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $html = $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page))
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", [
            'fields' => [
                ['label' => 'Job postcode', 'type' => 'text', 'required' => true],
            ],
        ])
        ->assertOk()
        ->json('html');

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
 * Channel split on the form route (oracle finding F1). Front 2 maps `update_form` to THIS legacy route.
 * Humans must keep the legacy write — draftOnly:false, so the public cache is invalidated and
 * Preview.snapshot mirrored — because UpdateFormOperation hardcodes that OFF and routing humans through it
 * would take their live preview away (the T18 ruling). Agents must get the opposite: the gate, an audit
 * row, and draft-only. Hence two paths, split on the declared channel.
 */
test('an agent form write is gated, audited as webmcp on behalf of the human, and stays draft-only', function () {
    config([
        'editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);
    [$site, $page] = seedFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $response = $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page) + ['X-Editor-Channel' => 'webmcp'])
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", [
            'fields' => [['label' => 'Job postcode', 'type' => 'text', 'required' => true]],
        ]);

    $response->assertOk();

    // the envelope an agent needs, alongside the legacy keys the coordinator still reads
    expect($response->json('ok'))->toBeTrue()
        ->and($response->json('status'))->toBe('ok')
        ->and($response->json('revision_id'))->toBeInt()
        ->and($response->json('html'))->toBeString();

    // actor_user_id is the human whose session it is; actor_channel says the agent drove it
    $this->assertDatabaseHas('editor_operation_log', [
        'site_id' => $site->id,
        'operation' => 'update_form',
        'actor_user_id' => $staff->id,
        'actor_channel' => 'webmcp',
        'result_code' => 'ok',
    ]);

    $draft = PageRevision::find($page->fresh()->draft_revision_id);
    $section = collect($draft->content_data['sections'])->firstWhere('type', 'contact_form');
    expect($section['fields'][0]['name'])->toBe('job_postcode');
});

test('an agent form write is refused 403 when the actor role is outside agent_tools.roles', function () {
    config([
        'editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['client'],
    ]);
    [$site, $page] = seedFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $response = $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page) + ['X-Editor-Channel' => 'webmcp'])
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", [
            'fields' => [['label' => 'Job postcode', 'type' => 'text', 'required' => true]],
        ]);

    $response->assertForbidden();
    expect($response->json('error.code'))->toBe('forbidden');
    expect($page->fresh()->draft_revision_id)->toBeNull();
});

test('a human form write keeps the legacy body and the legacy write path', function () {
    config([
        'editor.agent_tools.enabled' => true, 'editor.operations.enabled' => true,
        'editor.agent_tools.roles' => ['staff'],
    ]);
    [$site, $page] = seedFormUpdateSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $response = $this->actingAs($staff)
        ->withHeaders(validFormRevisionHeader($page))
        ->postJson("/sites/{$site->id}/pages/{$page->id}/form/1", [
            'fields' => [['label' => 'Job postcode', 'type' => 'text', 'required' => true]],
        ]);

    $response->assertOk();
    // no envelope keys leak into the human response
    expect(array_keys($response->json()))->toEqualCanonicalizing(['status', 'revision_id', 'html']);
});

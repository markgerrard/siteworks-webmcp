<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\User;
use App\Services\Site\ContentShapeTranslator;
use App\Services\Site\SectionSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->staff = User::factory()->staff(AgentRole::Admin)->create());

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function seedLeadFormEditorSite(?array $leadFormSection = null): Site
{
    $site = Site::factory()->create(['business_name' => 'Test Plumbers']);
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['home_lead_form_enabled' => true],
    ]);

    $sections = [
        ['type' => 'hero', 'title' => 'Home Hero', 'subtitle' => 'Sub'],
    ];

    if ($leadFormSection !== null) {
        $sections[] = array_merge(['type' => 'lead_form'], $leadFormSection);
    }

    $content = ['sections' => $sections];

    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => $content,
        'sort_order' => 0,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);
    $rev = PageRevision::create([
        'page_id' => $page->id,
        'content_data' => $content,
        'ai_generated' => true,
        'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    Preview::factory()->create(['site_id' => $site->id]);

    return $site;
}

// ---------------------------------------------------------------------------
// ContentShapeTranslator round-trip tests
// ---------------------------------------------------------------------------

test('ContentShapeTranslator round-trips lead_form with benefits and extra_fields', function () {
    $translator = new ContentShapeTranslator(
        new SectionSchema(config('site_sections', []))
    );

    $raw = [
        'lead_form' => [
            'title' => 'Get a free quote today',
            'intro' => 'We reply within 24 hours.',
            'benefits' => ['Free no-obligation quote', 'Reply within 24 hours', 'Fully insured'],
            'submit_label' => 'Get my quote',
            'extra_fields' => [
                ['name' => 'service_type', 'label' => 'Service required', 'type' => 'select', 'options' => ['Boiler repair', 'Plumbing', 'Other'], 'required' => true],
                ['name' => 'is_emergency', 'label' => 'Is this an emergency?', 'type' => 'radio', 'options' => ['Yes', 'No'], 'required' => false],
            ],
        ],
    ];

    $out = $translator->translate($raw);

    // Must be in the sections array, not stripped.
    $leadForm = collect($out['sections'])->firstWhere('type', 'lead_form');
    expect($leadForm)->not->toBeNull();
    expect($leadForm['title'])->toBe('Get a free quote today');
    expect($leadForm['intro'])->toBe('We reply within 24 hours.');
    expect($leadForm['benefits'])->toEqual(['Free no-obligation quote', 'Reply within 24 hours', 'Fully insured']);
    expect($leadForm['submit_label'])->toBe('Get my quote');
    expect($leadForm['extra_fields'])->toHaveCount(2);
    expect($leadForm['extra_fields'][0]['name'])->toBe('service_type');
    expect($leadForm['extra_fields'][0]['options'])->toEqual(['Boiler repair', 'Plumbing', 'Other']);
    expect($leadForm['extra_fields'][1]['type'])->toBe('radio');
});

test('ContentShapeTranslator idempotent: lead_form section already in sections array passes through', function () {
    $translator = new ContentShapeTranslator(
        new SectionSchema(config('site_sections', []))
    );

    $input = [
        'sections' => [
            [
                'type' => 'lead_form',
                'title' => 'Already translated',
                'benefits' => ['Trust A', 'Trust B', 'Trust C'],
                'extra_fields' => [
                    ['name' => 'service_type', 'label' => 'Service', 'type' => 'select', 'options' => ['A', 'B'], 'required' => true],
                ],
            ],
        ],
    ];

    $out = $translator->translate($input);

    // Idempotent — returns input unchanged when already has sections key.
    expect($out)->toEqual($input);
});

test('ContentShapeTranslator drops non-array or non-string benefits entries', function () {
    $translator = new ContentShapeTranslator(
        new SectionSchema(config('site_sections', []))
    );

    $raw = [
        'lead_form' => [
            'title' => 'Quote',
            'benefits' => ['Good signal', null, 42, 'Another signal'],
        ],
    ];

    $out = $translator->translate($raw);
    $leadForm = collect($out['sections'])->firstWhere('type', 'lead_form');
    // Non-strings should be filtered out.
    expect($leadForm['benefits'])->toEqual(['Good signal', 'Another signal']);
});

// ---------------------------------------------------------------------------
// Livewire lead-form-editor tests
// ---------------------------------------------------------------------------

test('lead-form-editor mounts and reads existing lead_form data', function () {
    $site = seedLeadFormEditorSite([
        'title' => 'Get your free quote',
        'intro' => 'Fast response guaranteed.',
        'benefits' => ['Free quote', '24hr reply', 'Insured'],
        'submit_label' => 'Get quote',
        'extra_fields' => [
            ['name' => 'job_type', 'label' => 'Job type', 'type' => 'select', 'options' => ['Boiler', 'Pipes'], 'required' => true],
        ],
    ]);

    Livewire::actingAs($this->staff)
        ->test('lead-form-editor', ['siteId' => $site->id])
        ->assertSet('title', 'Get your free quote')
        ->assertSet('intro', 'Fast response guaranteed.')
        ->assertSet('submitLabel', 'Get quote')
        ->assertCount('benefits', 3)
        ->assertCount('extraFields', 1);
});

test('lead-form-editor save() creates a draft PageRevision with updated lead_form', function () {
    $site = seedLeadFormEditorSite([
        'title' => 'Old title',
        'benefits' => ['A', 'B', 'C'],
        'submit_label' => 'Old button',
        'extra_fields' => [],
    ]);

    Livewire::actingAs($this->staff)
        ->test('lead-form-editor', ['siteId' => $site->id])
        ->set('title', 'Get a free no-obligation quote')
        ->set('intro', 'Reply within 24 hours.')
        ->set('benefits.0', 'Free no-obligation quote')
        ->set('benefits.1', 'Reply within 24 hours')
        ->set('benefits.2', 'Gas Safe registered')
        ->set('submitLabel', 'Get my quote')
        ->call('save')
        ->assertDispatched('composition-dirty');

    $page = GeneratedPage::where('site_id', $site->id)->where('page_type', 'home')->first();
    $page->refresh();
    expect($page->draft_revision_id)->not->toBeNull();

    $draftRev = PageRevision::find($page->draft_revision_id);
    $leadForm = collect($draftRev->content_data['sections'])->firstWhere('type', 'lead_form');
    expect($leadForm['title'])->toBe('Get a free no-obligation quote');
    expect($leadForm['benefits'])->toEqual(['Free no-obligation quote', 'Reply within 24 hours', 'Gas Safe registered']);
    expect($leadForm['submit_label'])->toBe('Get my quote');
});

test('lead-form-editor save() bumps admin_revision', function () {
    $site = seedLeadFormEditorSite(['title' => 'Old', 'benefits' => ['A', 'B', 'C'], 'extra_fields' => []]);
    app(\App\Services\Site\CompositionService::class)->getOrCreateDraft($site);
    $before = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');

    Livewire::actingAs($this->staff)
        ->test('lead-form-editor', ['siteId' => $site->id])
        ->set('title', 'New headline')
        ->set('benefits.0', 'A')
        ->set('benefits.1', 'B')
        ->set('benefits.2', 'C')
        ->call('save');

    $after = (int) SiteDraft::where('site_id', $site->id)->value('admin_revision');
    expect($after)->toBe($before + 1);
});

test('lead-form-editor save() correctly serialises select extra field with options', function () {
    $site = seedLeadFormEditorSite(['title' => 'Quote', 'benefits' => ['A', 'B', 'C'], 'extra_fields' => []]);

    Livewire::actingAs($this->staff)
        ->test('lead-form-editor', ['siteId' => $site->id])
        ->set('title', 'Get a free quote')
        ->set('benefits', ['A', 'B', 'C'])
        // Options are a list, not a comma-separated string. Changed
        // when the single text box was replaced with add/remove rows -- the old
        // shape made commas structural, so an option containing one split in
        // two. The assertion below is unchanged: what gets SAVED is the same.
        ->set('extraFields', [
            ['name' => 'service_type', 'label' => 'Service', 'type' => 'select', 'options' => ['Boiler repair', 'Plumbing', 'Other'], 'required' => true],
        ])
        ->call('save');

    $page = GeneratedPage::where('site_id', $site->id)->where('page_type', 'home')->first()->refresh();
    $draftRev = PageRevision::find($page->draft_revision_id);
    $leadForm = collect($draftRev->content_data['sections'])->firstWhere('type', 'lead_form');
    expect($leadForm['extra_fields'][0]['name'])->toBe('service_type');
    expect($leadForm['extra_fields'][0]['options'])->toEqual(['Boiler repair', 'Plumbing', 'Other']);
});

test('lead-form-editor save() accepts date type extra field without options', function () {
    $site = seedLeadFormEditorSite(['title' => 'Quote', 'benefits' => ['A', 'B', 'C'], 'extra_fields' => []]);

    Livewire::actingAs($this->staff)
        ->test('lead-form-editor', ['siteId' => $site->id])
        ->set('title', 'Get a free quote')
        ->set('benefits', ['A', 'B', 'C'])
        ->set('extraFields', [
            ['name' => 'preferred_visit_date', 'label' => 'Preferred visit date', 'type' => 'date', 'options' => [], 'required' => false],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $page = GeneratedPage::where('site_id', $site->id)->where('page_type', 'home')->first()->refresh();
    $draftRev = PageRevision::find($page->draft_revision_id);
    $leadForm = collect($draftRev->content_data['sections'])->firstWhere('type', 'lead_form');
    expect($leadForm['extra_fields'][0]['type'])->toBe('date');
    expect($leadForm['extra_fields'][0])->not->toHaveKey('options');
});

test('lead-form-editor rejects extra_fields beyond the shared cap', function () {
    $site = seedLeadFormEditorSite(['title' => 'Quote', 'benefits' => ['A', 'B', 'C'], 'extra_fields' => []]);

    // Cap-relative: this editor and the form panel must agree, and they used to
    // drift — the review would save a form this editor then refused to save.
    $tooMany = collect(range(1, \App\Support\FormFieldDefinition::MAX_FIELDS + 1))
        ->map(fn ($n) => ['name' => "f{$n}", 'label' => "F{$n}", 'type' => 'text', 'options' => [], 'required' => false])
        ->all();

    Livewire::actingAs($this->staff)
        ->test('lead-form-editor', ['siteId' => $site->id])
        ->set('title', 'Quote')
        ->set('benefits', ['A', 'B', 'C'])
        ->set('extraFields', $tooMany)
        ->call('save')
        ->assertHasErrors(['extraFields']);
});

test('lead-form-editor saves the endpoint maximum plus Message', function () {
    $operatorFields = collect(range(1, \App\Support\FormFieldDefinition::MAX_FIELDS))
        ->map(fn (int $number): array => [
            'name' => "field_{$number}",
            'label' => "Field {$number}",
            'type' => 'text',
            'options' => [],
            'placeholder' => '',
            'required' => false,
        ])
        ->all();
    $messageField = [
        'name' => 'message',
        'label' => 'Message',
        'type' => 'textarea',
        'options' => [],
        'placeholder' => 'How can we help?',
        'required' => true,
    ];
    $site = seedLeadFormEditorSite([
        'title' => 'Quote',
        'benefits' => ['A', 'B', 'C'],
        'extra_fields' => [...$operatorFields, $messageField],
    ]);

    Livewire::actingAs($this->staff)
        ->test('lead-form-editor', ['siteId' => $site->id])
        ->call('save')
        ->assertHasNoErrors();

    $page = GeneratedPage::where('site_id', $site->id)->where('page_type', 'home')->first()->refresh();
    $draft = PageRevision::find($page->draft_revision_id);
    $leadForm = collect($draft->content_data['sections'])->firstWhere('type', 'lead_form');

    expect($leadForm['extra_fields'])->toHaveCount(\App\Support\FormFieldDefinition::MAX_FIELDS + 1);
});

test('lead-form-editor rejects title longer than 60 chars', function () {
    $site = seedLeadFormEditorSite(['title' => 'Short', 'benefits' => ['A', 'B', 'C'], 'extra_fields' => []]);

    Livewire::actingAs($this->staff)
        ->test('lead-form-editor', ['siteId' => $site->id])
        ->set('title', str_repeat('X', 61))
        ->set('benefits', ['A', 'B', 'C'])
        ->call('save')
        ->assertHasErrors(['title']);
});

test('lead-form-editor addExtraField and removeExtraField work correctly', function () {
    $site = seedLeadFormEditorSite(['title' => 'Quote', 'benefits' => ['A', 'B', 'C'], 'extra_fields' => []]);

    $component = Livewire::actingAs($this->staff)
        ->test('lead-form-editor', ['siteId' => $site->id]);

    $component->assertCount('extraFields', 0);

    $cap = \App\Support\FormFieldDefinition::MAX_FIELDS;

    $component->call('addExtraField');
    $component->assertCount('extraFields', 1);

    for ($i = 1; $i < $cap; $i++) {
        $component->call('addExtraField');
    }
    $component->assertCount('extraFields', $cap);

    // One past the cap is a no-op.
    $component->call('addExtraField');
    $component->assertCount('extraFields', $cap);

    $component->call('removeExtraField', 0);
    $component->assertCount('extraFields', $cap - 1);
});

test('lead-form-editor save() keeps a deleted Message and unknown section keys', function () {
    $site = seedLeadFormEditorSite([
        'title' => 'Quote',
        'benefits' => ['A', 'B', 'C'],
        'submit_label' => 'Send',
        'message_field_migrated' => true,
        'some_future_flag' => true,
        'extra_fields' => [
            [
                'name' => 'phone',
                'label' => 'Phone',
                'type' => 'tel',
                'placeholder' => '07xxx',
                'required' => false,
            ],
        ],
    ]);

    Livewire::actingAs($this->staff)
        ->test('lead-form-editor', ['siteId' => $site->id])
        ->set('title', 'Get a quote')
        ->set('benefits', ['A', 'B', 'C'])
        ->call('save')
        ->assertHasNoErrors();

    $page = GeneratedPage::where('site_id', $site->id)->where('page_type', 'home')->first()->refresh();
    $draftRev = PageRevision::find($page->draft_revision_id);
    $leadForm = collect($draftRev->content_data['sections'])->firstWhere('type', 'lead_form');

    expect($leadForm['message_field_migrated'])->toBeTrue()
        ->and($leadForm['some_future_flag'])->toBeTrue()
        ->and(collect($leadForm['extra_fields'])->pluck('name'))->not->toContain('message')
        ->and($leadForm['extra_fields'][0]['placeholder'])->toBe('07xxx');

    $html = view('site.sections.lead_form', [
        'section' => $leadForm,
        'sectionIndex' => 1,
        'pageId' => $page->id,
        'emitMarkers' => false,
    ])->render();

    expect($html)->not->toContain('name="message"');
});

test('lead-form-editor moveExtraFieldUp and moveExtraFieldDown reorder fields', function () {
    $site = seedLeadFormEditorSite([
        'title' => 'Quote',
        'benefits' => ['A', 'B', 'C'],
        'extra_fields' => [
            ['name' => 'first', 'label' => 'First', 'type' => 'text', 'required' => false],
            ['name' => 'second', 'label' => 'Second', 'type' => 'text', 'required' => false],
        ],
    ]);

    $component = Livewire::actingAs($this->staff)
        ->test('lead-form-editor', ['siteId' => $site->id]);

    // Move index 1 up → it should now be at index 0.
    $component->call('moveExtraFieldUp', 1);

    $fields = $component->get('extraFields');
    expect($fields[0]['name'])->toBe('second');
    expect($fields[1]['name'])->toBe('first');
});

// ---------------------------------------------------------------------------
// Smoke test — PageRenderer picks up lead_form section
// ---------------------------------------------------------------------------

test('PageRenderer renders lead_form section when present in content_data', function () {
    $site = seedLeadFormEditorSite([
        'title' => 'Get a free quote today',
        'intro' => 'We respond within 24 hours.',
        'benefits' => ['Free no-obligation quote', 'Reply within 24 hours', 'Fully insured'],
        'submit_label' => 'Get my quote',
        'extra_fields' => [
            ['name' => 'service_type', 'label' => 'Service required', 'type' => 'select', 'options' => ['Boiler', 'Plumbing', 'Other'], 'required' => true],
        ],
    ]);

    // Ensure lead_form_enabled flag is set.
    $site->businessProfile->update(['profile_data' => ['home_lead_form_enabled' => true]]);

    // Wire up a published version so PageRenderer can resolve it.
    $homePage = GeneratedPage::where('site_id', $site->id)->where('page_type', 'home')->first();
    $version = \App\Models\Site\SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $homePage->id,
        ],
        'page_revisions' => [['page_id' => $homePage->id, 'revision_id' => $homePage->published_revision_id]],
        'published_at' => now(),
    ]);
    \App\Models\Site\SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    $html = app(\App\Services\Site\PageRenderer::class)->render($site, $homePage->id, mode: 'public');

    // Core fields are always present.
    expect($html)->toContain('name="message"');
    // The AI-generated title should appear somewhere in the form section.
    expect($html)->toContain('Get a free quote today');
    // Submit label.
    expect($html)->toContain('Get my quote');
});

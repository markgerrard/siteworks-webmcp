<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->staff = User::factory()->staff(AgentRole::Admin)->create());

/**
 * Local seed. Pest does not share top-level functions between test files, and
 * the near-identical helper in LeadFormEditorTest.php cannot be reused without
 * a redeclare collision once the whole suite loads both.
 */
function seedLeadFormOptionsSite(array $leadFormSection): Site
{
    $site = Site::factory()->create(['business_name' => 'Test Plumbers']);
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['home_lead_form_enabled' => true],
    ]);

    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Home Hero', 'subtitle' => 'Sub'],
        array_merge(['type' => 'lead_form'], $leadFormSection),
    ]];

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

/*
 * Select/radio options used to be one comma-separated text box, which asks a
 * client to know that commas are structural -- so an option containing a
 * comma silently became two options, and there was no way to see how many
 * options you actually had. These cover the add/remove list that replaces it.
 */

test('existing options load as a list, not a comma-separated string', function () {
    $site = seedLeadFormOptionsSite([
        'title' => 'Get a quote',
        'extra_fields' => [
            ['name' => 'service_type', 'label' => 'Service', 'type' => 'select', 'options' => ['Boiler', 'Pipes'], 'required' => true],
        ],
    ]);

    $options = Livewire::actingAs($this->staff)
        ->test('lead-form-editor', ['siteId' => $site->id])
        ->get('extraFields')[0]['options'];

    expect($options)->toBe(['Boiler', 'Pipes']);
});

test('an option can be added to a field', function () {
    $site = seedLeadFormOptionsSite([
        'title' => 'Get a quote',
        'extra_fields' => [
            ['name' => 'service_type', 'label' => 'Service', 'type' => 'select', 'options' => ['Boiler'], 'required' => true],
        ],
    ]);

    $options = Livewire::actingAs($this->staff)
        ->test('lead-form-editor', ['siteId' => $site->id])
        ->call('addOption', 0)
        ->get('extraFields')[0]['options'];

    // A blank row to type into, not a placeholder value that could be saved.
    expect($options)->toBe(['Boiler', '']);
});

test('an option can be removed and the list closes up', function () {
    $site = seedLeadFormOptionsSite([
        'title' => 'Get a quote',
        'extra_fields' => [
            ['name' => 'service_type', 'label' => 'Service', 'type' => 'select', 'options' => ['Boiler', 'Pipes', 'Drains'], 'required' => true],
        ],
    ]);

    $options = Livewire::actingAs($this->staff)
        ->test('lead-form-editor', ['siteId' => $site->id])
        ->call('removeOption', 0, 1)
        ->get('extraFields')[0]['options'];

    // Re-indexed: a gap would serialise as a JSON object rather than a list.
    expect($options)->toBe(['Boiler', 'Drains']);
});

test('an option containing a comma survives a save', function () {
    // The whole point of dropping the comma-separated box: "Repairs, servicing
    // and callouts" used to split into three options.
    $site = seedLeadFormOptionsSite([
        'title' => 'Get a quote',
        'extra_fields' => [
            ['name' => 'service_type', 'label' => 'Service', 'type' => 'select', 'options' => ['Boiler'], 'required' => true],
        ],
    ]);

    Livewire::actingAs($this->staff)
        ->test('lead-form-editor', ['siteId' => $site->id])
        ->set('extraFields.0.options', ['Repairs, servicing and callouts', 'Installations'])
        ->call('save');

    $sections = $site->generatedPages()->where('page_type', 'home')->first()->content_data['sections'];
    $leadForm = collect($sections)->firstWhere('type', 'lead_form');

    expect($leadForm['extra_fields'][0]['options'])
        ->toBe(['Repairs, servicing and callouts', 'Installations']);
});

test('blank option rows are dropped on save', function () {
    // addOption() appends an empty row; a client who adds one and changes
    // their mind must not ship an empty <option>.
    $site = seedLeadFormOptionsSite([
        'title' => 'Get a quote',
        'extra_fields' => [
            ['name' => 'service_type', 'label' => 'Service', 'type' => 'select', 'options' => ['Boiler'], 'required' => true],
        ],
    ]);

    Livewire::actingAs($this->staff)
        ->test('lead-form-editor', ['siteId' => $site->id])
        ->set('extraFields.0.options', ['Boiler', '', '  ', 'Drains'])
        ->call('save');

    $sections = $site->generatedPages()->where('page_type', 'home')->first()->content_data['sections'];
    $leadForm = collect($sections)->firstWhere('type', 'lead_form');

    expect($leadForm['extra_fields'][0]['options'])->toBe(['Boiler', 'Drains']);
});

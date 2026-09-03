<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Preview;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->staff = User::factory()->staff(AgentRole::Admin)->create());

/*
 * These only started to matter when contact_form.blade.php was
 * wired to render $section['fields']. Until then page-manager's contact editor
 * was write-only, so its option parsing could be wrong without any visible
 * effect. Now it reaches the public site.
 */

function seedContactFormSite(array $fields): Site
{
    $site = Site::factory()->create(['business_name' => 'Test Plumbers']);

    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Home Hero', 'subtitle' => 'Sub'],
        ['type' => 'contact_form', 'title' => 'Contact us', 'fields' => $fields],
    ]];

    $page = GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'contact',
        'content_data' => $content,
        'sort_order' => 1,
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

function savedContactFields(Site $site): array
{
    $page = $site->generatedPages()->where('page_type', 'contact')->first()->refresh();
    $content = $page->draft_revision_id
        ? PageRevision::find($page->draft_revision_id)->content_data
        : $page->content_data;

    return collect($content['sections'])->firstWhere('type', 'contact_form')['fields'] ?? [];
}

test('a radio field keeps its options through a save', function () {
    // The parser only handled `select`, so a radio field's options were
    // dropped on every save -- invisible while nothing rendered them.
    $site = seedContactFormSite([
        ['name' => 'urgency', 'label' => 'How urgent?', 'type' => 'radio', 'options' => ['Emergency', 'This week'], 'required' => false],
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'contact', 'contact_form')
        ->call('saveSection');

    $fields = savedContactFields($site);

    expect($fields[0]['options'] ?? null)->toBe(['Emergency', 'This week']);
});

test('an option can be added and removed on a contact field', function () {
    // Parity with lead-form-editor: clients get add/remove rows rather than
    // having to know that commas are structural.
    $site = seedContactFormSite([
        ['name' => 'service', 'label' => 'Service', 'type' => 'select', 'options' => ['Boiler'], 'required' => false],
    ]);

    $component = Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'contact', 'contact_form')
        ->call('addFieldOption', 0);

    expect($component->get('editFormFields')[0]['options'])->toBe(['Boiler', '']);

    $component->call('removeFieldOption', 0, 0);

    expect($component->get('editFormFields')[0]['options'])->toBe(['']);
});

test('a trailing empty option is not saved', function () {
    // "a, b, " yielded a third, empty option, which would render as a blank
    // choice on the public form.
    $site = seedContactFormSite([
        ['name' => 'service', 'label' => 'Service', 'type' => 'select', 'options' => ['Boiler', 'Drains'], 'required' => false],
    ]);

    $component = Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'contact', 'contact_form');

    $fields = $component->get('editFormFields');
    $fields[0]['options'] = ['Boiler', '', 'Drains', '   '];
    // Changed too, so the assertion below cannot pass on a save that silently
    // did nothing -- the seeded options are already ['Boiler', 'Drains'].
    $fields[0]['label'] = 'Which service?';

    $component->set('editFormFields', $fields)->call('saveSection');

    $saved = savedContactFields($site);

    expect($saved[0]['label'] ?? null)->toBe('Which service?')
        ->and($saved[0]['options'] ?? null)->toBe(['Boiler', 'Drains']);
});

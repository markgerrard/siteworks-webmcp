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
 * The contact editor could only ever edit fields the AI generator had already
 * written -- there was no way to add one. Worse, saveSection() only wrote the
 * fields key back when it ALREADY existed, so on a section without one (which
 * is every site today) an added field would have been discarded silently.
 */

function seedContactSite(?array $fields = null): Site
{
    $site = Site::factory()->create(['business_name' => 'Test Plumbers']);

    $contactSection = ['type' => 'contact_form', 'title' => 'Contact us'];
    if ($fields !== null) {
        $contactSection['fields'] = $fields;
    }

    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Home Hero', 'subtitle' => 'Sub'],
        $contactSection,
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

function savedContactSection(Site $site): array
{
    $page = $site->generatedPages()->where('page_type', 'contact')->first()->refresh();
    $content = $page->draft_revision_id
        ? PageRevision::find($page->draft_revision_id)->content_data
        : $page->content_data;

    return collect($content['sections'])->firstWhere('type', 'contact_form') ?? [];
}

test('a field can be added to a contact form that has none', function () {
    // The case that matters: no site in the estate has a fields key.
    $site = seedContactSite(null);

    $component = Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'contact', 'contact_form')
        ->call('addFormField');

    $fields = $component->get('editFormFields');
    $fields[0]['name'] = 'job_postcode';
    $fields[0]['label'] = 'Job postcode';

    $component->set('editFormFields', $fields)->call('saveSection');

    $saved = savedContactSection($site);

    expect($saved['fields'][0]['name'] ?? null)->toBe('job_postcode')
        ->and($saved['fields'][0]['label'] ?? null)->toBe('Job postcode');
});

test('a field can be removed and the list closes up', function () {
    $site = seedContactSite([
        ['name' => 'a', 'label' => 'A', 'type' => 'text', 'required' => false],
        ['name' => 'b', 'label' => 'B', 'type' => 'text', 'required' => false],
        ['name' => 'c', 'label' => 'C', 'type' => 'text', 'required' => false],
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'contact', 'contact_form')
        ->call('removeFormField', 1)
        ->call('saveSection');

    expect(array_column(savedContactSection($site)['fields'], 'name'))->toBe(['a', 'c']);
});

test('removing the last field leaves the form on its standard four', function () {
    // An empty list must not mean "a form with no inputs" -- the renderer
    // falls back, and this asserts the saved shape supports that.
    $site = seedContactSite([
        ['name' => 'only', 'label' => 'Only', 'type' => 'text', 'required' => false],
    ]);

    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'contact', 'contact_form')
        ->call('removeFormField', 0)
        ->call('saveSection');

    expect(savedContactSection($site)['fields'] ?? null)->toBe([]);
});

test('a field key is sanitised into something an input name can hold', function () {
    // The key becomes an HTML input name and an enquiry payload key. A client
    // typing "Job Postcode!" must not produce a broken form.
    $site = seedContactSite(null);

    $component = Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'contact', 'contact_form')
        ->call('addFormField');

    $fields = $component->get('editFormFields');
    $fields[0]['name'] = 'Job Postcode!';
    $fields[0]['label'] = 'Job postcode';

    $component->set('editFormFields', $fields)->call('saveSection');

    expect(savedContactSection($site)['fields'][0]['name'])->toBe('job_postcode');
});

test('a field with no key at all is dropped rather than saved', function () {
    // An input with no name submits nothing, so saving one is storing a field
    // that can never collect an answer.
    $site = seedContactSite(null);

    $component = Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'contact', 'contact_form')
        ->call('addFormField');

    $fields = $component->get('editFormFields');
    $fields[0]['name'] = '';
    $fields[0]['label'] = 'Nameless';

    $component->set('editFormFields', $fields)->call('saveSection');

    expect(savedContactSection($site)['fields'] ?? null)->toBe([]);
});

test('fields cannot be added without bound', function () {
    $site = seedContactSite(null);

    $component = Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'contact', 'contact_form');

    for ($i = 0; $i < 12; $i++) {
        $component->call('addFormField');
    }

    expect(count($component->get('editFormFields')))->toBe(8);
});

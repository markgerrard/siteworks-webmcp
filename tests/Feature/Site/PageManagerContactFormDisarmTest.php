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

beforeEach(function () {
    $this->staff = User::factory()->staff(AgentRole::Admin)->create();
});

function seedUntouchedContactSite(): array
{
    $site = Site::factory()->create(['business_name' => 'Acme Trades']);

    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Hero'],
        ['type' => 'contact_form', 'title' => 'Contact Us', 'submit_label' => 'Send'],
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

    return [$site, $page];
}

function seedCustomFieldsContactSite(array $fields): array
{
    $site = Site::factory()->create(['business_name' => 'Acme Trades']);

    $content = ['sections' => [
        ['type' => 'hero', 'title' => 'Hero'],
        ['type' => 'contact_form', 'title' => 'Contact Us', 'submit_label' => 'Send', 'fields' => $fields, 'fields_migrated' => true],
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
        'ai_generated' => false,
        'created_at' => now(),
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    Preview::factory()->create(['site_id' => $site->id]);

    return [$site, $page];
}

test('a title-only admin save on an untouched contact form leaves Phone and Message offered', function () {
    [$site, $page] = seedUntouchedContactSite();
    $site->update(['created_by_user_id' => $this->staff->id]);

    // Admin edits only the title in page-manager
    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'contact', 'contact_form')
        ->set('editHeading', 'Get in Touch Today')
        ->call('saveSection');

    // Verify form definition endpoint still offers the implicit Phone + Message fields
    $this->actingAs($this->staff)
        ->getJson("/sites/{$site->id}/pages/{$page->id}/form/1")
        ->assertOk()
        ->assertJsonPath('title', 'Get in Touch Today')
        ->assertJsonCount(2, 'fields')
        ->assertJsonPath('fields.0.name', 'phone')
        ->assertJsonPath('fields.1.name', 'message');

    // Also verify the saved draft revision does NOT stamp fields_migrated = true
    $page->refresh();
    $draftRev = PageRevision::find($page->draft_revision_id);
    $savedSection = $draftRev->content_data['sections'][1];
    expect($savedSection['fields_migrated'] ?? false)->toBeFalse()
        ->and(array_key_exists('fields', $savedSection))->toBeFalse();
});

test('an explicit deletion of all fields from a contact form still sticks', function () {
    [$site, $page] = seedCustomFieldsContactSite([
        ['name' => 'service', 'label' => 'Service', 'type' => 'text', 'required' => false],
    ]);
    $site->update(['created_by_user_id' => $this->staff->id]);

    // Admin removes the existing custom field
    Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'contact', 'contact_form')
        ->call('removeFormField', 0)
        ->call('saveSection');

    // Verify form definition endpoint honours the explicit deletion and offers no fields
    $this->actingAs($this->staff)
        ->getJson("/sites/{$site->id}/pages/{$page->id}/form/1")
        ->assertOk()
        ->assertJsonCount(0, 'fields');

    $page->refresh();
    $draftRev = PageRevision::find($page->draft_revision_id);
    $savedSection = $draftRev->content_data['sections'][1];
    expect($savedSection['fields_migrated'] ?? false)->toBeTrue()
        ->and($savedSection['fields'] ?? null)->toBe([]);
});

test('an admin save that genuinely changes the field list behaves as expected', function () {
    [$site, $page] = seedUntouchedContactSite();
    $site->update(['created_by_user_id' => $this->staff->id]);

    // Admin adds a custom field
    $component = Livewire::actingAs($this->staff)
        ->test('page-manager', ['siteId' => $site->id])
        ->call('edit', 'contact', 'contact_form')
        ->call('addFormField');

    $fields = $component->get('editFormFields');
    $fields[0]['name'] = 'job_postcode';
    $fields[0]['label'] = 'Job Postcode';
    $component->set('editFormFields', $fields)->call('saveSection');

    // Verify form definition endpoint returns the added field
    $this->actingAs($this->staff)
        ->getJson("/sites/{$site->id}/pages/{$page->id}/form/1")
        ->assertOk()
        ->assertJsonCount(1, 'fields')
        ->assertJsonPath('fields.0.name', 'job_postcode');

    $page->refresh();
    $draftRev = PageRevision::find($page->draft_revision_id);
    $savedSection = $draftRev->content_data['sections'][1];
    expect($savedSection['fields_migrated'] ?? false)->toBeTrue()
        ->and($savedSection['fields'][0]['name'])->toBe('job_postcode');
});

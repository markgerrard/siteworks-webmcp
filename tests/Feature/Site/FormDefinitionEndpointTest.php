<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\Client;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\User;
use App\Support\FormFieldDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedFormEndpointSite(): array
{
    $site = Site::factory()->create();
    $page = GeneratedPage::create([
        'site_id' => $site->id, 'page_type' => 'contact',
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Hi'],
            ['type' => 'contact_form', 'title' => 'Contact us', 'submit_label' => 'Send',
                'fields' => [['name' => 'service', 'label' => 'Service', 'type' => 'select', 'options' => ['Boiler']]]],
        ]],
        'sort_order' => 1, 'version' => 1, 'status' => PageStatus::Published,
    ]);

    return [$site, $page];
}

function seedLeadFormDefinitionSite(array $extraFields = [], bool $messageFieldMigrated = false): array
{
    $site = Site::factory()->create();
    $page = GeneratedPage::create([
        'site_id' => $site->id, 'page_type' => 'home',
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Hi'],
            [
                'type' => 'lead_form',
                'title' => 'Get a quote',
                'extra_fields' => $extraFields,
                'message_field_migrated' => $messageFieldMigrated,
            ],
        ]],
        'sort_order' => 0, 'version' => 1, 'status' => PageStatus::Published,
    ]);

    return [$site, $page];
}

test('staff can read a form definition', function () {
    [$site, $page] = seedFormEndpointSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    // SitePolicy scopes agents to sites they created or are assigned to.
    $site->update(['created_by_user_id' => $staff->id]);

    $this->actingAs($staff)
        ->getJson("/sites/{$site->id}/pages/{$page->id}/form/1")
        ->assertOk()
        ->assertJsonPath('section_type', 'contact_form')
        ->assertJsonPath('title', 'Contact us')
        ->assertJsonPath('fields.0.name', 'service')
        ->assertJsonPath('fields.0.options.0', 'Boiler')
        ->assertJsonPath('max_fields', 10);
});

test('an untouched contact form exposes its implicit fields', function (bool $storedFieldsKeyExists) {
    [$site, $page] = seedFormEndpointSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $contactForm = ['type' => 'contact_form', 'title' => 'Contact us'];
    if ($storedFieldsKeyExists) {
        $contactForm['fields'] = [];
    }
    $page->update(['content_data' => ['sections' => [
        ['type' => 'hero', 'title' => 'Hi'],
        $contactForm,
    ]]]);

    $this->actingAs($staff)
        ->getJson("/sites/{$site->id}/pages/{$page->id}/form/1")
        ->assertOk()
        ->assertJsonPath('fields.0.name', 'phone')
        ->assertJsonPath('fields.0.type', 'tel')
        ->assertJsonPath('fields.1.name', 'message')
        ->assertJsonPath('fields.1.type', 'textarea')
        ->assertJsonCount(2, 'fields')
        ->assertJsonPath('max_fields', 10);
})->with([
    'fields absent' => false,
    'fields empty' => true,
]);

test('a partially deleted contact form exposes only its remaining field', function () {
    [$site, $page] = seedFormEndpointSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $this->actingAs($staff)
        ->getJson("/sites/{$site->id}/pages/{$page->id}/form/1")
        ->assertOk()
        ->assertJsonCount(1, 'fields')
        ->assertJsonPath('fields.0.name', 'service');
});

test('a client user can read their own site form', function () {
    [$site, $page] = seedFormEndpointSite();
    $client = Client::factory()->create();
    $site->update(['client_id' => $client->id]);
    $user = User::factory()->create(['client_id' => $client->id, 'role' => null, 'last_login_at' => now()]);

    $this->actingAs($user)
        ->getJson("/sites/{$site->id}/pages/{$page->id}/form/1")
        ->assertOk();
});

test('an untouched lead form exposes Message after its existing fields', function () {
    [$site, $page] = seedLeadFormDefinitionSite([
        ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel'],
        ['name' => 'postcode', 'label' => 'Postcode', 'type' => 'text'],
    ]);
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $this->actingAs($staff)
        ->getJson("/sites/{$site->id}/pages/{$page->id}/form/1")
        ->assertOk()
        ->assertJsonCount(3, 'fields')
        ->assertJsonPath('fields.2.name', 'message')
        ->assertJsonPath('fields.2.label', 'Message')
        ->assertJsonPath('fields.2.type', 'textarea')
        ->assertJsonPath('fields.2.required', true)
        ->assertJsonPath('fields.2.placeholder', 'How can we help?')
        ->assertJsonPath('max_fields', 10);
});

test('a lead form definition does not duplicate an existing Message field', function () {
    [$site, $page] = seedLeadFormDefinitionSite([
        ['name' => 'message', 'label' => 'Tell us more', 'type' => 'textarea', 'required' => false],
        ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel'],
    ]);
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $response = $this->actingAs($staff)
        ->getJson("/sites/{$site->id}/pages/{$page->id}/form/1")
        ->assertOk()
        ->assertJsonCount(2, 'fields');

    expect(collect($response->json('fields'))->where('name', 'message'))->toHaveCount(1);
});

test('a migrated lead form definition does not restore a deleted Message field', function () {
    [$site, $page] = seedLeadFormDefinitionSite([
        ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel'],
    ], messageFieldMigrated: true);
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $response = $this->actingAs($staff)
        ->getJson("/sites/{$site->id}/pages/{$page->id}/form/1")
        ->assertOk()
        ->assertJsonCount(1, 'fields');

    expect(collect($response->json('fields'))->pluck('name'))->not->toContain('message');
});

test('a form definition exposes the shared options ceiling', function () {
    [$site, $page] = seedFormEndpointSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $this->actingAs($staff)
        ->getJson("/sites/{$site->id}/pages/{$page->id}/form/1")
        ->assertOk()
        ->assertJsonPath('max_options', FormFieldDefinition::MAX_OPTIONS);
});

test('a stranger cannot read it', function () {
    [$site, $page] = seedFormEndpointSite();

    $this->actingAs(User::factory()->create())
        ->getJson("/sites/{$site->id}/pages/{$page->id}/form/1")
        ->assertForbidden();
});

test('a section that is not a form is refused', function () {
    [$site, $page] = seedFormEndpointSite();
    $staff = User::factory()->staff(AgentRole::Agent)->create();
    $site->update(['created_by_user_id' => $staff->id]);

    $this->actingAs($staff)
        ->getJson("/sites/{$site->id}/pages/{$page->id}/form/0")
        ->assertStatus(422);
});

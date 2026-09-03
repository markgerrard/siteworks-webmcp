<?php

use App\Enums\AgentRole;
use App\Enums\PageStatus;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\SiteEnquiry;
use App\Models\User;
use App\Services\Site\SiteHostResolver;
use App\Support\EnquiryFieldLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('an enquiry can carry a label map alongside its payload', function () {
    $site = Site::factory()->create();

    $enquiry = SiteEnquiry::create([
        'site_id' => $site->id,
        'name' => 'Jo Bloggs',
        'email' => 'jo@example.test',
        'payload' => ['job_postcode' => 'RM1 1DA'],
        'field_labels' => ['job_postcode' => 'Job postcode'],
        'ip_hash' => hash('sha256', '127.0.0.1'),
    ]);

    expect($enquiry->fresh()->field_labels)->toBe(['job_postcode' => 'Job postcode']);
});

test('field_labels is null for an enquiry that predates the column', function () {
    // Every row already in the table is in this state; the inbox must cope.
    $site = Site::factory()->create();

    $enquiry = SiteEnquiry::create([
        'site_id' => $site->id,
        'name' => 'Jo Bloggs',
        'email' => 'jo@example.test',
        'payload' => ['job_postcode' => 'RM1 1DA'],
        'ip_hash' => hash('sha256', '127.0.0.1'),
    ]);

    expect($enquiry->fresh()->field_labels)->toBeNull();
});

test('labels are captured from the page the enquiry was submitted from', function () {
    $site = Site::factory()->create();
    GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'contact',
        'content_data' => ['sections' => [[
            'type' => 'contact_form',
            'fields' => [
                ['name' => 'job_postcode', 'label' => 'Job postcode', 'type' => 'text'],
                ['name' => 'service', 'label' => 'Service needed', 'type' => 'select'],
            ],
        ]]],
        'sort_order' => 1,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);

    $labels = EnquiryFieldLabels::forPage(
        GeneratedPage::where('site_id', $site->id)->where('page_type', 'contact')->first()
    );

    expect($labels)->toBe([
        'job_postcode' => 'Job postcode',
        'service' => 'Service needed',
    ]);
});

test('lead form extra_fields are captured too', function () {
    $site = Site::factory()->create();
    GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'home',
        'content_data' => ['sections' => [[
            'type' => 'lead_form',
            'extra_fields' => [
                ['name' => 'urgency', 'label' => 'How urgent?', 'type' => 'radio'],
            ],
        ]]],
        'sort_order' => 0,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);

    $labels = EnquiryFieldLabels::forPage(
        GeneratedPage::where('site_id', $site->id)->where('page_type', 'home')->first()
    );

    expect($labels)->toBe(['urgency' => 'How urgent?']);
});

test('a bare key humanises when no label was captured', function () {
    expect(EnquiryFieldLabels::humanise('job_postcode'))->toBe('Job postcode');
});

test('submitting an enquiry stores the labels for the keys it carried', function () {
    // config('domains.site_public_suffix') does not exist. Existing enquiry
    // tests resolve the site by mocking SiteHostResolver — copy that, do
    // not invent a host suffix.
    $site = Site::factory()->create();
    test()->mock(SiteHostResolver::class, fn ($mock) => $mock->shouldReceive('resolve')->andReturn($site));

    GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'contact',
        'content_data' => ['sections' => [[
            'type' => 'contact_form',
            'fields' => [['name' => 'job_postcode', 'label' => 'Job postcode', 'type' => 'text']],
        ]]],
        'sort_order' => 1,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);

    $this->postJson('/enquiries', [
        'name' => 'Jo Bloggs',
        'email' => 'jo@example.test',
        'page_type' => 'contact',
        'job_postcode' => 'RM1 1DA',
    ])->assertOk();

    $enquiry = SiteEnquiry::where('site_id', $site->id)->firstOrFail();

    expect($enquiry->payload['job_postcode'])->toBe('RM1 1DA')
        ->and($enquiry->field_labels['job_postcode'])->toBe('Job postcode');
});

test('the stored label map only contains keys that were actually submitted', function () {
    $site = Site::factory()->create();
    test()->mock(SiteHostResolver::class, fn ($mock) => $mock->shouldReceive('resolve')->andReturn($site));

    GeneratedPage::create([
        'site_id' => $site->id,
        'page_type' => 'contact',
        'content_data' => ['sections' => [[
            'type' => 'contact_form',
            'fields' => [
                ['name' => 'job_postcode', 'label' => 'Job postcode', 'type' => 'text'],
                ['name' => 'service', 'label' => 'Service needed', 'type' => 'select'],
            ],
        ]]],
        'sort_order' => 1,
        'version' => 1,
        'status' => PageStatus::Published,
    ]);

    $this->postJson('/enquiries', [
        'name' => 'Jo Bloggs',
        'email' => 'jo@example.test',
        'page_type' => 'contact',
        'job_postcode' => 'RM1 1DA',
    ])->assertOk();

    $enquiry = SiteEnquiry::where('site_id', $site->id)->firstOrFail();

    expect($enquiry->field_labels)->toBe(['job_postcode' => 'Job postcode'])
        ->and($enquiry->field_labels)->not->toHaveKey('service');
});

test('the inbox shows the stored label, and humanises a key with none', function () {
    // enquiries-list.blade.php is a shared include, not a Livewire
    // component. The staff inbox that mounts it is registered as
    // 'enquiries-inbox' (see tests/Feature/Livewire/EnquiriesInboxTest.php).
    $staff = User::factory()->staff(AgentRole::Admin)->create();
    $site = Site::factory()->create();

    SiteEnquiry::create([
        'site_id' => $site->id,
        'name' => 'Labelled',
        'email' => 'a@example.test',
        'payload' => ['job_postcode' => 'RM1 1DA'],
        'field_labels' => ['job_postcode' => 'Job postcode'],
        'ip_hash' => 'x',
    ]);
    SiteEnquiry::create([
        'site_id' => $site->id,
        'name' => 'Legacy',
        'email' => 'b@example.test',
        'payload' => ['site_access' => 'Rear gate'],
        'ip_hash' => 'y',
    ]);

    Livewire::actingAs($staff)
        ->test('enquiries-inbox', ['siteId' => $site->id])
        ->assertSee('Job postcode')   // stored label
        ->assertSee('Site access');   // humanised fallback
});

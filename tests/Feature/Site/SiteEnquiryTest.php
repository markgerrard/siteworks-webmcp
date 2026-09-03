<?php

use App\Mail\SiteEnquiryReceived;
use App\Models\Site;
use App\Models\SiteEnquiry;
use App\Services\Site\SiteHostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function enquirySite(array $attributes = []): Site
{
    $site = Site::factory()->create($attributes);

    test()->mock(SiteHostResolver::class, fn ($mock) => $mock->shouldReceive('resolve')->andReturn($site));

    return $site;
}

function submitEnquiry(array $overrides = [])
{
    return test()->postJson('/enquiries', array_merge([
        'name' => 'Nadia',
        'email' => 'nadia@example.com',
        'message' => 'Quote for a loft conversion please.',
        'service' => 'Extensions & Loft Conversions',
        'page_type' => 'home',
        'website' => '',
    ], $overrides));
}

test('submission 404s when the host resolves to no site', function () {
    test()->mock(SiteHostResolver::class, fn ($mock) => $mock->shouldReceive('resolve')->andReturn(null));

    test()->postJson('/enquiries', ['name' => 'X', 'email' => 'x@example.com'])->assertNotFound();
    expect(SiteEnquiry::count())->toBe(0);
});

test('valid submission stores the enquiry with extra fields in payload', function () {
    Mail::fake();
    $site = enquirySite();

    submitEnquiry()->assertSuccessful();

    $enquiry = SiteEnquiry::sole();
    expect($enquiry->site_id)->toBe($site->id)
        ->and($enquiry->name)->toBe('Nadia')
        ->and($enquiry->email)->toBe('nadia@example.com')
        ->and($enquiry->page_type)->toBe('home')
        ->and($enquiry->payload['message'])->toBe('Quote for a loft conversion please.')
        ->and($enquiry->payload['service'])->toBe('Extensions & Loft Conversions')
        ->and($enquiry->payload)->not->toHaveKeys(['name', 'email', 'website', 'page_type']);
});

test('no email is sent when the site has no notification address', function () {
    Mail::fake();
    enquirySite(['enquiry_notification_email' => null]);

    submitEnquiry()->assertSuccessful();

    Mail::assertNothingOutgoing();
    expect(SiteEnquiry::count())->toBe(1);
});

test('email goes to the configured address with reply-to the enquirer', function () {
    Mail::fake();
    enquirySite(['enquiry_notification_email' => 'owner@business.example']);

    submitEnquiry()->assertSuccessful();

    Mail::assertQueued(SiteEnquiryReceived::class, function (SiteEnquiryReceived $mail) {
        return $mail->hasTo('owner@business.example')
            && $mail->hasFrom(config('site.enquiry_from_address'))
            && $mail->hasReplyTo('nadia@example.com')
            && $mail->enquiry->name === 'Nadia';
    });
});

test('honeypot submissions pretend success, store nothing, send nothing', function () {
    Mail::fake();
    enquirySite(['enquiry_notification_email' => 'owner@business.example']);

    submitEnquiry(['website' => 'https://spam.example'])->assertSuccessful();

    Mail::assertNothingOutgoing();
    expect(SiteEnquiry::count())->toBe(0);
});

test('missing email is rejected', function () {
    Mail::fake();
    enquirySite();

    submitEnquiry(['email' => 'not-an-email'])->assertUnprocessable();
    expect(SiteEnquiry::count())->toBe(0);
});

test('list command shows recent enquiries', function () {
    $site = Site::factory()->create();
    SiteEnquiry::factory()->for($site)->create(['name' => 'Listed Lena']);

    $this->artisan('site-enquiries:list', ['site' => $site->id])
        ->expectsOutputToContain('Listed Lena')
        ->assertSuccessful();
});

test('both public form sections post to /enquiries with honeypot, no fake action', function () {
    $shared = [
        'pageId' => 1, 'sectionIndex' => 0, 'emitMarkers' => false,
        'profile' => [], 'theme' => [], 'pageType' => 'home',
        'site' => Site::factory()->create(),
    ];

    $contact = view('site.sections.contact_form', $shared + [
        'section' => ['type' => 'contact_form', 'title' => 'Contact'],
    ])->render();
    $lead = view('site.sections.lead_form', $shared + [
        'section' => ['type' => 'lead_form', 'title' => 'Get a quote'],
    ])->render();

    foreach ([$contact, $lead] as $html) {
        expect($html)->toContain("fetch('/enquiries'")
            ->and($html)->toContain('name="website"')
            ->and($html)->not->toContain('action="#"');
    }
});

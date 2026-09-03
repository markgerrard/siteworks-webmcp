<?php

use App\Enums\LeadFormPolicy;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupLeadFormSite(bool $flagEnabled): array
{
    $site = Site::factory()->create([
        'business_name' => 'Lead Form Test Co',
        'theme' => 'trades-bold',
    ]);
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['home_lead_form_enabled' => $flagEnabled],
    ]);

    $homePage = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $revision = PageRevision::factory()->for($homePage, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Home', 'subtitle' => 'Welcome'],
            ['type' => 'lead_form', 'title' => 'Get in touch'],
        ]],
    ]);
    $homePage->update(['published_revision_id' => $revision->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $homePage->id,
        ],
        'page_revisions' => [['page_id' => $homePage->id, 'revision_id' => $revision->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return [$site, $homePage];
}

test('lead_form template renders core form fields + primary-stripe card', function () {
    $html = view('site.sections.lead_form', [
        'section' => ['type' => 'lead_form'],
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
    ])->render();

    // Core hardcoded fields: name, email, message.
    // phone is no longer a hardcoded core field — it can appear as an extra_field.
    expect($html)->toContain('name="name"');
    expect($html)->toContain('name="email"');
    expect(substr_count($html, 'name="message"'))->toBe(1);
    expect($html)->toContain('placeholder="How can we help?"');
    expect($html)->toContain('var(--brand-primary)');
    expect($html)->toContain('var(--radius-card)');
    expect($html)->toContain('type="submit"');
});

test('lead_form renders five operator fields plus a materialised Message field', function () {
    $fields = [
        ...collect(range(1, 5))->map(fn (int $number): array => [
            'name' => "field_{$number}",
            'label' => "Field {$number}",
            'type' => 'text',
        ])->all(),
        [
            'name' => 'message',
            'label' => 'Project details',
            'type' => 'textarea',
            'required' => true,
            'placeholder' => 'Tell us about the job',
        ],
    ];

    $html = view('site.sections.lead_form', [
        'section' => [
            'type' => 'lead_form',
            'extra_fields' => $fields,
            'message_field_migrated' => true,
        ],
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
    ])->render();

    expect($html)->toContain('name="field_5"')
        ->and(substr_count($html, 'name="message"'))->toBe(1)
        ->and($html)->toContain('Project details')
        ->and($html)->toContain('placeholder="Tell us about the job"');
});

test('lead_form does not fall back to Message after its fields become authoritative', function () {
    $html = view('site.sections.lead_form', [
        'section' => [
            'type' => 'lead_form',
            'extra_fields' => [],
            'message_field_migrated' => true,
        ],
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
    ])->render();

    expect($html)->not->toContain('name="message"');
});

test('lead_form renders custom submit_label when provided', function () {
    $html = view('site.sections.lead_form', [
        'section' => ['type' => 'lead_form', 'submit_label' => 'Book a callback'],
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
    ])->render();

    expect($html)->toContain('Book a callback');
});

test('lead_form emits editor markers when requested', function () {
    $html = view('site.sections.lead_form', [
        'section' => ['type' => 'lead_form', 'title' => 'Contact us'],
        'sectionIndex' => 2,
        'pageId' => 42,
        'emitMarkers' => true,
    ])->render();

    expect($html)->toContain('data-editable="page.42.section.2.title"');
    expect($html)->toContain('data-editable-type="plain"');
    expect($html)->toContain('data-editable-section-type="lead_form"');
});

test('lead_form section is registered in site_sections config with all fields', function () {
    $sections = config('site_sections');
    expect($sections)->toHaveKey('lead_form');
    expect($sections['lead_form']['fields'])->toHaveKey('title');
    expect($sections['lead_form']['fields'])->toHaveKey('intro');
    expect($sections['lead_form']['fields'])->toHaveKey('submit_label');
    expect($sections['lead_form']['fields'])->toHaveKey('benefits');
    expect($sections['lead_form']['fields'])->toHaveKey('extra_fields');
});

test('page render omits lead_form when home_lead_form_enabled is false', function () {
    [$site, $homePage] = setupLeadFormSite(flagEnabled: false);

    $html = app(PageRenderer::class)->render($site, $homePage->id, mode: 'public');

    expect($html)->not->toContain('name="message"');
});

test('page render includes lead_form when home_lead_form_enabled is true', function () {
    [$site, $homePage] = setupLeadFormSite(flagEnabled: true);

    $html = app(PageRenderer::class)->render($site, $homePage->id, mode: 'public');

    expect($html)->toContain('name="message"');
    expect($html)->toContain('name="email"');
});

// ───── Policy-based render tests ─────────────────

/**
 * Builds a site with a published home page (containing a lead_form section)
 * and a service page. Returns [site, homePage, servicePage].
 *
 * @param  string  $policyValue  One of 'off'|'home'|'home_services'|'all'|null (null = omit key for archetype default)
 * @return array{0: \App\Models\Site, 1: \App\Models\GeneratedPage, 2: \App\Models\GeneratedPage}
 */
function setupPolicySite(?string $policyValue): array
{
    $site = Site::factory()->create(['business_name' => 'Policy Test Co']);

    $profileData = ['archetype' => 'local_service'];
    if ($policyValue !== null) {
        $profileData['lead_form_policy'] = $policyValue;
    }
    BusinessProfile::factory()->for($site)->create(['profile_data' => $profileData]);

    $homePage = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $homeRevision = PageRevision::factory()->for($homePage, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Home'],
            ['type' => 'lead_form', 'title' => 'Get a quote'],
        ]],
    ]);
    $homePage->update(['published_revision_id' => $homeRevision->id]);

    $servicePage = GeneratedPage::factory()->for($site)->create(['page_type' => 'roofing-wigan', 'nav_label' => 'Roofing']);
    $serviceRevision = PageRevision::factory()->for($servicePage, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Roofing'],
            ['type' => 'intro', 'body' => 'Expert roofing.'],
            ['type' => 'cta', 'title' => 'Call us'],
        ]],
    ]);
    $servicePage->update(['published_revision_id' => $serviceRevision->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold'],
            'homepage_page_id' => $homePage->id,
        ],
        'page_revisions' => [
            ['page_id' => $homePage->id, 'revision_id' => $homeRevision->id],
            ['page_id' => $servicePage->id, 'revision_id' => $serviceRevision->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return [$site, $homePage, $servicePage];
}

test('policy off: lead_form absent from home and service pages', function () {
    [$site, $homePage, $servicePage] = setupPolicySite('off');

    $homeHtml = app(PageRenderer::class)->render($site, $homePage->id, mode: 'public');
    $serviceHtml = app(PageRenderer::class)->render($site, $servicePage->id, mode: 'public');

    expect($homeHtml)->not->toContain('name="message"');
    expect($serviceHtml)->not->toContain('name="message"');
});

test('policy home: lead_form on home only, not on service pages', function () {
    [$site, $homePage, $servicePage] = setupPolicySite('home');

    $homeHtml = app(PageRenderer::class)->render($site, $homePage->id, mode: 'public');
    $serviceHtml = app(PageRenderer::class)->render($site, $servicePage->id, mode: 'public');

    expect($homeHtml)->toContain('name="message"');
    expect($serviceHtml)->not->toContain('name="message"');
});

test('policy home_services: lead_form on home and service pages', function () {
    [$site, $homePage, $servicePage] = setupPolicySite('home_services');

    $homeHtml = app(PageRenderer::class)->render($site, $homePage->id, mode: 'public');
    $serviceHtml = app(PageRenderer::class)->render($site, $servicePage->id, mode: 'public');

    expect($homeHtml)->toContain('name="message"');
    expect($serviceHtml)->toContain('name="message"');
});

test('policy all: lead_form on home and service pages', function () {
    [$site, $homePage, $servicePage] = setupPolicySite('all');

    $homeHtml = app(PageRenderer::class)->render($site, $homePage->id, mode: 'public');
    $serviceHtml = app(PageRenderer::class)->render($site, $servicePage->id, mode: 'public');

    expect($homeHtml)->toContain('name="message"');
    expect($serviceHtml)->toContain('name="message"');
});

test('legacy boolean true + no new policy field: lead_form on home', function () {
    [$site, $homePage, $servicePage] = setupPolicySite(null);
    // Override profile_data with legacy-boolean-only shape (no lead_form_policy key).
    $site->businessProfile->update(['profile_data' => ['home_lead_form_enabled' => true]]);

    $homeHtml = app(PageRenderer::class)->render($site, $homePage->id, mode: 'public');

    expect($homeHtml)->toContain('name="message"');
});

test('bad persisted lead_form_policy value falls back to Home without throwing', function () {
    [$site, $homePage] = setupPolicySite(null);
    // Simulate a bad value that would have caused ValueError with ::from().
    $site->businessProfile->update(['profile_data' => ['lead_form_policy' => 'invalid_garbage']]);

    // Must not throw — ::tryFrom returns null, fallback is LeadFormPolicy::Home.
    $policy = $site->businessProfile->fresh()->leadFormPolicy();
    expect($policy)->toBe(LeadFormPolicy::Home);

    // Render must also not throw.
    $html = app(PageRenderer::class)->render($site, $homePage->id, mode: 'public');
    expect($html)->toContain('name="message"');
});

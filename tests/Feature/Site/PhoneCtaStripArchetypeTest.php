<?php

use App\Enums\Archetype;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Build a service-page render context for a given archetype. Returns the
 * rendered HTML so assertions can inspect the phone_cta_strip output.
 */
function renderServicePageWithArchetype(Archetype $archetype): string
{
    $site = Site::factory()->create([
        'business_name' => 'Acme',
        'theme' => 'trades-bold',
    ]);

    BusinessProfile::factory()->for($site)->create([
        'profile_data' => [
            'archetype' => $archetype->value,
            'lead_form_policy' => 'all',
            'contact' => ['phones' => ['+44 1234 567890']],
        ],
    ]);

    // Service page (NOT home/about/contact) so injectServiceLeadForm runs.
    $servicePage = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'plumbing-emergency-glasgow',
        'nav_label' => 'Emergency Plumbing',
    ]);
    // Home page carrying the lead_form template that injectServiceLeadForm
    // copies onto the service page.
    $homePage = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $homeRev = PageRevision::factory()->for($homePage, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'lead_form', 'title' => 'Get a quote', 'intro' => 'Tell us about it.', 'submit_label' => 'Send'],
        ]],
    ]);
    $homePage->update(['published_revision_id' => $homeRev->id]);

    // The service page's own content has just an intro + cta — the strip
    // and lead_form are render-time injected by injectServiceLeadForm.
    $serviceRev = PageRevision::factory()->for($servicePage, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'intro', 'title' => 'Service intro'],
            ['type' => 'cta', 'title' => 'Ready?'],
        ]],
    ]);
    $servicePage->update(['published_revision_id' => $serviceRev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $homePage->id,
        ],
        'page_revisions' => [
            ['page_id' => $homePage->id, 'revision_id' => $homeRev->id],
            ['page_id' => $servicePage->id, 'revision_id' => $serviceRev->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return app(PageRenderer::class)->render($site, $servicePage->id, mode: 'public');
}

it('renders 24/7 emergency framing on EmergencyTrade sites', function () {
    $html = renderServicePageWithArchetype(Archetype::EmergencyTrade);
    expect($html)->toContain('24/7 Emergency Call-Out');
    expect($html)->toContain('Rapid response across our coverage area');
});

it('does NOT render emergency framing on LocalService sites (landscaping etc)', function () {
    $html = renderServicePageWithArchetype(Archetype::LocalService);
    expect($html)->not->toContain('24/7 Emergency Call-Out');
    expect($html)->not->toContain('Rapid response across our coverage area');
    // The archetype-specific framing should be visible instead.
    expect($html)->toContain('Call about your project');
});

it('does NOT render emergency framing on RetailVenue sites', function () {
    $html = renderServicePageWithArchetype(Archetype::RetailVenue);
    expect($html)->not->toContain('24/7 Emergency Call-Out');
    expect($html)->toContain('Get in touch');
    expect($html)->toContain('Call ahead or pop in');
});

it('does NOT render emergency framing on ProfessionalService sites', function () {
    $html = renderServicePageWithArchetype(Archetype::ProfessionalService);
    expect($html)->not->toContain('24/7 Emergency Call-Out');
    expect($html)->toContain('Speak to our team');
});

it('blade fallback is neutral when section has no title and no archetype-resolved copy', function () {
    // Simulate a render path that bypasses injection — directly include
    // the partial with an empty section payload. This protects against
    // legacy revisions / direct includes that don't go through
    // injectServiceLeadForm.
    $html = view('site.sections.phone_cta_strip', [
        'section' => ['type' => 'phone_cta_strip'],
        'pageId' => 1, 'sectionIndex' => 0, 'emitMarkers' => false,
        'profile' => ['contact' => ['phones' => ['01234 567890']]],
    ])->render();

    expect($html)->not->toContain('24/7 Emergency Call-Out');
    expect($html)->not->toContain('Rapid response');
    expect($html)->toContain('Get in touch');
});

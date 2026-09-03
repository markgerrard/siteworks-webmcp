<?php

use App\Enums\Archetype;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedServiceInjectionSite(): array
{
    $site = Site::factory()->create([
        'business_name' => 'Acme Plumbing',
        'theme' => 'trades-bold',
    ]);

    BusinessProfile::factory()->for($site)->create([
        'profile_data' => [
            'archetype' => Archetype::EmergencyTrade->value,
            'lead_form_policy' => 'all',
            'contact' => ['phones' => ['+44 1234 567890']],
        ],
    ]);

    $homePage = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
    $homeRev = PageRevision::factory()->for($homePage, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Home Hero'],
            ['type' => 'lead_form', 'title' => 'Get a quote', 'intro' => 'Tell us about it.', 'submit_label' => 'Send'],
            ['type' => 'cta', 'title' => 'Home CTA'],
        ]],
    ]);
    $homePage->update(['published_revision_id' => $homeRev->id]);

    $servicePage = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'plumbing-emergency',
        'nav_label' => 'Emergency Plumbing',
    ]);
    $serviceRev = PageRevision::factory()->for($servicePage, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'intro', 'title' => 'Service intro'],
            ['type' => 'cta', 'title' => 'Ready to book?'],
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

    return [$site, $homePage, $servicePage];
}

test('a service page that receives an injected lead form emits no data-form-editable for it', function () {
    [$site, , $servicePage] = seedServiceInjectionSite();

    $html = app(PageRenderer::class)->render($site, $servicePage->id, mode: 'admin-edit', formPanel: true);

    // Injected lead form must NOT emit data-form-editable
    expect($html)->not->toContain('data-form-kind="lead_form"')
        ->and($html)->not->toContain('data-form-editable');
});

test('a section after the splice point emits its stored index, not its rendered one', function () {
    [$site, , $servicePage] = seedServiceInjectionSite();

    $html = app(PageRenderer::class)->render($site, $servicePage->id, mode: 'admin-edit', formPanel: true);

    // Stored sections on servicePage are:
    // 0: intro
    // 1: cta
    // Injected sections are spliced before cta, so cta is at rendered index 3 (intro, phone_cta_strip, lead_form, cta).
    // CTA must emit its stored index (1), NOT rendered index (3).
    expect($html)->toContain('data-editable="page.'.$servicePage->id.'.section.1.title"')
        ->and($html)->not->toContain('data-editable="page.'.$servicePage->id.'.section.3.title"');

    // Intro section at stored index 0
    expect($html)->toContain('data-editable="page.'.$servicePage->id.'.section.0.title"');
});

test('a page with no injection emits markers matching its stored indices', function () {
    [$site, $homePage] = seedServiceInjectionSite();

    $html = app(PageRenderer::class)->render($site, $homePage->id, mode: 'admin-edit', formPanel: true);

    // Home page has:
    // 0: hero
    // 1: lead_form
    // 2: cta
    expect($html)->toContain('data-editable="page.'.$homePage->id.'.section.0.title"')
        ->and($html)->toContain('data-form-editable="page.'.$homePage->id.'.section.1"')
        ->and($html)->toContain('data-form-kind="lead_form"')
        ->and($html)->toContain('data-editable="page.'.$homePage->id.'.section.1.title"')
        ->and($html)->toContain('data-editable="page.'.$homePage->id.'.section.2.title"');
});

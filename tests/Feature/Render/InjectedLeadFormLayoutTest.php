<?php

use App\Enums\Archetype;
use App\Enums\PageKind;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Site with a published home (hero, the given lead_form, cta) and one service page (intro, cta).
 * injectServiceLeadForm() reads the home lead_form from the CURRENT published version, so this publishes one.
 *
 * @return array{0: Site, 1: GeneratedPage, 2: GeneratedPage} site, home, service
 */
function leadFormInjectionSite(array $homeLeadForm, array $siteAttrs = [], array $profileData = []): array
{
    $site = Site::factory()->create(['business_name' => 'Acme Plumbing', 'theme' => 'trades-bold'] + $siteAttrs);
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => array_replace([
            'archetype' => Archetype::EmergencyTrade->value,
            'lead_form_policy' => 'all',
            'contact' => ['phones' => ['0161 123 4567'], 'emails' => ['hello@acme.test']],
            'geo' => ['service_area' => 'Manchester'],
        ], $profileData),
    ]);
    $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'kind' => PageKind::Core]);
    $homeRev = PageRevision::factory()->for($home, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home Hero'], $homeLeadForm, ['type' => 'cta', 'title' => 'Home CTA']]],
    ]);
    $home->update(['published_revision_id' => $homeRev->id]);
    $service = GeneratedPage::factory()->for($site)->create(['page_type' => 'plumbing-emergency', 'kind' => PageKind::Service, 'nav_label' => 'Emergency Plumbing']);
    $serviceRev = PageRevision::factory()->for($service, 'page')->create([
        'content_data' => ['sections' => [['type' => 'intro', 'title' => 'Service intro'], ['type' => 'cta', 'title' => 'Ready to book?']]],
    ]);
    $service->update(['published_revision_id' => $serviceRev->id]);
    $version = SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => [
            'nav' => ['items' => []], 'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $home->id,
        ],
        'page_revisions' => [
            ['page_id' => $home->id, 'revision_id' => $homeRev->id],
            ['page_id' => $service->id, 'revision_id' => $serviceRev->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return [$site, $home, $service];
}

it('strips variant, __options, __surface and __stored_index from the injected service lead_form', function () {
    [$site, , $service] = leadFormInjectionSite([
        'type' => 'lead_form', 'title' => 'Get in touch', 'extra_fields' => [], 'variant' => null,
        '__options' => ['form_surface' => 'flat-cream'], '__surface' => 'brand',
    ]);
    $renderer = app(PageRenderer::class);
    $method = new \ReflectionMethod($renderer, 'injectServiceLeadForm');
    $method->setAccessible(true);

    $out = $method->invoke($renderer, $site, $service, [['type' => 'intro', 'title' => 'Service intro'], ['type' => 'cta', 'title' => 'Ready to book?']]);

    $injected = collect($out)->firstWhere('type', 'lead_form');
    expect($injected)->not->toBeNull()
        ->and($injected['title'])->toBe('Get a free Emergency Plumbing quote')
        ->and(array_key_exists('variant', $injected))->toBeFalse()
        ->and(array_key_exists('__options', $injected))->toBeFalse()
        ->and(array_key_exists('__surface', $injected))->toBeFalse()
        ->and(array_key_exists('__stored_index', $injected))->toBeFalse();
});

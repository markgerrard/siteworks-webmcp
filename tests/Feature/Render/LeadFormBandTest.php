<?php

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
 * A published home page: hero first, then $sections. $profileData keys override the defaults.
 *
 * @return array{0: Site, 1: GeneratedPage}
 */
function leadFormBandHome(array $sections, array $profileData = [], array $siteAttrs = []): array
{
    $site = Site::factory()->create(['business_name' => 'Acme Plumbing', 'theme' => 'trades-bold'] + $siteAttrs);
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => array_replace(
            ['contact' => ['phones' => ['0161 123 4567']], 'geo' => ['service_area' => 'Manchester']],
            $profileData,
        ),
    ]);
    $page = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'kind' => PageKind::Core]);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => array_merge([['type' => 'hero', 'title' => 'Welcome to Acme']], $sections)],
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    $version = SiteVersion::create([
        'site_id' => $site->id, 'version' => 1,
        'composition' => [
            'nav' => ['items' => [['type' => 'page', 'label' => 'Home', 'page_id' => $page->id]]],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return [$site, $page];
}

it('inline-band puts required fields in row one and the rest below, submitting all fields', function () {
    $fields = [
        ['name' => 'service_type', 'type' => 'select', 'label' => 'Service', 'options' => ['A', 'B'], 'required' => true],
        ['name' => 'job_type', 'type' => 'radio', 'label' => 'Job', 'options' => ['X', 'Y'], 'required' => true],
        ['name' => 'budget', 'type' => 'select', 'label' => 'Budget', 'options' => ['1', '2']],
    ];
    [$site, $page] = leadFormBandHome([['type' => 'lead_form', 'title' => 'T', 'extra_fields' => $fields, 'variant' => 'inline-band']]);
    $html = app(PageRenderer::class)->render($site, $page->id);
    $rowOne = substr($html, strpos($html, 'data-band-row="1"'), strpos($html, 'data-band-row="2"') - strpos($html, 'data-band-row="1"'));
    expect($rowOne)->toContain('name="name"')->toContain('name="email"')->toContain('name="service_type"')->toContain('name="job_type"')
        ->not->toContain('type="submit"')->not->toContain('name="budget"');
    expect($html)->toContain('data-lead-form-variant="inline-band"')->toContain('name="budget"')->toContain('name="message"')->toContain('name="website"')
        ->toContain('w-full md:w-auto')
        ->and(substr_count($html, 'type="submit"'))->toBe(1)
        ->and(strpos($html, 'type="submit"'))->toBeGreaterThan(strpos($html, 'data-band-row="2"'));
});

it('inline-band with no optional fields still renders row two with the message', function () {
    $fields = [['name' => 'service_type', 'type' => 'select', 'label' => 'Service', 'options' => ['A', 'B'], 'required' => true]];
    [$site, $page] = leadFormBandHome([['type' => 'lead_form', 'title' => 'T', 'extra_fields' => $fields, 'variant' => 'inline-band']]);
    $html = app(PageRenderer::class)->render($site, $page->id);
    expect($html)->toContain('data-band-row="2"')->toContain('name="message"')
        ->and(substr_count($html, 'type="submit"'))->toBe(1)
        ->and(strpos($html, 'type="submit"'))->toBeGreaterThan(strpos($html, 'data-band-row="2"'));
});

it('inline-band card-on-dark on a light band uses flat-cream chrome', function () {
    [$site, $page] = leadFormBandHome(
        [[
            'type' => 'lead_form',
            'title' => 'T',
            'extra_fields' => [],
            'variant' => 'inline-band',
            '__options' => ['form_surface' => 'card-on-dark', 'form_input_style' => 'boxed'],
        ]],
        ['archetype' => \App\Enums\Archetype::RetailVenue->value],
    );
    $html = app(PageRenderer::class)->render($site, $page->id);
    $formStart = strpos($html, '<form');
    expect($formStart)->not->toBeFalse();
    $form = substr($html, $formStart, (strpos($html, '</form>', $formStart) ?: strlen($html)) - $formStart);

    expect($html)->toContain('data-lead-form-variant="inline-band"')
        ->and($html)->toContain('color-mix(in oklab, var(--color-band, #0f172a) 6%, #fbf8f2)')
        ->and($form)->not->toContain('border-white/70')
        ->and($form)->toContain('text-gray-700');
});

it('inline-band with no optional fields and a migrated message does not open an empty row two', function () {
    $fields = [['name' => 'service_type', 'type' => 'select', 'label' => 'Service', 'options' => ['A', 'B'], 'required' => true]];
    [$site, $page] = leadFormBandHome([['type' => 'lead_form', 'title' => 'T', 'extra_fields' => $fields, 'variant' => 'inline-band', 'message_field_migrated' => true]]);
    $html = app(PageRenderer::class)->render($site, $page->id);
    expect($html)->toContain('data-band-row="1"')
        ->and($html)->not->toContain('data-band-row="2"')
        ->and($html)->not->toContain('name="message"')
        ->and(substr_count($html, 'type="submit"'))->toBe(1)
        ->and(strpos($html, 'type="submit"'))->toBeGreaterThan(strpos($html, 'data-band-row="1"'));
});

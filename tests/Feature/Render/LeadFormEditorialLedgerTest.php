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
function leadFormEditorialHome(array $sections, array $profileData = [], array $siteAttrs = []): array
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

it('renders the light editorial form with a stacked ledger, underline inputs, no card and an auto submit', function () {
    [$site, $page] = leadFormEditorialHome([['type' => 'lead_form', 'title' => 'Tell us about your project', 'intro' => 'Whether it is a single room or a full renovation.', 'extra_fields' => [['name' => 'postcode', 'type' => 'text', 'label' => 'Project postcode']], 'variant' => 'editorial-ledger']], [
        'contact' => ['phones' => ['07700 900123'], 'mobile' => '07700 900456', 'emails' => ['info@hplusb.example'], 'address' => '30 Ledger Lane, London, SW9 9EA'],
        'geo' => ['service_area' => 'London and the South East'],
    ]);
    $html = app(PageRenderer::class)->render($site, $page->id);
    expect($html)->toContain('data-lead-form-variant="editorial-ledger"')
        ->toContain('data-ledger-row="phone"')->toContain('07700 900123')->toContain('07700 900456')
        ->toContain('data-ledger-row="email"')->toContain('mailto:info@hplusb.example')
        ->toContain('data-ledger-row="address"')->toContain('30 Ledger Lane')
        ->toContain('data-ledger-row="area"')->toContain('London and the South East')
        ->toContain('border-b border-gray-500')          // light underline inputs
        ->not->toContain('bg-white p-7 md:p-9')          // no card
        ->toContain('uppercase tracking-[0.12em]')       // auto submit
        ->not->toContain('aria-hidden="true">→')          // no arrow
        ->and(substr_count($html, 'type="submit"'))->toBe(1);
});

it('renders no ledger block when the profile has no contact data', function () {
    [$site, $page] = leadFormEditorialHome([['type' => 'lead_form', 'title' => 'T', 'extra_fields' => [], 'variant' => 'editorial-ledger']], ['contact' => [], 'geo' => []]);
    $html = app(PageRenderer::class)->render($site, $page->id);
    expect($html)->toContain('data-lead-form-variant="editorial-ledger"')->not->toContain('data-ledger-row=');
});

it('emits the classic marker set in admin-edit (hidden benefit markers, no trust block)', function () {
    [$site, $page] = leadFormEditorialHome([['type' => 'lead_form', 'title' => 'T', 'intro' => 'I', 'eyebrow' => 'Contact', 'benefits' => ['a'], 'extra_fields' => [], 'variant' => 'editorial-ledger']]);
    $html = app(PageRenderer::class)->render($site, $page->id, 'admin-edit', formPanel: true);
    foreach (['eyebrow', 'title', 'intro', 'submit_label', 'benefits.0'] as $f) {
        expect($html)->toContain('data-editable-field="'.$f.'"');
    }
    expect($html)->not->toContain('data-trust-style=')->and(substr_count($html, 'data-form-editable='))->toBe(1);
});

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
function leadFormLedgerHome(array $sections, array $profileData = [], array $siteAttrs = []): array
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

it('renders ledger rows only for data that exists, including primary_area', function () {
    [$site, $page] = leadFormLedgerHome([['type' => 'lead_form', 'title' => 'T', 'extra_fields' => [], 'benefits' => ['A'], 'variant' => 'phone-ledger']], [
        'contact' => ['phones' => ['0161 691 9529'], 'mobile' => '07931 990683', 'emails' => ['x@y.z']],
        'geo' => ['primary_area' => 'North West England'],
    ]);
    $html = app(PageRenderer::class)->render($site, $page->id);
    expect($html)->toContain('href="tel:01616919529"')->toContain('07931 990683')->toContain('x@y.z')->toContain('North West England')
        ->not->toContain('data-ledger-row="hours"');
});

it('renders hours when present and omits empty rows', function () {
    [$site, $page] = leadFormLedgerHome([['type' => 'lead_form', 'title' => 'T', 'extra_fields' => [], 'variant' => 'phone-ledger']], [
        'contact' => ['phones' => ['0161 691 9529']], 'geo' => [], 'opening_hours' => ['Mon–Fri' => '8–5'],
    ]);
    $html = app(PageRenderer::class)->render($site, $page->id);
    expect($html)->toContain('data-lead-form-variant="phone-ledger"')->toContain('data-ledger-row="hours"')->toContain('Mon–Fri')
        ->not->toContain('data-ledger-row="email"')->not->toContain('data-ledger-row="area"');
});

it('omits the ledger entirely when the profile has no contact data, keeping the trust list', function () {
    [$site, $page] = leadFormLedgerHome([['type' => 'lead_form', 'title' => 'T', 'extra_fields' => [], 'benefits' => ['A'], 'variant' => 'phone-ledger']], ['contact' => [], 'geo' => []]);
    $html = app(PageRenderer::class)->render($site, $page->id);
    expect($html)->not->toContain('Prefer to talk?')->not->toContain('data-ledger-row=')->toContain('data-trust-style="tick-list"');
});

it('collapses to a full-width form column when the profile is empty and there are no benefits', function () {
    [$site, $page] = leadFormLedgerHome([['type' => 'lead_form', 'title' => 'T', 'extra_fields' => [], 'variant' => 'phone-ledger']], ['contact' => [], 'geo' => []]);
    $html = app(PageRenderer::class)->render($site, $page->id);
    expect($html)->not->toContain('lg:col-span-6 lg:pl-10')->toContain('lg:col-span-12');
});

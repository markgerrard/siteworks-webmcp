<?php

use App\Enums\PageKind;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\LayoutPreset;
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
function leadFormImageHome(array $sections, array $profileData = [], array $siteAttrs = []): array
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

/** Stamp image-backed onto the home form through a site-scoped recipe (the production path). */
function leadFormImageRecipe(Site $site): void
{
    $site->update(['home_layout' => 'forms-qa']);
    LayoutPreset::factory()->for($site)->active()->create([
        'page_kind' => 'home', 'key' => 'forms-qa',
        'recipe' => [
            'label' => 'Image QA', 'description' => 'image-backed', 'schema_version' => 1,
            'variants' => ['lead_form' => 'image-backed'],
            'options' => ['form_input_style' => 'boxed', 'form_trust_style' => 'tick-list'],
            'eyebrow_policy' => 'all', 'eyebrow_sections' => [], 'insert_sections' => [],
        ],
    ]);
}

it('uses the band image and prefers it over the hero image', function () {
    [$site, $page] = leadFormImageHome([['type' => 'lead_form', 'title' => 'T', 'extra_fields' => [], 'benefits' => ['A']]]);
    leadFormImageRecipe($site);
    HeroVersion::factory()->for($site)->active()->create(['page_type' => 'home', 'slot' => 'hero', 'url' => 'https://cdn.example/hero-home.jpg']);
    HeroVersion::factory()->for($site)->active()->create(['page_type' => 'home', 'slot' => 'band', 'url' => 'https://cdn.example/band-home.jpg']);
    $html = app(PageRenderer::class)->render($site->fresh(), $page->id);
    expect($html)->toContain('data-lead-form-variant="image-backed"');
    // The home hero section also emits background-image: url('…hero-home.jpg');
    // prefer/fallback must be judged on the form fragment, not the whole page.
    $form = substr($html, (int) strpos($html, 'data-lead-form-variant="image-backed"'));
    expect($form)->toContain("background-image: url('https://cdn.example/band-home.jpg')")
        ->not->toContain("background-image: url('https://cdn.example/hero-home.jpg')")
        ->toContain('bg-white p-7 md:p-9 border-t-4')   // the solid card over the scrim
        ->toContain('from-black/75');
});

it('falls back to the hero image when there is no band image', function () {
    [$site, $page] = leadFormImageHome([['type' => 'lead_form', 'title' => 'T', 'extra_fields' => []]]);
    leadFormImageRecipe($site);
    HeroVersion::factory()->for($site)->active()->create(['page_type' => 'home', 'slot' => 'hero', 'url' => 'https://cdn.example/hero-home.jpg']);
    $html = app(PageRenderer::class)->render($site->fresh(), $page->id);
    expect($html)->toContain('data-lead-form-variant="image-backed"');
    $form = substr($html, (int) strpos($html, 'data-lead-form-variant="image-backed"'));
    expect($form)->toContain("background-image: url('https://cdn.example/hero-home.jpg')");
});

it('keeps the page hero available to an image-backed form when the hero section is a placeholder', function () {
    [$site, $page] = leadFormImageHome([['type' => 'lead_form', 'title' => 'T', 'extra_fields' => []]]);
    leadFormImageRecipe($site);
    $revision = $page->publishedRevision;
    $content = $revision->content_data;
    $content['sections'][0]['placeholder'] = true;
    $revision->update(['content_data' => $content]);
    HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/placeholder-page-hero.jpg',
    ]);

    $html = app(PageRenderer::class)->render($site->fresh(), $page->id);
    $form = substr($html, (int) strpos($html, 'data-lead-form-variant="image-backed"'));

    expect($html)->toContain('data-lead-form-variant="image-backed"')
        ->and($form)->toContain("background-image: url('https://cdn.example/placeholder-page-hero.jpg')");
});

it('honours the watermark copy when the profile has watermarks on', function () {
    [$site, $page] = leadFormImageHome([['type' => 'lead_form', 'title' => 'T', 'extra_fields' => []]], ['watermark_enabled' => true]);
    leadFormImageRecipe($site);
    HeroVersion::factory()->for($site)->active()->create(['page_type' => 'home', 'slot' => 'band', 'url' => 'https://cdn.example/band.jpg', 'watermark_url' => 'https://cdn.example/band-wm.jpg']);
    expect(app(PageRenderer::class)->render($site->fresh(), $page->id))->toContain("url('https://cdn.example/band-wm.jpg')");
});

it('renders the identity composition when there is no image at all, without recursing', function () {
    [$site, $page] = leadFormImageHome([['type' => 'lead_form', 'title' => 'T', 'extra_fields' => [], 'benefits' => ['A']]], [], ['nav_cta_target' => 'form']);
    leadFormImageRecipe($site);
    $html = app(PageRenderer::class)->render($site->fresh(), $page->id);
    expect($html)->not->toContain('data-lead-form-variant=')->toContain('space-y-3 max-w-md')->toContain('bg-white p-7 md:p-9 border-t-4')
        ->and(substr_count($html, 'id="enquire"'))->toBe(1)
        ->and(substr_count($html, 'name="website"'))->toBe(1)
        ->and(substr_count($html, 'function initDatePickers'))->toBe(1)
        ->and(substr_count($html, 'bg-white p-7 md:p-9 border-t-4'))->toBe(1);
});

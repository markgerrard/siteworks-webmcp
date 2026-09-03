<?php

use App\Enums\PageKind;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\LogoConcept;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteDraft;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\Editor\DraftAssetSelections;
use App\Services\Site\HeroResolution;
use App\Services\Site\PageLayoutRegistry;
use App\Services\Site\PageRenderer;
use Illuminate\Support\Facades\Storage;

/**
 * @param  list<array<string, mixed>>  $sections
 * @return array{Site, GeneratedPage, SiteVersion}
 */
function draftAssetRenderSite(
    array $sections,
    string $pageType = 'home',
    ?PageKind $kind = null,
    string $heroSource = 'shared',
): array {
    $site = Site::factory()->create([
        'business_name' => 'Draft Asset Test',
        'theme' => 'trades-bold',
    ]);
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => $pageType,
        'kind' => $kind,
        'hero_source' => $heroSource,
    ]);
    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => $sections],
    ]);
    $page->update([
        'published_revision_id' => $revision->id,
        'draft_revision_id' => $revision->id,
    ]);

    $composition = [
        'nav' => ['items' => []],
        'footer' => ['columns' => [], 'show_credit' => true],
        'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
        'homepage_page_id' => $page->id,
    ];
    $version = SiteVersion::query()->create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => $composition,
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $revision->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::query()->create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);
    SiteDraft::query()->create([
        'site_id' => $site->id,
        'composition' => $composition,
        'updated_at' => now(),
    ]);

    return [$site->fresh(), $page->fresh(), $version];
}

it('shows draft hero selections only when explicitly requested', function () {
    [$site, $page, $version] = draftAssetRenderSite([
        ['type' => 'hero', 'title' => 'Draft asset hero'],
    ]);
    $active = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/active-home-hero.jpg',
    ]);
    $draft = HeroVersion::factory()->for($site)->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/draft-home-hero.jpg',
    ]);

    app(DraftAssetSelections::class)->setHero($site, 'home', 'hero', $draft, null);

    $renderer = app(PageRenderer::class);
    $publicHtml = $renderer->render($site, $page->id, mode: 'public');
    $draftHtml = $renderer->render($site, $page->id, mode: 'admin-edit', useDraftAssets: true);
    $defaultAdminHtml = $renderer->render($site, $page->id, mode: 'admin-edit');
    $versionHtml = $renderer->renderVersion($site, $page->id, $version);

    expect($publicHtml)->toContain($active->url)->not->toContain($draft->url)
        ->and($draftHtml)->toContain($draft->url)->not->toContain($active->url)
        ->and($defaultAdminHtml)->toContain($active->url)->not->toContain($draft->url)
        ->and($versionHtml)->toContain($active->url)->not->toContain($draft->url)
        ->and($active->fresh()->is_active)->toBeTrue()
        ->and($draft->fresh()->is_active)->toBeFalse();
});

it('shows draft logo selections only when explicitly requested', function () {
    Storage::fake('s3');
    [$site, $page, $version] = draftAssetRenderSite([
        ['type' => 'hero', 'title' => 'Draft asset logo'],
    ]);
    $selected = LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/selected-logo.png',
    ]);
    $draft = LogoConcept::factory()->for($site)->create([
        'path' => 'logos/draft-logo.png',
    ]);

    app(DraftAssetSelections::class)->setLogo($site, $draft, null);

    $renderer = app(PageRenderer::class);
    $publicHtml = $renderer->render($site, $page->id, mode: 'public');
    $draftHtml = $renderer->render($site, $page->id, mode: 'admin-edit', useDraftAssets: true);
    $defaultAdminHtml = $renderer->render($site, $page->id, mode: 'admin-edit');
    $versionHtml = $renderer->renderVersion($site, $page->id, $version);

    expect($publicHtml)->toContain('selected-logo.png')->not->toContain('draft-logo.png')
        ->and($draftHtml)->toContain('draft-logo.png')->not->toContain('selected-logo.png')
        ->and($defaultAdminHtml)->toContain('selected-logo.png')->not->toContain('draft-logo.png')
        ->and($versionHtml)->toContain('selected-logo.png')->not->toContain('draft-logo.png')
        ->and($selected->fresh()->is_selected)->toBeTrue()
        ->and($draft->fresh()->is_selected)->toBeFalse();
});

it('overlays band_2 and the shared service hero selection', function () {
    [$site, $page] = draftAssetRenderSite([
        ['type' => 'hero', 'title' => 'Shared service hero'],
        [
            'type' => 'intro',
            'title' => 'Editorial intro',
            'variant' => 'editorial',
            '__options' => ['band_image_count' => 2],
        ],
    ], 'emergency-plumbing', PageKind::Service);
    $activeShared = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => '__shared_service_hero',
        'slot' => 'hero',
        'url' => 'https://cdn.example/active-shared-hero.jpg',
    ]);
    $draftShared = HeroVersion::factory()->for($site)->create([
        'page_type' => '__shared_service_hero',
        'slot' => 'hero',
        'url' => 'https://cdn.example/draft-shared-hero.jpg',
    ]);
    HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'emergency-plumbing',
        'slot' => 'band',
        'url' => 'https://cdn.example/active-band.jpg',
    ]);
    $draftBand2 = HeroVersion::factory()->for($site)->create([
        'page_type' => 'emergency-plumbing',
        'slot' => 'band_2',
        'url' => 'https://cdn.example/draft-band-2.jpg',
    ]);

    $selections = app(DraftAssetSelections::class);
    $selections->setHero($site, '__shared_service_hero', 'hero', $draftShared, null);
    $selections->setHero($site, 'emergency-plumbing', 'band_2', $draftBand2, null);

    $renderer = app(PageRenderer::class);
    $defaultHtml = $renderer->render($site, $page->id, mode: 'admin-edit');
    $draftHtml = $renderer->render($site, $page->id, mode: 'admin-edit', useDraftAssets: true);

    expect($defaultHtml)->toContain($activeShared->url)
        ->not->toContain($draftShared->url)
        ->not->toContain($draftBand2->url)
        ->and($draftHtml)->toContain($draftShared->url)
        ->toContain($draftBand2->url)
        ->not->toContain($activeShared->url);
});

it('resolves a drafted shared service hero when no per-page draft exists', function () {
    [$site, $page] = draftAssetRenderSite([
        ['type' => 'hero', 'title' => 'Shared service hero'],
    ], 'emergency-plumbing', PageKind::Service);
    $activeShared = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => '__shared_service_hero',
        'slot' => 'hero',
        'url' => 'https://cdn.example/active-shared-hero.jpg',
    ]);
    $draftShared = HeroVersion::factory()->for($site)->create([
        'page_type' => '__shared_service_hero',
        'slot' => 'hero',
        'url' => 'https://cdn.example/draft-shared-hero.jpg',
    ]);
    app(DraftAssetSelections::class)->setHero($site, '__shared_service_hero', 'hero', $draftShared, null);

    $state = app(HeroResolution::class)->for($site, $page, true);

    expect($state->image_version_id)->toBe($draftShared->id)
        ->and($state->image_url)->toBe($draftShared->url)
        ->and($state->image_url)->not->toBe($activeShared->url)
        ->and($state->reason)->toBe('draft_selection');
});

it('shows a drafted per-page hero on a shared-source service page (draft modes only)', function () {
    [$site, $page] = draftAssetRenderSite([
        ['type' => 'hero', 'title' => 'Shared service hero'],
    ], 'emergency-plumbing', PageKind::Service, heroSource: 'shared');
    $activeShared = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => '__shared_service_hero',
        'slot' => 'hero',
        'url' => 'https://cdn.example/active-shared-hero.jpg',
    ]);
    $draftPageHero = HeroVersion::factory()->for($site)->create([
        'page_type' => 'emergency-plumbing',
        'slot' => 'hero',
        'url' => 'https://cdn.example/draft-page-hero.jpg',
    ]);
    app(DraftAssetSelections::class)->setHero($site, 'emergency-plumbing', 'hero', $draftPageHero, null);

    $renderer = app(PageRenderer::class);
    $publicHtml = $renderer->render($site, $page->id, mode: 'public');
    $draftHtml = $renderer->render($site, $page->id, mode: 'admin-edit', useDraftAssets: true);

    expect($publicHtml)->toContain($activeShared->url)->not->toContain($draftPageHero->url)
        ->and($draftHtml)->toContain($draftPageHero->url)->not->toContain($activeShared->url);
});

it('manages selections without changing live asset flags and rejects foreign assets', function () {
    $site = Site::factory()->create();
    $foreignSite = Site::factory()->create();
    $hero = HeroVersion::factory()->for($site)->active()->create();
    $logo = LogoConcept::factory()->for($site)->selected()->create();
    $foreignHero = HeroVersion::factory()->for($foreignSite)->create();
    $foreignLogo = LogoConcept::factory()->for($foreignSite)->create();
    $selections = app(DraftAssetSelections::class);

    $selections->setHero($site, 'home', 'hero', $hero, null);
    $selections->setLogo($site, $logo, null);

    expect($selections->heroFor($site, 'home', 'hero')?->is($hero))->toBeTrue()
        ->and($selections->logoFor($site)?->is($logo))->toBeTrue()
        ->and($selections->all($site))->toHaveCount(2)
        ->and($selections->any($site))->toBeTrue()
        ->and($hero->fresh()->is_active)->toBeTrue()
        ->and($logo->fresh()->is_selected)->toBeTrue();

    $selections->clearMatching($site, 'hero', 'home', 'hero');
    expect($selections->heroFor($site, 'home', 'hero'))->toBeNull()
        ->and($selections->any($site))->toBeTrue();

    $selections->clear($site);
    expect($selections->any($site))->toBeFalse();

    expect(fn () => $selections->setHero($site, 'home', 'hero', $foreignHero, null))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $selections->setLogo($site, $foreignLogo, null))
        ->toThrow(InvalidArgumentException::class);
});

it('exposes layout recipe variants and the service lead form injection guard', function () {
    $registry = app(PageLayoutRegistry::class);
    expect($registry->variantOptionsFor('home', 'services'))->toBe([
        'featured-ledger',
        'photo-cards',
        'marker-columns',
        'split-bands',
    ]);

    $site = Site::factory()->create();
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['lead_form_policy' => 'home_services'],
    ]);
    $servicePage = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'emergency-plumbing',
        'kind' => PageKind::Service,
    ]);
    $homePage = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
    ]);

    $renderer = app(PageRenderer::class);
    expect($renderer->wouldInjectServiceLeadForm($site, $servicePage))->toBeTrue()
        ->and($renderer->wouldInjectServiceLeadForm($site, $homePage))->toBeFalse();
});

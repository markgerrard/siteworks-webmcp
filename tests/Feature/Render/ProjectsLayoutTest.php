<?php

use App\Enums\PageKind;
use App\Enums\ProjectItemSource;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

/**
 * Published projects page. Recipe key follows sites.services_layout.
 *
 * @param  array<string, mixed>  $siteAttrs
 * @return array{0: Site, 1: GeneratedPage}
 */
function makeProjectsLayoutPage(array $siteAttrs = [], ?ProjectItemSource $itemSource = null): array
{
    $site = Site::factory()->create([
        'business_name' => 'Acme Roofing',
        'theme' => 'trades-bold',
    ] + $siteAttrs);

    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'kind' => PageKind::Core,
    ]);

    $itemIds = [];
    if ($itemSource !== null) {
        $item = ProjectItem::factory()->gallery()->published()->for($site)->create([
            'source' => $itemSource,
            'title' => 'Kitchen remodel',
            'category' => 'Kitchens',
            'description' => 'd',
        ]);
        $itemIds = [$item->id];
    }

    $revision = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'projects_hero', 'title' => 'Projects'],
            ['type' => 'project_gallery', 'title' => 'Recent Work', 'item_ids' => $itemIds],
        ]],
    ]);
    $page->update(['published_revision_id' => $revision->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [
            ['page_id' => $page->id, 'revision_id' => $revision->id],
        ],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create([
        'site_id' => $site->id,
        'version_id' => $version->id,
        'updated_at' => now(),
    ]);

    return [$site, $page];
}

function renderProjectsLayout(Site $site, GeneratedPage $page): string
{
    return app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');
}

function projectsGalleryBareH2(): string
{
    return <<<'HTML'
            <h2 class="text-3xl md:text-4xl font-extrabold tracking-tight mb-12 md:mb-16"
                style="color: var(--color-text);
                       font-family: var(--font-display);
                       letter-spacing: var(--heading-letter-spacing);"
                >
                Recent Work
            </h2>
HTML;
}

it('a precision services_layout projects page renders the ruled gallery eyebrow', function () {
    [$site, $page] = makeProjectsLayoutPage(['services_layout' => 'precision']);

    $html = renderProjectsLayout($site, $page);

    expect($html)->toContain('border-top: 2px solid var(--brand-accent)')
        ->toContain('text-xs font-bold tracking-[0.18em] uppercase')
        ->toContain('Our Work')
        ->toContain('Recent Work');
});

// e1f13d12 restored the plain eyebrow; 427fcd5a ratified it for the no-recipe path via regenerated Chrome snapshots.
it('a classic services_layout projects page keeps the byte-identical bare h2 under the plain eyebrow', function () {
    [$site, $page] = makeProjectsLayoutPage(['services_layout' => 'classic']);

    $html = renderProjectsLayout($site, $page);

    expect($html)->toContain(projectsGalleryBareH2())
        ->not->toContain('border-top: 2px solid var(--brand-accent)')
        ->toContain('<div class="mb-3">')
        ->toContain('text-xs font-bold tracking-[0.18em] uppercase');
});

it('a ruled gallery with AI-generated items uses the example eyebrow, not Our Work', function () {
    config()->set('site.honest_project_framing', false);
    [$site, $page] = makeProjectsLayoutPage(
        ['services_layout' => 'precision', 'honest_project_framing' => true],
        ProjectItemSource::AiGenerated,
    );

    $html = renderProjectsLayout($site, $page);

    expect($html)->toContain('border-top: 2px solid var(--brand-accent)')
        ->toContain('Example Projects')
        ->toMatch('/<span class="text-xs font-bold tracking-\[0\.18em\] uppercase"[^>]*>\s*Examples\s*<\/span>/')
        ->not->toMatch('/<span class="text-xs font-bold tracking-\[0\.18em\] uppercase"[^>]*>\s*Our Work\s*<\/span>/');
});

it('a ruled gallery with sourced items keeps the Our Work eyebrow', function () {
    config()->set('site.honest_project_framing', false);
    [$site, $page] = makeProjectsLayoutPage(
        ['services_layout' => 'precision', 'honest_project_framing' => true],
        ProjectItemSource::AgentUpload,
    );

    $html = renderProjectsLayout($site, $page);

    expect($html)->toContain('border-top: 2px solid var(--brand-accent)')
        ->toContain('Recent Work')
        ->toMatch('/<span class="text-xs font-bold tracking-\[0\.18em\] uppercase"[^>]*>\s*Our Work\s*<\/span>/');
});

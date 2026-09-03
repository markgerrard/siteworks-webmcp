<?php

namespace Tests\Support;

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;

/**
 * Builds a fully published classic site (home/service/about pages with
 * revisions and a current site version) for full-pipeline render tests.
 */
trait MakesClassicRenderSite
{
    protected function makeClassicSite(array $palette): array
    {
        $brief = [
            'mood' => 'refined-minimal',
            'display_font' => 'inter',
            'body_font' => 'inter',
            'heading_scale' => 'balanced',
            'spacing_density' => 'balanced',
            'corner_style' => 'soft',
            'palette' => [
                'primary' => $palette['primary_color'],
                'accent' => $palette['accent_color'],
                'tertiary' => $palette['tertiary_color'],
                'surface' => $palette['surface_color'],
                'surface_alt' => $palette['surface_alt_color'],
                'border' => $palette['border_color'],
                'text' => $palette['text_color'],
                'text_muted' => $palette['text_muted_color'],
            ],
        ];

        $site = Site::factory()->create([
            'business_name' => 'Acme',
            'theme' => 'trades-bold',
            'home_layout' => 'classic',
            'services_layout' => 'classic',
            'about_layout' => 'classic',
            'design_brief' => $brief,
        ]);

        $home = $this->makePage($site, 'home', [
            ['type' => 'hero', 'title' => 'Welcome'],
            ['type' => 'services', 'title' => 'What We Do', 'items' => [
                ['title' => 'One', 'body' => 'A'],
            ]],
            ['type' => 'trust', 'title' => 'Why us', 'items' => [
                ['title' => 'Quality', 'body' => 'Done well'],
            ]],
        ]);
        $service = $this->makePage($site, 'extensions', [
            ['type' => 'intro', 'title' => 'Extensions', 'body' => 'Prose.'],
            ['type' => 'features', 'title' => "What's Included", 'items' => [
                ['icon' => 'hammer', 'title' => 'Item 1', 'body' => 'Body 1.'],
            ]],
        ]);
        $about = $this->makePage($site, 'about', [
            ['type' => 'story', 'title' => 'Our Story', 'body' => 'Once upon a time.'],
            ['type' => 'values', 'title' => 'Values', 'items' => [
                ['title' => 'Care', 'body' => 'We care.'],
            ]],
        ]);

        $version = SiteVersion::create([
            'site_id' => $site->id,
            'version' => 1,
            'composition' => [
                'nav' => ['items' => []],
                'footer' => ['columns' => [], 'show_credit' => true],
                'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
                'homepage_page_id' => $home->id,
            ],
            'page_revisions' => [
                ['page_id' => $home->id, 'revision_id' => $home->published_revision_id],
                ['page_id' => $service->id, 'revision_id' => $service->published_revision_id],
                ['page_id' => $about->id, 'revision_id' => $about->published_revision_id],
            ],
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

        return [$site, $home, $service, $about];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    protected function makePage(Site $site, string $pageType, array $sections): GeneratedPage
    {
        $page = GeneratedPage::factory()->for($site)->create(['page_type' => $pageType]);
        $rev = PageRevision::factory()->for($page, 'page')->create([
            'content_data' => ['sections' => $sections],
        ]);
        $page->update(['published_revision_id' => $rev->id]);

        return $page->fresh();
    }

}

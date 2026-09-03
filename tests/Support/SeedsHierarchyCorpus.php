<?php

namespace Tests\Support;

use App\Enums\PreviewLayout;
use App\Enums\ProjectItemSource;
use App\Models\GeneratedPage;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\DesignBrief;

trait SeedsHierarchyCorpus
{
    /**
     * @return array{site: Site, pages: list<GeneratedPage>, host: string, palette: array<string, string>}
     */
    protected function seedHierarchyCorpusSite(int $corpusId): array
    {
        $definitions = $this->hierarchyCorpusDefinitions();
        $definition = $definitions[$corpusId] ?? null;
        $this->assertIsArray($definition, "Unknown hierarchy corpus site [{$corpusId}].");

        $palette = $this->hierarchyCorpusPalettes()[$corpusId];
        $host = "corpus-{$corpusId}.example";

        $site = Site::factory()->create([
            'id' => $corpusId,
            'business_name' => $definition['business_name'],
            'business_type' => $definition['business_type'],
            'location' => $definition['location'],
            'custom_domain' => $host,
            'custom_domain_status' => 'active',
            'preview_layout' => PreviewLayout::MultiPage,
            'theme' => 'trades-bold',
            'home_layout' => $definition['layout'],
            'services_layout' => $definition['layout'],
            'about_layout' => in_array($definition['layout'], ['classic', 'editorial', 'showcase', 'precision'], true)
                ? $definition['layout']
                : 'classic',
            'design_brief' => $this->hierarchyDesignBrief($palette, $definition['display_font']),
        ]);

        $home = $this->createHierarchyCorpusPage($site, $corpusId, 1, 'home', [
            [
                'type' => 'hero',
                'title' => $definition['hero'],
                'subtitle' => "Trusted {$definition['business_type']} services in {$definition['location']}.",
                'cta_label' => 'Request a quote',
            ],
            [
                'type' => 'services',
                'title' => 'What We Do',
                'eyebrow' => 'Our Services',
                'items' => [
                    ['icon' => 'hammer', 'title' => 'Planning', 'body' => 'Clear advice from the first visit.'],
                    ['icon' => 'wrench', 'title' => 'Delivery', 'body' => 'Careful work built to last.'],
                    ['icon' => 'check', 'title' => 'Aftercare', 'body' => 'Support after completion.'],
                ],
            ],
            [
                'type' => 'trust',
                'title' => 'Why Choose Us',
                'items' => [
                    ['title' => 'Local', 'body' => "Based in {$definition['location']}."],
                    ['title' => 'Reliable', 'body' => 'Appointments kept and progress explained.'],
                    ['title' => 'Experienced', 'body' => 'Practical expertise on every project.'],
                ],
            ],
        ]);
        $service = $this->createHierarchyCorpusPage($site, $corpusId, 2, $definition['service_slug'], [
            [
                'type' => 'intro',
                'title' => $definition['service_title'],
                'eyebrow' => 'Specialist Service',
                'body' => "A complete {$definition['service_title']} service for {$definition['location']}.",
            ],
            [
                'type' => 'features',
                'title' => "What's Included",
                'items' => [
                    ['icon' => 'clipboard', 'title' => 'Survey', 'body' => 'A careful assessment.'],
                    ['icon' => 'pencil', 'title' => 'Plan', 'body' => 'A clear written proposal.'],
                    ['icon' => 'shield', 'title' => 'Complete', 'body' => 'A tidy, checked finish.'],
                ],
            ],
        ]);
        $about = $this->createHierarchyCorpusPage($site, $corpusId, 3, 'about', [
            [
                'type' => 'story',
                'title' => 'Our Story',
                'eyebrow' => 'About Us',
                'body' => "{$definition['business_name']} serves homes and businesses across {$definition['location']}.",
            ],
            [
                'type' => 'values',
                'title' => 'Our Values',
                'items' => [
                    ['title' => 'Craft', 'body' => 'Details matter.'],
                    ['title' => 'Clarity', 'body' => 'Straight answers throughout.'],
                    ['title' => 'Care', 'body' => 'Respect for every property.'],
                ],
            ],
        ]);
        $contact = $this->createHierarchyCorpusPage($site, $corpusId, 4, 'contact', [
            [
                'type' => 'details',
                'title' => 'Talk to Our Team',
                'items' => [
                    ['label' => 'Phone', 'value' => '01942 555 010'],
                    ['label' => 'Email', 'value' => "hello@corpus-{$corpusId}.example"],
                    ['label' => 'Area', 'value' => $definition['location']],
                ],
            ],
            [
                'type' => 'contact_form',
                'eyebrow' => 'Start a Conversation',
                'title' => 'Tell Us About Your Project',
                'intro' => 'Share a few details and our team will be in touch.',
                'submit_label' => 'Send enquiry',
            ],
        ]);
        $projects = $this->createHierarchyCorpusPage($site, $corpusId, 5, 'projects', [
            [
                'type' => 'projects_hero',
                'title' => 'Selected Projects',
                'subtitle' => "Recent work completed around {$definition['location']}.",
            ],
        ]);
        $projectItems = collect([
            ['title' => 'Courtyard Renewal', 'category' => 'Residential'],
            ['title' => 'Workshop Upgrade', 'category' => 'Commercial'],
            ['title' => 'Period Property Care', 'category' => 'Heritage'],
        ])->map(fn (array $item, int $index): ProjectItem => ProjectItem::factory()
            ->gallery()
            ->published()
            ->for($site)
            ->create([
                'page_id' => $projects->id,
                'source' => ProjectItemSource::AgentAdded,
                'sort_order' => $index,
                'title' => "{$definition['business_name']} {$item['title']}",
                'description' => "A completed {$item['category']} project in {$definition['location']}.",
                'category' => $item['category'],
                'image_id' => null,
            ]));
        $projects->publishedRevision->update([
            'content_data' => ['sections' => [
                [
                    'type' => 'projects_hero',
                    'title' => 'Selected Projects',
                    'subtitle' => "Recent work completed around {$definition['location']}.",
                ],
                [
                    'type' => 'project_gallery',
                    'eyebrow' => 'Our Work',
                    'title' => 'Recent Work',
                    'item_ids' => $projectItems->pluck('id')->all(),
                ],
            ]],
        ]);

        $pages = [$home, $service, $about, $contact, $projects->fresh()];
        if (isset($definition['long_slug'])) {
            $pages[] = $this->createHierarchyCorpusPage($site, $corpusId, 6, $definition['long_slug'], [
                [
                    'type' => 'intro',
                    'eyebrow' => 'Specialist Service',
                    'title' => 'Long-Path Specialist Service',
                    'body' => "Detailed restoration support across {$definition['location']}.",
                ],
                [
                    'type' => 'features',
                    'title' => 'A Complete Service',
                    'items' => [
                        ['icon' => 'search', 'title' => 'Inspect', 'body' => 'A careful condition survey.'],
                        ['icon' => 'clipboard', 'title' => 'Specify', 'body' => 'A detailed restoration plan.'],
                        ['icon' => 'shield', 'title' => 'Protect', 'body' => 'Durable weatherproofing.'],
                    ],
                ],
            ]);
        }
        $version = SiteVersion::create([
            'site_id' => $site->id,
            'version' => 1,
            'composition' => [
                'nav' => ['items' => []],
                'footer' => ['columns' => [], 'show_credit' => true],
                'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
                'homepage_page_id' => $home->id,
            ],
            'page_revisions' => array_map(
                fn (GeneratedPage $page): array => [
                    'page_id' => $page->id,
                    'revision_id' => $page->published_revision_id,
                ],
                $pages,
            ),
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create([
            'site_id' => $site->id,
            'version_id' => $version->id,
            'updated_at' => now(),
        ]);

        return ['site' => $site->fresh(), 'pages' => $pages, 'host' => $host, 'palette' => $palette];
    }

    /**
     * @return array<int, array{business_name: string, business_type: string, location: string, layout: string, display_font: string, hero: string, service_slug: string, service_title: string, long_slug?: string}>
     */
    private function hierarchyCorpusDefinitions(): array
    {
        return [
            51 => [
                'business_name' => 'Eden Outdoor Living',
                'business_type' => 'Landscaping',
                'location' => 'Wigan',
                'layout' => 'showcase',
                'display_font' => 'fraunces',
                'hero' => 'Outdoor Spaces Made for Living',
                'service_slug' => 'garden-design',
                'service_title' => 'Garden Design',
            ],
            52 => [
                'business_name' => 'Hunt Property Services',
                'business_type' => 'Building',
                'location' => 'Bolton',
                'layout' => 'editorial',
                'display_font' => 'dm-serif-display',
                'hero' => 'Built Around Your Home',
                'service_slug' => 'home-extensions',
                'service_title' => 'Home Extensions',
            ],
            53 => [
                'business_name' => 'North House Electrical',
                'business_type' => 'Electrical',
                'location' => 'Preston',
                'layout' => 'precision',
                'display_font' => 'space-grotesk',
                'hero' => 'Electrical Work, Precisely Delivered',
                'service_slug' => 'commercial-electrics',
                'service_title' => 'Commercial Electrics',
            ],
            54 => [
                'business_name' => 'North House Renovations',
                'business_type' => 'Renovation',
                'location' => 'Lancaster',
                'layout' => 'banded',
                'display_font' => 'archivo-black',
                'hero' => 'Considered Renovation from Start to Finish',
                'service_slug' => 'whole-home-renovation',
                'service_title' => 'Whole Home Renovation',
                'long_slug' => 'bespoke-restoration-and-weatherproofing-for-historic-rural-commercial-properties',
            ],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function hierarchyCorpusPalettes(): array
    {
        return [
            51 => [
                'primary_color' => '#1a1a1c',
                'accent_color' => '#c0552f',
                'tertiary_color' => '#a3b8a8',
                'surface_color' => '#f9f8f6',
                'surface_alt_color' => '#efece6',
                'border_color' => '#d1d5d0',
                'text_color' => '#1a2421',
                'text_muted_color' => '#5c6b64',
            ],
            52 => [
                'primary_color' => '#2e4429',
                'accent_color' => '#98652f',
                'tertiary_color' => '#8a7f63',
                'surface_color' => '#f7f3ea',
                'surface_alt_color' => '#efe7d6',
                'border_color' => '#dfd5c0',
                'text_color' => '#1f1b14',
                'text_muted_color' => '#5c5343',
            ],
            53 => [
                'primary_color' => '#174c66',
                'accent_color' => '#a94716',
                'tertiary_color' => '#6e8c99',
                'surface_color' => '#fbfcfd',
                'surface_alt_color' => '#edf3f5',
                'border_color' => '#cad7dc',
                'text_color' => '#13252d',
                'text_muted_color' => '#536871',
            ],
            54 => [
                'primary_color' => '#91a9ce',
                'accent_color' => '#e8590c',
                'tertiary_color' => '#87909c',
                'surface_color' => '#090a0c',
                'surface_alt_color' => '#0f1113',
                'border_color' => '#343a40',
                'text_color' => '#e5e8eb',
                'text_muted_color' => '#969da5',
            ],
        ];
    }

    /**
     * @param  array<string, string>  $palette
     * @return array<string, mixed>
     */
    private function hierarchyDesignBrief(array $palette, string $displayFont): array
    {
        $brief = [
            'mood' => 'refined-minimal',
            'display_font' => $displayFont,
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

        $this->assertNotNull(DesignBrief::fromArray($brief), "Hierarchy corpus design brief using [{$displayFont}] must pass validation.");

        return $brief;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function createHierarchyCorpusPage(
        Site $site,
        int $corpusId,
        int $pageSequence,
        string $pageType,
        array $sections,
    ): GeneratedPage {
        $page = GeneratedPage::factory()->for($site)->create([
            'id' => ($corpusId * 100) + $pageSequence,
            'page_type' => $pageType,
        ]);
        $revision = PageRevision::factory()->for($page, 'page')->create([
            'id' => ($corpusId * 100) + $pageSequence,
            'content_data' => ['sections' => $sections],
        ]);
        $page->update(['published_revision_id' => $revision->id]);

        return $page->fresh();
    }
}

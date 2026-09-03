<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HomeSectionExtractionSnapshotTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function baseVars(bool $markers): array
    {
        return [
            'sectionIndex' => 1,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => $markers,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'profile' => [
                'watermark_enabled' => true,
                'geo' => ['scope' => 'local'],
            ],
            'site' => null,
            'pagesBySlug' => [
                'boiler-repair' => '/boiler-repair',
                'bathroom-fitting' => '/bathroom-fitting',
                'contact' => '/contact',
                'projects' => '/projects',
            ],
            'heroImages' => [],
            'itemsById' => collect(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function servicesClassicVars(bool $markers): array
    {
        return array_merge($this->baseVars($markers), [
            'section' => [
                'type' => 'services',
                'title' => 'What We Do',
                'eyebrow' => 'Our Services',
                'intro' => 'Trade services across the borough.',
                'accent_word' => 'Do',
                'items' => [
                    [
                        'icon' => 'wrench',
                        'title' => 'Boiler repair',
                        'body' => 'Fast boiler fixes.',
                        'source_service' => 'Boiler Repair',
                        'featured' => true,
                    ],
                    [
                        'icon' => 'bath',
                        'title' => 'Bathroom fitting',
                        'body' => 'Full bathroom refits.',
                        'source_service' => 'Bathroom Fitting',
                    ],
                    [
                        'title' => 'Talk to us',
                        'body' => 'Book a visit.',
                        'contact_cta' => true,
                        'cta_label' => 'Get in touch',
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function servicesPhotoCardsVars(bool $markers): array
    {
        $vars = $this->servicesClassicVars($markers);
        $vars['section']['variant'] = 'photo-cards';
        $vars['heroImages'] = [
            'boiler-repair' => [
                'url' => 'https://example.test/boiler-clean.jpg',
                'watermark_url' => 'https://example.test/boiler-wm.jpg',
            ],
            'bathroom-fitting' => [
                'url' => 'https://example.test/bath-clean.jpg',
                'watermark_url' => 'https://example.test/bath-wm.jpg',
            ],
        ];

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function servicesPhotoCardsDuplicateVars(bool $markers): array
    {
        $vars = $this->servicesPhotoCardsVars($markers);
        $shared = [
            'url' => 'https://example.test/shared.jpg',
            'watermark_url' => 'https://example.test/shared-wm.jpg',
        ];
        $vars['heroImages'] = [
            'boiler-repair' => $shared,
            'bathroom-fitting' => $shared,
        ];

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function servicesEmptyTitleVars(bool $markers): array
    {
        $vars = $this->servicesClassicVars($markers);
        unset($vars['section']['title'], $vars['section']['intro'], $vars['section']['eyebrow']);

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function servicesEmptyItemsVars(bool $markers): array
    {
        $vars = $this->servicesClassicVars($markers);
        $vars['section']['items'] = [];

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function servicesCount4Vars(bool $markers): array
    {
        $vars = $this->baseVars($markers);
        $items = [];
        foreach (range(1, 4) as $n) {
            $items[] = ['icon' => 'hammer', 'title' => "Service {$n}", 'body' => "Body {$n}."];
        }
        $vars['section'] = [
            'type' => 'services',
            'title' => 'Four services',
            'items' => $items,
        ];

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function servicesCount8Vars(bool $markers): array
    {
        $vars = $this->baseVars($markers);
        $items = [];
        foreach (range(1, 8) as $n) {
            $items[] = ['icon' => 'hammer', 'title' => "Service {$n}", 'body' => "Body {$n}."];
        }
        $vars['section'] = [
            'type' => 'services',
            'title' => 'Eight services',
            'items' => $items,
        ];

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function servicesNoIconVars(bool $markers): array
    {
        $vars = $this->baseVars($markers);
        $vars['section'] = [
            'type' => 'services',
            'title' => 'Numbered cards',
            'items' => [
                ['title' => 'First', 'body' => 'No icon here.'],
            ],
        ];

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function trustThreeVars(bool $markers): array
    {
        return array_merge($this->baseVars($markers), [
            'section' => [
                'type' => 'trust',
                'title' => 'Why homeowners pick us',
                'eyebrow' => 'Why Choose Us',
                'items' => [
                    ['title' => 'Quality Craftsmanship', 'body' => 'Every project completed to an exceptional standard.'],
                    ['title' => 'Honest & Transparent', 'body' => 'Clear upfront quotes with no hidden surprises.'],
                    ['title' => 'London Specialists', 'body' => 'We know the local property landscape.'],
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function trustFourVars(bool $markers): array
    {
        $vars = $this->trustThreeVars($markers);
        $vars['section']['items'][] = ['title' => 'Fourth Signal', 'body' => 'Must be clamped on classic.'];

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function trustTitleMatchesEyebrowVars(bool $markers): array
    {
        $vars = $this->trustThreeVars($markers);
        $vars['section']['title'] = 'Why Choose Us';

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function trustEmptyTitleVars(bool $markers): array
    {
        $vars = $this->trustThreeVars($markers);
        unset($vars['section']['title'], $vars['section']['eyebrow']);

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function trustEmptyItemsVars(bool $markers): array
    {
        $vars = $this->trustThreeVars($markers);
        $vars['section']['items'] = [];

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function processVars(bool $markers): array
    {
        return array_merge($this->baseVars($markers), [
            'section' => [
                'type' => 'process',
                'title' => 'How it works',
                'eyebrow' => 'Our Process',
                'items' => [
                    ['step' => 1, 'title' => 'Book a survey', 'body' => 'We visit at a time that suits you.'],
                    ['step' => 2, 'title' => 'Receive your quote', 'body' => 'Detailed quote within 24h.'],
                    ['step' => 3, 'title' => 'Work is scheduled', 'body' => 'We confirm dates that work.'],
                    ['step' => 4, 'title' => 'Aftercare included', 'body' => 'We follow up after every job.'],
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function processEmptyTitleVars(bool $markers): array
    {
        $vars = $this->processVars($markers);
        unset($vars['section']['title'], $vars['section']['eyebrow']);

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function processEmptyItemsVars(bool $markers): array
    {
        $vars = $this->processVars($markers);
        $vars['section']['items'] = [];

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function portfolioClassicVars(bool $markers): array
    {
        $vars = $this->baseVars($markers);
        $vars['profile']['portfolio_images'] = [
            'https://example.test/p1.jpg',
            'https://example.test/p2.jpg',
            'not-a-url',
            'https://example.test/p3.jpg',
        ];
        $vars['section'] = [
            'type' => 'portfolio_strip',
            'title' => 'Recent projects',
            'eyebrow' => 'Our work',
            'intro' => 'A handful of recent jobs.',
        ];

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function portfolioClassicEmptyVars(bool $markers): array
    {
        $vars = $this->portfolioClassicVars($markers);
        $vars['profile']['portfolio_images'] = [];

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function portfolioDarkBandVars(bool $markers): array
    {
        $vars = $this->baseVars($markers);
        $vars['itemsById'] = $this->darkBandItems();
        $vars['section'] = [
            'type' => 'portfolio_strip',
            'variant' => 'dark-band',
            'title' => 'Featured projects',
            'eyebrow' => 'Our work',
            'item_ids' => [11, 12, 13],
        ];

        return $vars;
    }

    /**
     * @return array<string, mixed>
     */
    private function portfolioDarkBandEmptyVars(bool $markers): array
    {
        $vars = $this->portfolioDarkBandVars($markers);
        $vars['section']['item_ids'] = [];
        $vars['itemsById'] = collect();

        return $vars;
    }

    /**
     * @return Collection<int, object>
     */
    private function darkBandItems(): Collection
    {
        $make = function (int $id, string $title, string $category): object {
            $image = (object) [
                'url' => "https://example.test/project-{$id}.jpg",
                'id' => $id,
            ];

            return (object) [
                'title' => $title,
                'category' => $category,
                'image' => $image,
            ];
        };

        return collect([
            11 => $make(11, 'Kitchen refit', 'Kitchens'),
            12 => $make(12, 'Loft conversion', 'Lofts'),
            13 => $make(13, 'Garden room', 'Extensions'),
        ]);
    }

    public static function snapshotCases(): array
    {
        return [
            'services-classic-markers' => ['site.sections.services', 'servicesClassicVars', true, 'services-classic-markers'],
            'services-classic-plain' => ['site.sections.services', 'servicesClassicVars', false, 'services-classic-plain'],
            'services-photo-cards-markers' => ['site.sections.services', 'servicesPhotoCardsVars', true, 'services-photo-cards-markers'],
            'services-photo-cards-plain' => ['site.sections.services', 'servicesPhotoCardsVars', false, 'services-photo-cards-plain'],
            'services-photo-cards-dup-plain' => ['site.sections.services', 'servicesPhotoCardsDuplicateVars', false, 'services-photo-cards-dup-plain'],
            'services-empty-title-markers' => ['site.sections.services', 'servicesEmptyTitleVars', true, 'services-empty-title-markers'],
            'services-empty-items-markers' => ['site.sections.services', 'servicesEmptyItemsVars', true, 'services-empty-items-markers'],
            'services-count4-plain' => ['site.sections.services', 'servicesCount4Vars', false, 'services-count4-plain'],
            'services-count8-plain' => ['site.sections.services', 'servicesCount8Vars', false, 'services-count8-plain'],
            'services-no-icon-plain' => ['site.sections.services', 'servicesNoIconVars', false, 'services-no-icon-plain'],
            'trust-3items-markers' => ['site.sections.trust', 'trustThreeVars', true, 'trust-3items-markers'],
            'trust-3items-plain' => ['site.sections.trust', 'trustThreeVars', false, 'trust-3items-plain'],
            'trust-4items-markers' => ['site.sections.trust', 'trustFourVars', true, 'trust-4items-markers'],
            'trust-4items-plain' => ['site.sections.trust', 'trustFourVars', false, 'trust-4items-plain'],
            'trust-title-matches-eyebrow-plain' => ['site.sections.trust', 'trustTitleMatchesEyebrowVars', false, 'trust-title-matches-eyebrow-plain'],
            'trust-empty-title-markers' => ['site.sections.trust', 'trustEmptyTitleVars', true, 'trust-empty-title-markers'],
            'trust-empty-items-plain' => ['site.sections.trust', 'trustEmptyItemsVars', false, 'trust-empty-items-plain'],
            'process-markers' => ['site.sections.process', 'processVars', true, 'process-markers'],
            'process-plain' => ['site.sections.process', 'processVars', false, 'process-plain'],
            'process-empty-title-markers' => ['site.sections.process', 'processEmptyTitleVars', true, 'process-empty-title-markers'],
            'process-empty-items-plain' => ['site.sections.process', 'processEmptyItemsVars', false, 'process-empty-items-plain'],
            'portfolio-classic-markers' => ['site.sections.portfolio_strip', 'portfolioClassicVars', true, 'portfolio-classic-markers'],
            'portfolio-classic-plain' => ['site.sections.portfolio_strip', 'portfolioClassicVars', false, 'portfolio-classic-plain'],
            'portfolio-classic-empty-plain' => ['site.sections.portfolio_strip', 'portfolioClassicEmptyVars', false, 'portfolio-classic-empty-plain'],
            'portfolio-dark-band-plain' => ['site.sections.portfolio_strip', 'portfolioDarkBandVars', false, 'portfolio-dark-band-plain'],
            'portfolio-dark-band-empty-plain' => ['site.sections.portfolio_strip', 'portfolioDarkBandEmptyVars', false, 'portfolio-dark-band-empty-plain'],
        ];
    }

    #[DataProvider('snapshotCases')]
    public function test_render_matches_snapshot(string $view, string $varsFn, bool $markers, string $name): void
    {
        $normalize = fn (string $h): string => trim(preg_replace(['/>\s+</', '/\s+/'], ['><', ' '], $h));
        $html = $normalize(View::make($view, $this->{$varsFn}($markers))->render());
        $path = base_path("tests/fixtures/home-sections/{$name}.html");
        if (! file_exists($path)) {
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, $html);
            $this->markTestIncomplete("Snapshot created at {$path} — re-run to assert.");
        }
        $this->assertSame(file_get_contents($path), $html, "{$name} drifted from snapshot");
    }

    public function test_classic_trust_clamps_four_items_to_three(): void
    {
        $html = View::make('site.sections.trust', $this->trustFourVars(false))->render();
        $this->assertStringContainsString('Quality Craftsmanship', $html);
        $this->assertStringContainsString('London Specialists', $html);
        $this->assertStringNotContainsString('Fourth Signal', $html, 'classic trust 4->3 clamp arm must hold');
    }
}

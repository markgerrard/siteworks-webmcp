<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Contract: every globbed variant emits the family-default
 * data-editable-field set. intro/features/story/services/process are
 * exact-set; values and trust are SUPERSET (classic clamps and only
 * marks clamped items). portfolio_strip is glob-covered only: dark-band
 * is a different content model (ProjectItems, no section-field markers)
 * and is D0-frozen. Families are hardcoded; variant names are discovered
 * by glob so a new partial is auto-covered and fails this test if it
 * drops a marker.
 */
class VariantMarkerParityTest extends TestCase
{
    public function test_globs_family_defaults_for_every_known_family(): void
    {
        foreach ($this->familyMap() as $family => $default) {
            $variants = $this->variantsFor($family);
            $this->assertContains(
                $default,
                $variants,
                "{$family} family is missing default variant {$default}",
            );
        }
    }

    public function test_every_globbed_variant_emits_the_same_editable_fields_as_family_default(): void
    {
        $compared = 0;

        foreach ($this->familyMap() as $family => $default) {
            if ($family === 'portfolio_strip') {
                continue;
            }
            $expected = $this->editableFields($this->renderFamily($family, $default));
            $this->assertNotEmpty($expected, "{$family}/{$default} emitted no data-editable-field markers");

            foreach ($this->variantsFor($family) as $variant) {
                $actual = $this->editableFields($this->renderFamily($family, $variant));
                if (in_array($family, ['values', 'trust'], true)) {
                    $missing = array_values(array_diff($expected, $actual));
                    $this->assertSame(
                        [],
                        $missing,
                        "{$family}/{$variant} dropped classic fields: ".implode(', ', $missing),
                    );
                } else {
                    $this->assertSame(
                        $expected,
                        $actual,
                        "{$family}/{$variant} data-editable-field set drifted from {$family}/{$default}",
                    );
                }
                $compared++;
            }
        }

        $this->assertGreaterThanOrEqual(20, $compared);
    }

    public function test_home_extracted_families_are_explicitly_in_the_parity_harness(): void
    {
        $map = $this->familyMap();
        foreach (['services', 'trust', 'process', 'portfolio_strip'] as $family) {
            $this->assertArrayHasKey(
                $family,
                $map,
                "T10 must explicitly glob {$family}; the harness does not auto-cover new home families",
            );
            $this->assertContains(
                $map[$family],
                $this->variantsFor($family),
                "{$family} family is missing default variant {$map[$family]}",
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function familyMap(): array
    {
        return [
            'intro' => 'classic',
            'features' => 'cards',
            'story' => 'classic',
            'values' => 'classic',
            'services' => 'classic',
            'trust' => 'classic',
            'process' => 'classic',
            'portfolio_strip' => 'classic',
            'project_gallery' => 'classic',
        ];
    }

    /**
     * @return list<string>
     */
    private function variantsFor(string $family): array
    {
        $pattern = dirname(__DIR__, 3)."/resources/views/site/sections/variants/{$family}/*.blade.php";
        $files = glob($pattern) ?: [];
        $names = array_map(
            fn (string $path): string => basename($path, '.blade.php'),
            $files,
        );
        sort($names);

        return array_values($names);
    }

    /**
     * @return list<string>
     */
    private function editableFields(string $html): array
    {
        preg_match_all('/data-editable-field="([^"]+)"/', $html, $matches);

        $fields = array_values(array_unique($matches[1] ?? []));
        sort($fields);

        return $fields;
    }

    private function renderFamily(string $family, string $variant): string
    {
        return View::make("site.sections.{$family}", $this->fixtureVars($family, $variant))->render();
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtureVars(string $family, string $variant): array
    {
        $base = [
            'sectionIndex' => 1,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => true,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'profile' => ['watermark_enabled' => false],
            'introImageUrl' => 'https://example.test/intro.jpg',
        ];

        if ($family === 'intro') {
            return [
                ...$base,
                'section' => [
                    'type' => 'intro',
                    'variant' => $variant,
                    'title' => 'Extensions & Loft Conversions',
                    'eyebrow' => 'About This Service',
                    'body' => "First paragraph of prose.\n\nSecond paragraph with detail.",
                ],
            ];
        }

        if ($family === 'story') {
            return [
                ...$base,
                'section' => [
                    'type' => 'story',
                    'variant' => $variant,
                    'title' => 'Our Story',
                    'eyebrow' => 'About Us',
                    'body' => "First paragraph of story.\n\nSecond paragraph with more detail.\n\nThird paragraph continues the narrative.\n\nFourth paragraph closes the story.",
                ],
                'introImageUrl' => [
                    'url' => 'https://example.test/story.jpg',
                    'watermark_url' => 'https://example.test/story-wm.jpg',
                ],
            ];
        }

        if ($family === 'values') {
            // Six items: classic clamps (>5 → 5) and only marks the clamped
            // set; new variants receive the full list (superset rule).
            $items = [];
            foreach (range(1, 6) as $n) {
                $items[] = ['title' => "Value {$n}", 'body' => "Conviction {$n}."];
            }

            return [
                ...$base,
                'sectionIndex' => 2,
                'section' => [
                    'type' => 'values',
                    'variant' => $variant,
                    'title' => 'What We Stand For',
                    'eyebrow' => 'Our Values',
                    'items' => $items,
                ],
            ];
        }

        if ($family === 'services') {
            $items = [];
            foreach (range(1, 3) as $n) {
                $items[] = ['icon' => 'hammer', 'title' => "Service {$n}", 'body' => "Body {$n}."];
            }

            return [
                ...$base,
                'profile' => [
                    'watermark_enabled' => false,
                    'geo' => ['scope' => 'local'],
                ],
                'site' => null,
                'pagesBySlug' => [
                    'service-1' => '/service-1',
                    'contact' => '/contact',
                ],
                'heroImages' => [],
                'itemsById' => collect(),
                'section' => [
                    'type' => 'services',
                    'variant' => $variant,
                    'title' => 'What We Do',
                    'eyebrow' => 'Our Services',
                    'intro' => 'Trade services across the borough.',
                    'items' => $items,
                ],
            ];
        }

        if ($family === 'trust') {
            // Six items: classic clamps to 3 and only marks the clamped
            // set; new variants receive the full list (superset rule).
            $items = [];
            foreach (range(1, 6) as $n) {
                $items[] = ['title' => "Signal {$n}", 'body' => "Why {$n}."];
            }

            return [
                ...$base,
                'sectionIndex' => 2,
                'section' => [
                    'type' => 'trust',
                    'variant' => $variant,
                    'title' => 'Why Choose Us',
                    'eyebrow' => 'Our Signals',
                    'items' => $items,
                ],
            ];
        }

        if ($family === 'process') {
            $items = [];
            foreach (range(1, 4) as $n) {
                $items[] = ['step' => (string) $n, 'title' => "Step {$n}", 'body' => "Do {$n}."];
            }

            return [
                ...$base,
                'sectionIndex' => 2,
                'section' => [
                    'type' => 'process',
                    'variant' => $variant,
                    'title' => 'How We Work',
                    'eyebrow' => 'Our Process',
                    'items' => $items,
                ],
            ];
        }

        if ($family === 'project_gallery') {
            return [
                ...$base,
                'itemsById' => collect(),
                'section' => [
                    'type' => 'project_gallery',
                    'variant' => $variant,
                    'title' => 'Recent Work',
                    'item_ids' => [],
                ],
            ];
        }

        $items = [];
        foreach (range(1, 6) as $n) {
            $items[] = ['icon' => 'hammer', 'title' => "Item {$n}", 'body' => "Body {$n}."];
        }

        return [
            ...$base,
            'sectionIndex' => 2,
            'section' => [
                'type' => 'features',
                'variant' => $variant,
                'title' => "What's Included",
                'eyebrow' => "What's Included",
                'intro' => 'Scope intro line.',
                'items' => $items,
            ],
        ];
    }
}

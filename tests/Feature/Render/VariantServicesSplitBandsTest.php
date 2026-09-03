<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantServicesSplitBandsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function servicesVars(bool $markers, array $sectionOverrides = [], array $extra = []): array
    {
        $items = $sectionOverrides['items'] ?? [
            ['title' => 'Boiler Repair', 'body' => 'Fast fixes.', 'source_service' => 'Boiler Repair'],
            ['title' => 'Bathroom Fitting', 'body' => 'Full refits.', 'source_service' => 'Bathroom Fitting'],
            ['title' => 'Wet Rooms', 'body' => 'Level access.', 'source_service' => 'Wet Rooms'],
        ];
        unset($sectionOverrides['items']);

        return array_merge([
            'section' => array_merge([
                'type' => 'services',
                'variant' => 'split-bands',
                'title' => 'What We Do',
                'eyebrow' => 'Our Services',
                'intro' => 'Trade services across the borough.',
                'items' => $items,
            ], $sectionOverrides),
            'sectionIndex' => 1,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => $markers,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'profile' => ['watermark_enabled' => false, 'geo' => ['scope' => 'local']],
            'site' => null,
            'pagesBySlug' => ['boiler-repair' => '/boiler-repair'],
            'heroImages' => [
                'boiler-repair' => ['url' => 'https://example.test/boiler.jpg', 'watermark_url' => null],
                'bathroom-fitting' => ['url' => 'https://example.test/bathroom.jpg', 'watermark_url' => null],
            ],
            'itemsById' => collect(),
        ], $extra);
    }

    private function render(bool $markers, array $sectionOverrides = [], array $extra = []): string
    {
        return View::make('site.sections.services', $this->servicesVars($markers, $sectionOverrides, $extra))->render();
    }

    public function test_routes_to_split_bands_with_brand_panel_chrome(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="split-bands"', $html);
        $this->assertStringContainsString('background-color: var(--brand-primary);', $html);
        $this->assertStringContainsString('background-color: var(--brand-accent);', $html, 'accent rule missing');
        $this->assertStringContainsString('var(--color-text-on-primary, #ffffff)', $html);
        $this->assertStringContainsString('What We Do', $html);
        $this->assertStringContainsString('Fast fixes.', $html);
    }

    public function test_item_photos_resolve_by_source_service_and_alternate_sides(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('https://example.test/boiler.jpg', $html);
        $this->assertStringContainsString('https://example.test/bathroom.jpg', $html);
        $this->assertStringContainsString('lg:order-last', $html, 'second band must flip the image side');
    }

    public function test_item_without_hero_gets_a_panel_only_band(): void
    {
        $html = $this->render(false);

        // Wet Rooms has no heroImages entry: its band renders without media.
        $this->assertSame(2, substr_count($html, 'data-svc-media'));
        $this->assertSame(2, substr_count($html, 'lg:grid-cols-2'));
    }

    public function test_duplicate_photo_urls_fall_back_to_panel_only(): void
    {
        $html = $this->render(false, [], ['heroImages' => [
            'boiler-repair' => ['url' => 'https://example.test/same.jpg', 'watermark_url' => null],
            'bathroom-fitting' => ['url' => 'https://example.test/same.jpg', 'watermark_url' => null],
        ]]);

        $this->assertStringNotContainsString('https://example.test/same.jpg', $html);
        $this->assertSame(0, substr_count($html, 'data-svc-media'));
    }

    public function test_read_more_links_via_resolver_and_titles_stay_plain(): void
    {
        $html = $this->render(false);

        // Card-style read-more chrome, only for resolvable items; titles
        // carry no anchor and no hover underline.
        $this->assertStringContainsString('href="/boiler-repair"', $html);
        $this->assertSame(1, substr_count($html, 'Read more'));
        $this->assertStringContainsString('border-b-2', $html);
        $this->assertStringNotContainsString('hover:underline', $html);
        $this->assertDoesNotMatchRegularExpression('/<a[^>]*>Boiler Repair<\/a>/', $html);
    }

    public function test_bands_bleed_full_width_with_container_aligned_copy(): void
    {
        $html = $this->render(false);

        $this->assertSame(
            2,
            substr_count($html, 'calc(var(--container-width) / 2)'),
            'each image band panel constrains copy to half the container',
        );
        $this->assertStringContainsString('margin-right: auto;', $html);
        $this->assertStringContainsString('margin-left: auto;', $html, 'alternating band must flip the copy alignment');
        // The no-photo band centres copy on the full container width.
        $this->assertStringContainsString('width: min(100%, var(--container-width));', $html);
    }

    public function test_emits_classic_editor_field_set(): void
    {
        $html = $this->render(true);

        foreach (['eyebrow', 'title', 'intro', 'items.0.title', 'items.0.body', 'items.0.icon'] as $field) {
            $this->assertStringContainsString('data-editable-field="'.$field.'"', $html, "missing marker {$field}");
        }
    }
}

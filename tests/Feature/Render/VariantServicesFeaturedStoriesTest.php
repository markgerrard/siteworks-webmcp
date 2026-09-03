<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantServicesFeaturedStoriesTest extends TestCase
{
    private function vars(bool $markers, array $sectionOverrides = [], array $extra = []): array
    {
        $items = $sectionOverrides['items'] ?? array_map(
            fn (int $n) => ['title' => "Service {$n}", 'body' => "Body {$n}.", 'source_service' => "Service {$n}"],
            range(1, 12),
        );
        unset($sectionOverrides['items']);

        return array_merge([
            'section' => array_merge([
                'type' => 'services', 'variant' => 'featured-stories',
                'title' => 'What We Do', 'eyebrow' => 'Our Services',
                'intro' => 'Across the borough.', 'items' => $items,
            ], $sectionOverrides),
            'sectionIndex' => 1, 'pageId' => 42, 'mode' => 'public',
            'emitMarkers' => $markers, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [],
            'profile' => ['watermark_enabled' => false, 'geo' => ['scope' => 'local']],
            'site' => null,
            'pagesBySlug' => ['service-1' => '/service-1'],
            'heroImages' => [
                'service-1' => ['url' => 'https://example.test/s1.jpg', 'watermark_url' => null],
                'service-2' => ['url' => 'https://example.test/s2.jpg', 'watermark_url' => null],
            ],
            'itemsById' => collect(),
        ], $extra);
    }

    private function render(bool $markers, array $sectionOverrides = [], array $extra = []): string
    {
        return View::make('site.sections.services', $this->vars($markers, $sectionOverrides, $extra))->render();
    }

    public function test_routes_and_featured_rows_carry_resolved_photos(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="featured-stories"', $html);
        $this->assertStringContainsString('https://example.test/s1.jpg', $html);
        $this->assertStringContainsString('https://example.test/s2.jpg', $html);
        $this->assertStringContainsString('lg:order-last', $html, 'second story flips the image side');
        $this->assertSame(2, substr_count($html, 'data-svc-media'), 'services 3-4 have no hero: type-led degrade');
    }

    public function test_duplicate_photo_urls_degrade_that_row_to_type_led(): void
    {
        $html = $this->render(false, [], ['heroImages' => [
            'service-1' => ['url' => 'https://example.test/same.jpg', 'watermark_url' => null],
            'service-2' => ['url' => 'https://example.test/same.jpg', 'watermark_url' => null],
        ]]);

        $this->assertStringNotContainsString('https://example.test/same.jpg', $html);
        $this->assertSame(0, substr_count($html, 'data-svc-media'));
        $this->assertStringContainsString('Service 1', $html);
    }

    public function test_tail_is_unnumbered_and_contrast_swap_holds(): void
    {
        $html = $this->render(false, ['__surface' => 'contrast']);

        $this->assertStringContainsString('Also covered', $html);
        $this->assertStringNotContainsString('>05</span>', $html);
        $this->assertStringContainsString('background-color: var(--color-surface-contrast)', $html);
        $this->assertStringNotContainsString('color-mix(in oklab, var(--color-text) ', $html);
    }

    public function test_emits_classic_editor_field_set_for_all_items(): void
    {
        $html = $this->render(true);

        foreach (['eyebrow', 'title', 'intro', 'items.0.title', 'items.0.body', 'items.0.icon', 'items.11.title', 'items.11.body', 'items.11.icon'] as $field) {
            $this->assertStringContainsString('data-editable-field="'.$field.'"', $html, "missing {$field}");
        }
    }
}

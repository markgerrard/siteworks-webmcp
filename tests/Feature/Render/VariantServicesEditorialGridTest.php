<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantServicesEditorialGridTest extends TestCase
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
                'type' => 'services', 'variant' => 'editorial-grid',
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

    public function test_routes_flat_tiles_with_right_hung_numeral_suffixes(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="editorial-grid"', $html);
        $this->assertStringContainsString('lg:grid-cols-4', $html);
        // Numeral rides as a small suffix inside the title row; tiles carry
        // no card chrome.
        $this->assertStringContainsString('>01</span>', $html);
        $this->assertStringContainsString('>04</span>', $html);
        $this->assertStringNotContainsString('>05</span>', $html);
        $this->assertStringNotContainsString('shadow', $html);
        $this->assertStringNotContainsString('border-radius: var(--radius-card)', $html);
        $this->assertStringContainsString('Also covered', $html);
    }

    public function test_photos_resolve_and_tiles_degrade_to_type_only(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('https://example.test/s1.jpg', $html);
        $this->assertSame(2, substr_count($html, 'data-svc-media'), 'services 3-4 have no hero: type-only tiles');
    }

    public function test_duplicate_photo_urls_degrade(): void
    {
        $html = $this->render(false, [], ['heroImages' => [
            'service-1' => ['url' => 'https://example.test/same.jpg', 'watermark_url' => null],
            'service-2' => ['url' => 'https://example.test/same.jpg', 'watermark_url' => null],
        ]]);

        $this->assertSame(0, substr_count($html, 'data-svc-media'));
    }

    public function test_contrast_swap_holds(): void
    {
        $html = $this->render(false, ['__surface' => 'contrast']);

        $this->assertStringContainsString('background-color: var(--color-surface-contrast)', $html);
        $this->assertStringNotContainsString('var(--brand-accent-text)"', $html);
        $this->assertStringNotContainsString('color-mix(in oklab, var(--color-text) ', $html);
    }

    public function test_whole_tile_links_outside_editor_mode(): void
    {
        $html = $this->render(false);
        $this->assertStringContainsString("after:absolute after:inset-0", $html);
        $this->assertStringContainsString('href="/service-1"', $html);

        $editor = $this->render(true);
        $this->assertStringNotContainsString('after:absolute after:inset-0', $editor);
    }

    public function test_grid_knobs_columns_and_row_cap(): void
    {
        $html = $this->render(false, ['__options' => ['grid_columns' => 3, 'grid_rows' => 2]]);

        $this->assertStringContainsString('lg:grid-cols-3', $html);
        $this->assertStringContainsString('>06</span>', $html, '3 cols x 2 rows = 6 tiles');
        $this->assertStringNotContainsString('>07</span>', $html);
        $this->assertStringContainsString('Also covered', $html);
    }

    public function test_grid_rows_all_shows_every_item_with_no_tail(): void
    {
        $html = $this->render(false, ['__options' => ['grid_columns' => 3, 'grid_rows' => 'all']]);

        $this->assertStringContainsString('>12</span>', $html);
        $this->assertStringNotContainsString('Also covered', $html);
    }

    public function test_defaults_stay_four_by_one(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('lg:grid-cols-4', $html);
        $this->assertStringContainsString('>04</span>', $html);
        $this->assertStringNotContainsString('>05</span>', $html);
    }

    public function test_grid_options_validate(): void
    {
        $registry = app(\App\Services\Site\PageLayoutRegistry::class);
        $base = ['schema_version' => 1, 'variants' => ['services' => 'editorial-grid'], 'eyebrow_policy' => 'all', 'insert_sections' => []];

        $this->assertTrue($registry->isUsable($base + ['options' => ['grid_columns' => 3, 'grid_rows' => 'all']], 'home'));
        $this->assertFalse($registry->isUsable($base + ['options' => ['grid_columns' => 5]], 'home'));
        $this->assertFalse($registry->isUsable($base + ['options' => ['grid_rows' => 'some']], 'home'));
    }

    public function test_numbers_knob_off_drops_the_suffixes(): void
    {
        $html = $this->render(false, ['__options' => ['grid_numbers' => false]]);

        $this->assertStringNotContainsString('>01</span>', $html);
        $this->assertStringNotContainsString('tabular-nums', $html);
        $this->assertStringContainsString('Service 1', $html);
    }

    public function test_corner_knob_rounds_image_tops_via_the_radius_token(): void
    {
        $html = $this->render(false, ['__options' => ['grid_image_corners' => 'round-top']]);
        $this->assertStringContainsString('border-radius: var(--radius-card) var(--radius-card) 0 0', $html);

        $square = $this->render(false);
        $this->assertStringNotContainsString('border-radius:', $square);
    }

    public function test_emits_classic_editor_field_set_for_all_items(): void
    {
        $html = $this->render(true);

        foreach (['eyebrow', 'title', 'intro', 'items.0.title', 'items.0.body', 'items.0.icon', 'items.11.title', 'items.11.body', 'items.11.icon'] as $field) {
            $this->assertStringContainsString('data-editable-field="'.$field.'"', $html, "missing {$field}");
        }
    }
}

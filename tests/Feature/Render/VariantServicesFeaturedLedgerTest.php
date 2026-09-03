<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantServicesFeaturedLedgerTest extends TestCase
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
                'type' => 'services', 'variant' => 'featured-ledger',
                'title' => 'What We Do', 'eyebrow' => 'Our Services',
                'intro' => 'Across the borough.', 'items' => $items,
            ], $sectionOverrides),
            'sectionIndex' => 1, 'pageId' => 42, 'mode' => 'public',
            'emitMarkers' => $markers, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [],
            'profile' => ['watermark_enabled' => false, 'geo' => ['scope' => 'local']],
            'site' => null,
            'pagesBySlug' => ['service-1' => '/service-1'],
            'heroImages' => [], 'itemsById' => collect(),
        ], $extra);
    }

    private function render(bool $markers, array $sectionOverrides = [], array $extra = []): string
    {
        return View::make('site.sections.services', $this->vars($markers, $sectionOverrides, $extra))->render();
    }

    public function test_routes_and_splits_featured_four_from_unnumbered_tail(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="featured-ledger"', $html);
        // Featured ordinals 01-04 only; the tail carries no ordinals.
        foreach (['01', '02', '03', '04'] as $n) {
            $this->assertStringContainsString('>'.$n.'</span>', $html);
        }
        $this->assertStringNotContainsString('>05</span>', $html);
        $this->assertStringContainsString('Also covered', $html);
        $this->assertStringContainsString('Service 12', $html);
    }

    public function test_featured_count_option_is_honoured(): void
    {
        $html = $this->render(false, ['__options' => ['featured_count' => 2]]);

        $this->assertStringContainsString('>02</span>', $html);
        $this->assertStringNotContainsString('>03</span>', $html);
    }

    public function test_ghost_ordinals_use_clamped_display_scale_and_accent_mix(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('font-size: clamp(3.5rem, 7vw, 6.5rem)', $html);
        $this->assertStringContainsString('color-mix(in oklab, var(--brand-accent-text) 28%, transparent)', $html);
    }

    public function test_contrast_stamp_swaps_the_full_token_set(): void
    {
        $html = $this->render(false, ['__surface' => 'contrast']);

        $this->assertStringContainsString('background-color: var(--color-surface-contrast)', $html);
        $this->assertStringContainsString('color-mix(in oklab, var(--brand-accent-text-on-contrast) 28%, transparent)', $html);
        $this->assertStringNotContainsString('var(--brand-accent-text)"', $html);
        $this->assertStringNotContainsString('color-mix(in oklab, var(--color-text) ', $html);
    }

    public function test_rows_hover_and_link_via_resolver(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('group-hover:translate-x-1', $html);
        $this->assertStringContainsString('whitespace-nowrap">&#160;<span', $html, 'arrow must be glued to the last title word');
        $this->assertStringContainsString('items-start', $html, 'featured rows top-align the title against the ghost ordinal');
        $this->assertStringContainsString("after:absolute after:inset-0", $html, 'resolved rows are whole-row clickable');
    }

    public function test_stretched_link_is_absent_in_editor_mode(): void
    {
        $html = $this->render(true);

        $this->assertStringNotContainsString('after:absolute after:inset-0', $html, 'the overlay must not steal editor marker clicks');
        $this->assertStringContainsString('href="/service-1"', $html);
        $this->assertDoesNotMatchRegularExpression('/<a[^>]*>Service 2<\/a>/', $html);
    }

    public function test_hover_thumbnails_option_reveals_resolved_thumbs(): void
    {
        $html = $this->render(false, ['__options' => ['featured_count' => 4, 'hover_thumbnails' => true]], ['heroImages' => [
            'service-1' => ['url' => 'https://example.test/s1.jpg', 'watermark_url' => null],
            'service-2' => ['url' => 'https://example.test/s2.jpg', 'watermark_url' => null],
        ]]);

        $this->assertSame(2, substr_count($html, 'data-svc-thumb'), 'services 3-4 have no hero: no thumb slot');
        $this->assertStringContainsString('https://example.test/s1.jpg', $html);
        $this->assertStringContainsString('opacity-0 group-hover:opacity-100', $html);
    }

    public function test_hover_thumbnails_duplicate_urls_degrade_to_type_only(): void
    {
        $html = $this->render(false, ['__options' => ['hover_thumbnails' => true]], ['heroImages' => [
            'service-1' => ['url' => 'https://example.test/same.jpg', 'watermark_url' => null],
            'service-2' => ['url' => 'https://example.test/same.jpg', 'watermark_url' => null],
        ]]);

        $this->assertSame(0, substr_count($html, 'data-svc-thumb'));
        $this->assertStringNotContainsString('https://example.test/same.jpg', $html);
    }

    public function test_thumbs_are_absent_without_the_option(): void
    {
        $html = $this->render(false, [], ['heroImages' => [
            'service-1' => ['url' => 'https://example.test/s1.jpg', 'watermark_url' => null],
        ]]);

        $this->assertStringNotContainsString('data-svc-thumb', $html);
        $this->assertStringNotContainsString('https://example.test/s1.jpg', $html);
    }

    public function test_hover_thumbnails_recipe_option_validates(): void
    {
        $registry = app(\App\Services\Site\PageLayoutRegistry::class);
        $base = ['schema_version' => 1, 'variants' => ['services' => 'featured-ledger'], 'eyebrow_policy' => 'all', 'insert_sections' => []];

        $this->assertTrue($registry->isUsable($base + ['options' => ['hover_thumbnails' => true]], 'home'));
        $this->assertFalse($registry->isUsable($base + ['options' => ['hover_thumbnails' => 'yes']], 'home'));
    }

    public function test_emits_classic_editor_field_set_for_all_items(): void
    {
        $html = $this->render(true);

        foreach (['eyebrow', 'title', 'intro', 'items.0.title', 'items.0.body', 'items.0.icon', 'items.11.title', 'items.11.body', 'items.11.icon'] as $field) {
            $this->assertStringContainsString('data-editable-field="'.$field.'"', $html, "missing {$field}");
        }
    }
}

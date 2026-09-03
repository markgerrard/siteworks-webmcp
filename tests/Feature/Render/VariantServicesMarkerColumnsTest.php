<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantServicesMarkerColumnsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @param  list<array<string, mixed>>|null  $items
     * @return array<string, mixed>
     */
    private function servicesVars(string $variant, bool $markers, int $count = 6, array $sectionOverrides = [], ?array $items = null): array
    {
        if ($items === null) {
            $items = [];
            foreach (range(1, $count) as $n) {
                $items[] = ['icon' => 'hammer', 'title' => "Service {$n}", 'body' => "Body {$n}."];
            }
        }

        return [
            'section' => array_merge([
                'type' => 'services',
                'variant' => $variant,
                'title' => 'What We Do',
                'eyebrow' => 'Our Services',
                'intro' => 'Trade services across the borough.',
                'accent_word' => 'Do',
                'items' => $items,
            ], $sectionOverrides),
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
                'contact' => '/contact',
            ],
            'heroImages' => [],
            'itemsById' => collect(),
        ];
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @param  list<array<string, mixed>>|null  $items
     */
    private function render(bool $markers, int $count = 6, array $sectionOverrides = [], ?array $items = null): string
    {
        return View::make(
            'site.sections.services',
            $this->servicesVars('marker-columns', $markers, $count, $sectionOverrides, $items),
        )->render();
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @param  list<array<string, mixed>>|null  $items
     */
    private function renderClassic(bool $markers, int $count = 6, array $sectionOverrides = [], ?array $items = null): string
    {
        return View::make(
            'site.sections.services',
            $this->servicesVars('classic', $markers, $count, $sectionOverrides, $items),
        )->render();
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

    public function test_routes_to_marker_columns_and_keeps_all_content(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringContainsString('id="home-content"', $html);
        $this->assertStringContainsString('What We Do', $html);
        $this->assertStringContainsString('Our Services', $html);
        $this->assertStringContainsString('Trade services across the borough.', $html);
        $this->assertSame(6, preg_match_all('/>\+<\/span>/', $html));

        foreach (range(1, 6) as $n) {
            $this->assertStringContainsString("Service {$n}", $html);
            $this->assertStringContainsString("Body {$n}.", $html);
        }
    }

    public function test_three_items_render_all(): void
    {
        $html = $this->render(false, 3);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertSame(3, preg_match_all('/>\+<\/span>/', $html));

        foreach (range(1, 3) as $n) {
            $this->assertStringContainsString("Service {$n}", $html);
            $this->assertStringContainsString("Body {$n}.", $html);
        }
    }

    public function test_twelve_items_render_all_without_clamping(): void
    {
        $html = $this->render(false, 12);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertSame(12, preg_match_all('/>\+<\/span>/', $html));

        foreach (range(1, 12) as $n) {
            $this->assertStringContainsString("Service {$n}", $html, "missing title {$n}");
            $this->assertStringContainsString("Body {$n}.", $html, "missing body {$n}");
        }
    }

    public function test_featured_and_contact_cta_flatten_to_equal_marker_rows(): void
    {
        $items = [
            [
                'icon' => 'wrench',
                'title' => 'Boiler repair',
                'body' => 'Fast boiler fixes.',
                'featured' => true,
                'featured_label' => 'Most popular',
                'source_service' => 'Boiler Repair',
            ],
            [
                'icon' => 'bath',
                'title' => 'Bathroom fitting',
                'body' => 'Full bathroom refits.',
            ],
            [
                'title' => 'Talk to us',
                'body' => 'Book a visit.',
                'contact_cta' => true,
                'cta_label' => 'Get in touch',
            ],
        ];

        $html = $this->render(false, 3, [], $items);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertSame(3, preg_match_all('/>\+<\/span>/', $html));
        $this->assertStringContainsString('Boiler repair', $html);
        $this->assertStringContainsString('Fast boiler fixes.', $html);
        $this->assertStringContainsString('Bathroom fitting', $html);
        $this->assertStringContainsString('Talk to us', $html);
        $this->assertStringContainsString('Book a visit.', $html);

        $this->assertStringNotContainsString('Most popular', $html);
        $this->assertStringNotContainsString('Get in touch', $html);
        $this->assertStringNotContainsString('scale-[1.02]', $html);
        $this->assertStringNotContainsString('data-service-card-photo', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('min-width: 280px', $html);
        $this->assertStringNotContainsString('flex: 0 1', $html);
    }

    public function test_does_not_emit_ordinal_circles_or_padded_indexes(): void
    {
        $html = $this->render(false, 10);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertSame(10, preg_match_all('/>\+<\/span>/', $html));
        $this->assertStringNotContainsString('>01<', $html);
        $this->assertStringNotContainsString('>02<', $html);
        $this->assertStringNotContainsString('rounded-full', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*0[1-9]\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*\d+\.\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*\d+\s*</', $html);
    }

    public function test_uses_features_markers_chrome(): void
    {
        $html = $this->render(false, 6);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringContainsString('md:columns-2', $html);
        $this->assertStringContainsString('md:gap-x-16', $html);
        $this->assertStringContainsString('break-inside-avoid', $html);
        $this->assertStringContainsString('grid-cols-[1.6rem_1fr]', $html);
        $this->assertSame(2, substr_count($html, '28%, transparent'));
        $this->assertSame(4, substr_count($html, '12%, transparent'));
        $this->assertStringNotContainsString('md:grid-cols-2', $html);
        $this->assertStringNotContainsString('md:grid-cols-3', $html);
    }

    public function test_emits_at_least_classic_editor_fields(): void
    {
        $html = $this->render(true, 6);
        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);

        $classic = $this->editableFields($this->renderClassic(true, 6));
        $markers = $this->editableFields($html);

        $this->assertNotEmpty($classic);
        $missing = array_values(array_diff($classic, $markers));
        $this->assertSame([], $missing, 'marker-columns dropped classic fields: '.implode(', ', $missing));
    }

    public function test_twelve_items_emit_item_markers_for_every_row(): void
    {
        $html = $this->render(true, 12);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringContainsString('data-editable-field="eyebrow"', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('data-editable-field="intro"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.icon"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.body"', $html);
        $this->assertStringContainsString('data-editable-field="items.11.icon"', $html);
        $this->assertStringContainsString('data-editable-field="items.11.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.11.body"', $html);
    }

    public function test_suppressed_eyebrow_emits_hidden_marker(): void
    {
        $html = $this->render(true, 6, ['__suppress_eyebrow' => true]);

        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringNotContainsString('Our Services</span>', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('What We Do', $html);
    }

    public function test_stamped_surface_swaps_to_contrast_tokens(): void
    {
        $html = $this->render(false, 3, ['__surface' => 'contrast']);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface-contrast)', $html);
        $this->assertStringContainsString('var(--color-text-on-contrast)', $html);
        $this->assertStringContainsString('var(--color-text-muted-on-contrast)', $html);
        $this->assertStringNotContainsString('background-color: var(--color-surface-alt)', $html);
    }

    public function test_unstamped_wrapper_stays_on_surface_alt(): void
    {
        $html = $this->render(false, 3);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface-alt)', $html);
        $this->assertStringNotContainsString('var(--color-surface-contrast)', $html);
        $this->assertStringNotContainsString('bg-white', $html);
        $this->assertStringContainsString('site-section-spacing', $html);
    }
}

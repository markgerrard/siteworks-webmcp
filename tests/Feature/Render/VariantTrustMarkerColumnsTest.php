<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantTrustMarkerColumnsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @return array<string, mixed>
     */
    private function trustVars(string $variant, bool $markers, int $count = 3, array $sectionOverrides = []): array
    {
        $items = [];
        foreach (range(1, $count) as $n) {
            $items[] = ['title' => "Signal {$n}", 'body' => "Proof {$n}."];
        }

        return [
            'section' => array_merge([
                'type' => 'trust',
                'variant' => $variant,
                'title' => 'Why homeowners pick us',
                'eyebrow' => 'Why Choose Us',
                'items' => $items,
            ], $sectionOverrides),
            'sectionIndex' => 2,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => $markers,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     */
    private function render(bool $markers, int $count = 3, array $sectionOverrides = []): string
    {
        return View::make('site.sections.trust', $this->trustVars('marker-columns', $markers, $count, $sectionOverrides))->render();
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     */
    private function renderClassic(bool $markers, int $count = 3, array $sectionOverrides = []): string
    {
        return View::make('site.sections.trust', $this->trustVars('classic', $markers, $count, $sectionOverrides))->render();
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
        $this->assertStringContainsString('Why homeowners pick us', $html);
        $this->assertStringContainsString('Why Choose Us', $html);
        $this->assertSame(3, preg_match_all('/>\+<\/span>/', $html));

        foreach (range(1, 3) as $n) {
            $this->assertStringContainsString("Signal {$n}", $html);
            $this->assertStringContainsString("Proof {$n}.", $html);
        }
    }

    public function test_renders_all_items_classic_would_clamp(): void
    {
        $html = $this->render(false, 12);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertSame(12, preg_match_all('/>\+<\/span>/', $html));

        foreach (range(1, 12) as $n) {
            $this->assertStringContainsString("Signal {$n}", $html, "missing title {$n}");
            $this->assertStringContainsString("Proof {$n}.", $html, "missing body {$n}");
        }

        $classic = $this->renderClassic(false, 12);
        $this->assertStringNotContainsString('Signal 4', $classic);
        $this->assertStringContainsString('Signal 4', $html);
    }

    public function test_three_items_render_all(): void
    {
        $html = $this->render(false, 3);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertSame(3, preg_match_all('/>\+<\/span>/', $html));

        foreach (range(1, 3) as $n) {
            $this->assertStringContainsString("Signal {$n}", $html);
            $this->assertStringContainsString("Proof {$n}.", $html);
        }
    }

    public function test_does_not_emit_ordinals_or_circles(): void
    {
        $html = $this->render(false, 10);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertSame(10, preg_match_all('/>\+<\/span>/', $html));
        $this->assertStringNotContainsString('>01<', $html);
        $this->assertStringNotContainsString('>02<', $html);
        $this->assertStringNotContainsString('tabular-nums', $html);
        $this->assertStringNotContainsString('rounded-full', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*0[1-9]\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*\d+\.\s*</', $html);
    }

    public function test_reuses_markers_chrome_columns_and_cluster_dividers(): void
    {
        $html = $this->render(false, 6);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringContainsString('md:columns-2', $html);
        $this->assertStringContainsString('md:gap-x-16', $html);
        $this->assertStringContainsString('break-inside-avoid', $html);
        $this->assertStringContainsString('grid-cols-[1.6rem_1fr]', $html);
        $this->assertStringContainsString('color-mix(in oklab, var(--color-text) 28%', $html);
        $this->assertStringContainsString('color-mix(in oklab, var(--color-text) 12%', $html);
    }

    public function test_emits_at_least_classic_editor_fields(): void
    {
        $html = $this->render(true, 3);
        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);

        $classic = $this->editableFields($this->renderClassic(true, 3));
        $actual = $this->editableFields($html);

        $this->assertNotEmpty($classic);
        foreach ($classic as $field) {
            $this->assertContains($field, $actual, "marker-columns missing classic field {$field}");
        }
    }

    public function test_twelve_items_emit_item_markers_classic_would_clamp(): void
    {
        $html = $this->render(true, 12);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringContainsString('data-editable-field="eyebrow"', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.body"', $html);
        $this->assertStringContainsString('data-editable-field="items.11.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.11.body"', $html);
    }

    public function test_title_matching_eyebrow_self_suppresses_like_classic(): void
    {
        $html = $this->render(false, 3, ['title' => 'Why Choose Us']);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertSame(
            1,
            substr_count($html, 'Why Choose Us'),
            'the default eyebrow must not double a title that already reads "Why Choose Us"',
        );
    }

    public function test_suppressed_eyebrow_emits_hidden_marker(): void
    {
        $html = $this->render(true, 3, ['__suppress_eyebrow' => true]);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringNotContainsString('Why Choose Us</span>', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('Why homeowners pick us', $html);
    }

    public function test_honours_surface_contrast(): void
    {
        $html = $this->render(false, 3, ['__surface' => 'contrast']);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface-contrast)', $html);
        $this->assertStringContainsString('var(--color-text-on-contrast)', $html);
        $this->assertStringContainsString('var(--color-text-muted-on-contrast)', $html);
        $this->assertStringContainsString('site-section-spacing', $html);
        $this->assertStringNotContainsString('background-color: var(--color-surface-alt)', $html);
    }

    public function test_absent_surface_uses_kit_surface_token(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface)', $html);
        $this->assertStringNotContainsString('var(--color-surface-contrast)', $html);
        $this->assertStringNotContainsString('bg-white', $html);
        $this->assertStringContainsString('pt-10 lg:pt-12', $html);
    }

    public function test_collapses_to_single_column_below_md(): void
    {
        $html = $this->render(false, 6);

        $this->assertStringContainsString('data-svc-variant="marker-columns"', $html);
        $this->assertStringContainsString('md:columns-2', $html);
        $this->assertDoesNotMatchRegularExpression('/(?<!md:)columns-2/', $html);
        $this->assertStringNotContainsString('md:grid-cols-2', $html);
        $this->assertStringNotContainsString('md:grid-cols-3', $html);
    }
}

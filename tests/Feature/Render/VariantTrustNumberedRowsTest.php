<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantTrustNumberedRowsTest extends TestCase
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
        return View::make('site.sections.trust', $this->trustVars('numbered-rows', $markers, $count, $sectionOverrides))->render();
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

    public function test_routes_to_numbered_rows_and_keeps_all_content(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('Why homeowners pick us', $html);
        $this->assertStringContainsString('Why Choose Us', $html);
        $this->assertStringContainsString('>01<', $html);
        $this->assertStringContainsString('>03<', $html);

        foreach (range(1, 3) as $n) {
            $this->assertStringContainsString("Signal {$n}", $html);
            $this->assertStringContainsString("Proof {$n}.", $html);
        }
    }

    public function test_renders_all_items_classic_would_clamp(): void
    {
        $html = $this->render(false, 6);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('>01<', $html);
        $this->assertStringContainsString('>06<', $html);

        foreach (range(1, 6) as $n) {
            $this->assertStringContainsString("Signal {$n}", $html, "missing title {$n}");
            $this->assertStringContainsString("Proof {$n}.", $html, "missing body {$n}");
        }

        $classic = $this->renderClassic(false, 6);
        $this->assertStringNotContainsString('Signal 4', $classic);
        $this->assertStringContainsString('Signal 4', $html);
    }

    public function test_two_items_render_without_error(): void
    {
        $html = $this->render(false, 2);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('>01<', $html);
        $this->assertStringContainsString('>02<', $html);
        $this->assertStringContainsString('Signal 1', $html);
        $this->assertStringContainsString('Signal 2', $html);
        $this->assertStringContainsString('Proof 1.', $html);
        $this->assertStringContainsString('Proof 2.', $html);
    }

    public function test_emits_at_least_classic_editor_fields(): void
    {
        $html = $this->render(true, 3);
        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);

        $classic = $this->editableFields($this->renderClassic(true, 3));
        $actual = $this->editableFields($html);

        $this->assertNotEmpty($classic);
        foreach ($classic as $field) {
            $this->assertContains($field, $actual, "numbered-rows missing classic field {$field}");
        }
    }

    public function test_six_items_emit_item_markers_classic_would_clamp(): void
    {
        $html = $this->render(true, 6);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('data-editable-field="eyebrow"', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.body"', $html);
        $this->assertStringContainsString('data-editable-field="items.5.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.5.body"', $html);
    }

    public function test_suppressed_eyebrow_emits_hidden_marker(): void
    {
        $html = $this->render(true, 3, ['__suppress_eyebrow' => true]);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringNotContainsString('Why Choose Us</span>', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('Why homeowners pick us', $html);
    }

    public function test_title_matching_eyebrow_self_suppresses_like_classic(): void
    {
        $html = $this->render(false, 3, ['title' => 'Why Choose Us']);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertSame(
            1,
            substr_count($html, 'Why Choose Us'),
            'the default eyebrow must not double a title that already reads "Why Choose Us"',
        );
    }

    public function test_does_not_emit_ordinal_circles(): void
    {
        $html = $this->render(false, 4);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('tabular-nums', $html);
        $this->assertStringNotContainsString('rounded-full', $html);
        $this->assertStringNotContainsString('w-20 h-20 rounded-full', $html);
    }

    public function test_reuses_numbered_row_chrome(): void
    {
        $html = $this->render(false, 3);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('grid-cols-[3.5rem_1fr]', $html);
        $this->assertStringContainsString('md:grid-cols-[5rem_minmax(0,1fr)_minmax(0,1.4fr)]', $html);
        $this->assertStringContainsString('font-light tabular-nums', $html);
    }

    public function test_honours_surface_contrast(): void
    {
        $html = $this->render(false, 3, ['__surface' => 'contrast']);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface-contrast)', $html);
        $this->assertStringContainsString('var(--color-text-on-contrast)', $html);
        $this->assertStringContainsString('var(--color-text-muted-on-contrast)', $html);
        $this->assertStringContainsString('site-section-spacing', $html);
        $this->assertStringNotContainsString('background-color: var(--color-surface-alt)', $html);
    }

    public function test_absent_surface_uses_kit_surface_token(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface)', $html);
        $this->assertStringNotContainsString('var(--color-surface-contrast)', $html);
        $this->assertStringNotContainsString('bg-white', $html);
        $this->assertStringContainsString('pt-10 lg:pt-12', $html);
    }

    public function test_collapses_to_single_content_column_below_md(): void
    {
        $html = $this->render(false, 3);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('grid-cols-[3.5rem_1fr]', $html);
        $this->assertStringContainsString('md:grid-cols-[5rem_minmax(0,1fr)_minmax(0,1.4fr)]', $html);
        $this->assertStringNotContainsString('md:columns-2', $html);
        $this->assertStringNotContainsString('md:grid-cols-3', $html);
    }
}

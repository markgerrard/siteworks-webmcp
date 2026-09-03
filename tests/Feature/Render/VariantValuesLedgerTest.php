<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantValuesLedgerTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @param  array<string, mixed>|string|null  $heroImageUrl
     * @param  array<string, mixed>|string|null  $bandImageUrl
     * @return array<string, mixed>
     */
    private function valuesVars(string $variant, bool $markers, int $count = 5, array $sectionOverrides = [], mixed $heroImageUrl = 'https://example.test/hero.jpg', mixed $bandImageUrl = null): array
    {
        $items = [];
        foreach (range(1, $count) as $n) {
            $items[] = ['title' => "Value {$n}", 'body' => "Conviction {$n}."];
        }

        return [
            'section' => array_merge([
                'type' => 'values',
                'variant' => $variant,
                'title' => 'What We Stand For',
                'eyebrow' => 'Our Values',
                'items' => $items,
            ], $sectionOverrides),
            'sectionIndex' => 2,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => $markers,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'heroImageUrl' => $heroImageUrl,
            'bandImageUrl' => $bandImageUrl,
            'profile' => ['watermark_enabled' => false],
        ];
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @param  array<string, mixed>|string|null  $heroImageUrl
     * @param  array<string, mixed>|string|null  $bandImageUrl
     */
    private function render(bool $markers, int $count = 5, array $sectionOverrides = [], mixed $heroImageUrl = 'https://example.test/hero.jpg', mixed $bandImageUrl = null): string
    {
        return View::make('site.sections.values', $this->valuesVars('ledger', $markers, $count, $sectionOverrides, $heroImageUrl, $bandImageUrl))->render();
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     */
    private function renderClassic(bool $markers, int $count = 5, array $sectionOverrides = []): string
    {
        return View::make('site.sections.values', $this->valuesVars('classic', $markers, $count, $sectionOverrides))->render();
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

    public function test_routes_to_ledger_and_keeps_all_content(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="ledger"', $html);
        $this->assertStringContainsString('What We Stand For', $html);
        $this->assertStringContainsString('Our Values', $html);

        foreach (range(1, 5) as $n) {
            $this->assertStringContainsString("Value {$n}", $html);
            $this->assertStringContainsString("Conviction {$n}.", $html);
        }
    }

    public function test_renders_two_through_eight_items_without_clamping(): void
    {
        foreach (range(2, 8) as $count) {
            $html = $this->render(false, $count);

            $this->assertStringContainsString('data-svc-variant="ledger"', $html, "count {$count}");

            foreach (range(1, $count) as $n) {
                $this->assertStringContainsString("Value {$n}", $html, "count {$count} missing title {$n}");
                $this->assertStringContainsString("Conviction {$n}.", $html, "count {$count} missing body {$n}");
            }
        }
    }

    public function test_numbered_rows_intro_and_optional_portrait(): void
    {
        $html = $this->render(false, 3);
        $this->assertStringContainsString('>01<', $html, 'ledger numbers rows like the service page (override of the ordinal ban)');
        $this->assertStringContainsString('>03<', $html);
        $withIntro = $this->render(false, 3, ['intro' => 'Why these matter.']);
        $this->assertStringContainsString('Why these matter.', $withIntro);
        $portrait = $this->render(false, 3, ['__options' => ['side_image' => true]]);
        $this->assertStringContainsString('aspect-ratio: 3 / 4', $portrait, 'portrait pane when side_image on');
        $this->assertStringNotContainsString('aspect-ratio: 3 / 4', $html, 'no portrait by default');
    }

    public function test_emits_at_least_classic_editor_fields(): void
    {
        $html = $this->render(true, 5);
        $this->assertStringContainsString('data-svc-variant="ledger"', $html);

        $classic = $this->editableFields($this->renderClassic(true, 5));
        $ledger = $this->editableFields($html);

        $this->assertNotEmpty($classic);
        foreach ($classic as $field) {
            $this->assertContains($field, $ledger, "ledger missing classic field {$field}");
        }
    }

    public function test_eight_items_emit_item_markers_classic_would_clamp(): void
    {
        $html = $this->render(true, 8);

        $this->assertStringContainsString('data-editable-field="items.0.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.body"', $html);
        $this->assertStringContainsString('data-editable-field="items.7.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.7.body"', $html);
    }

    public function test_suppressed_eyebrow_emits_hidden_marker(): void
    {
        $html = $this->render(true, 5, ['__suppress_eyebrow' => true]);

        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringNotContainsString('Our Values</span>', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('What We Stand For', $html);
    }

    public function test_uses_token_surface_and_ledger_grid(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="ledger"', $html);
        $this->assertStringContainsString('var(--color-surface)', $html);
        $this->assertStringNotContainsString('bg-white', $html);
        $this->assertStringContainsString('grid-cols-1', $html);
        $this->assertStringContainsString('md:grid-cols-[5rem_minmax(0,1fr)_minmax(0,1.4fr)]', $html);
    }

    public function test_portrait_prefers_band_image_over_hero_fallback(): void
    {
        $html = $this->render(false, 3, ['__options' => ['side_image' => true]], 'https://example.test/hero.jpg', 'https://example.test/band.jpg');

        $this->assertStringContainsString('src="https://example.test/band.jpg"', $html);
        $this->assertStringNotContainsString('https://example.test/hero.jpg', $html);
        $this->assertStringContainsString('aspect-ratio: 3 / 4', $html);
    }

    public function test_portrait_falls_back_to_hero_when_band_absent(): void
    {
        $html = $this->render(false, 3, ['__options' => ['side_image' => true]], 'https://example.test/hero.jpg', null);

        $this->assertStringContainsString('src="https://example.test/hero.jpg"', $html);
        $this->assertStringContainsString('aspect-ratio: 3 / 4', $html);
    }

    public function test_portrait_stays_off_when_side_image_disabled_even_with_band(): void
    {
        $html = $this->render(false, 3, [], 'https://example.test/hero.jpg', 'https://example.test/band.jpg');

        $this->assertStringNotContainsString('aspect-ratio: 3 / 4', $html);
        $this->assertStringNotContainsString('https://example.test/band.jpg', $html);
    }
}

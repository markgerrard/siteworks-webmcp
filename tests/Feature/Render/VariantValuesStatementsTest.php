<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantValuesStatementsTest extends TestCase
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
    private function render(bool $markers = false, int $count = 5, array $sectionOverrides = [], mixed $heroImageUrl = 'https://example.test/hero.jpg', mixed $bandImageUrl = null): string
    {
        return View::make('site.sections.values', $this->valuesVars('statements', $markers, $count, $sectionOverrides, $heroImageUrl, $bandImageUrl))->render();
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

    public function test_three_items_emit_variant_tag_and_copy(): void
    {
        $html = $this->render(false, 3);

        $this->assertStringContainsString('data-svc-variant="statements"', $html);
        $this->assertStringContainsString('What We Stand For', $html);
        $this->assertStringContainsString('Our Values', $html);

        foreach (range(1, 3) as $n) {
            $this->assertStringContainsString("Value {$n}", $html);
            $this->assertStringContainsString("Conviction {$n}.", $html);
        }
    }

    public function test_five_items_emit_variant_tag_and_copy(): void
    {
        $html = $this->render(false, 5);

        $this->assertStringContainsString('data-svc-variant="statements"', $html);
        $this->assertStringContainsString('What We Stand For', $html);
        $this->assertStringContainsString('Our Values', $html);

        foreach (range(1, 5) as $n) {
            $this->assertStringContainsString("Value {$n}", $html);
            $this->assertStringContainsString("Conviction {$n}.", $html);
        }
    }

    public function test_does_not_emit_ordinal_markers(): void
    {
        $html = $this->render(false, 5);

        $this->assertStringContainsString('data-svc-variant="statements"', $html);
        $this->assertStringNotContainsString('>01<', $html);
        $this->assertStringNotContainsString('>02<', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*0[1-9]\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*\d+\.\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*[1-8]\s*</', $html);
    }

    public function test_emits_at_least_classic_editor_fields(): void
    {
        $html = $this->render(true, 5);
        $this->assertStringContainsString('data-svc-variant="statements"', $html);

        $classic = $this->editableFields($this->renderClassic(true, 5));
        $statements = $this->editableFields($html);

        $this->assertNotEmpty($classic);
        foreach ($classic as $field) {
            $this->assertContains($field, $statements, "statements missing classic field {$field}");
        }
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

    public function test_band_splits_only_when_image_present(): void
    {
        $with = $this->render();
        $this->assertStringContainsString('lg:grid-cols-2', $with, 'image present: band splits');
        $this->assertStringContainsString('data-svc-media', $with);
        $without = $this->render(false, 5, [], null);
        $this->assertStringNotContainsString('lg:grid-cols-2', $without, 'no image: full-width list');
        $this->assertStringNotContainsString('data-svc-media', $without);
    }

    public function test_band_uses_checklist_chrome(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('var(--color-surface-alt)', $html, 'elevated band surface');
        $this->assertStringContainsString('rounded-full', $html, 'brand check circles');
        $this->assertStringContainsString('shadow-xl', $html);
        $this->assertStringNotContainsString('bg-white', $html);
    }

    public function test_band_prefers_band_image_over_hero_fallback(): void
    {
        $html = $this->render(false, 5, [], 'https://example.test/hero.jpg', 'https://example.test/band.jpg');

        $this->assertStringContainsString('src="https://example.test/band.jpg"', $html);
        $this->assertStringNotContainsString('https://example.test/hero.jpg', $html);
        $this->assertStringContainsString('data-svc-media', $html);
    }

    public function test_band_falls_back_to_hero_when_band_absent(): void
    {
        $html = $this->render(false, 5, [], 'https://example.test/hero.jpg', null);

        $this->assertStringContainsString('src="https://example.test/hero.jpg"', $html);
        $this->assertStringContainsString('data-svc-media', $html);
    }
}

<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantProcessChecklistStepsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @param  array<string, mixed>  $viewOverrides
     * @return array<string, mixed>
     */
    private function processVars(string $variant, bool $markers, int $count = 4, array $sectionOverrides = [], array $viewOverrides = []): array
    {
        $items = $sectionOverrides['items'] ?? $this->defaultItems($count);
        unset($sectionOverrides['items']);

        return array_merge([
            'section' => array_merge([
                'type' => 'process',
                'variant' => $variant,
                'title' => 'How it works',
                'eyebrow' => 'Our Process',
                'items' => $items,
            ], $sectionOverrides),
            'sectionIndex' => 3,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => $markers,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'profile' => ['watermark_enabled' => false],
            'heroImageUrl' => 'https://example.test/hero.jpg',
            'bandImageUrl' => 'https://example.test/band.jpg',
            'introImageUrl' => 'https://example.test/intro.jpg',
        ], $viewOverrides);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defaultItems(int $count): array
    {
        $items = [];
        foreach (range(1, $count) as $n) {
            $items[] = [
                'step' => $n,
                'title' => "Step title {$n}",
                'body' => "Step body {$n}.",
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @param  array<string, mixed>  $viewOverrides
     */
    private function render(bool $markers = false, int $count = 4, array $sectionOverrides = [], array $viewOverrides = []): string
    {
        return View::make('site.sections.process', $this->processVars('checklist-steps', $markers, $count, $sectionOverrides, $viewOverrides))->render();
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     */
    private function renderClassic(bool $markers, int $count = 4, array $sectionOverrides = []): string
    {
        return View::make('site.sections.process', $this->processVars('classic', $markers, $count, $sectionOverrides))->render();
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

    public function test_four_items_emit_variant_tag_and_copy(): void
    {
        $html = $this->render(false, 4);

        $this->assertStringContainsString('data-svc-variant="checklist-steps"', $html);
        $this->assertStringContainsString('How it works', $html);
        $this->assertStringContainsString('Our Process', $html);

        foreach (range(1, 4) as $n) {
            $this->assertStringContainsString("Step title {$n}", $html);
            $this->assertStringContainsString("Step body {$n}.", $html);
            $this->assertStringContainsString((string) $n, $html, "missing step copy {$n}");
        }
    }

    public function test_six_items_render_all_without_clamping(): void
    {
        $html = $this->render(false, 6);

        $this->assertStringContainsString('data-svc-variant="checklist-steps"', $html);
        $this->assertSame(6, preg_match_all('/M5 13l4 4L19 7/', $html));

        foreach (range(1, 6) as $n) {
            $this->assertStringContainsString("Step title {$n}", $html, "missing title {$n}");
            $this->assertStringContainsString("Step body {$n}.", $html, "missing body {$n}");
        }
    }

    public function test_circles_hold_checks_not_ordinals(): void
    {
        $html = $this->render(false, 4);

        $this->assertStringContainsString('data-svc-variant="checklist-steps"', $html);
        $this->assertStringContainsString('rounded-full', $html);
        $this->assertStringContainsString('w-6 h-6', $html);
        $this->assertSame(4, preg_match_all('/M5 13l4 4L19 7/', $html));
        $this->assertStringNotContainsString('w-20 h-20', $html);
        $this->assertStringNotContainsString('lg:grid-cols-4', $html);
        $this->assertStringNotContainsString('text-2xl font-bold shadow-lg', $html);
        $this->assertDoesNotMatchRegularExpression('/w-6 h-6 rounded-full[^>]*>\s*(?:0?\d)\s*</', $html);
        $this->assertStringNotContainsString('tabular-nums', $html);
    }

    public function test_keeps_step_copy_outside_the_check_circles(): void
    {
        $html = $this->render(false, 4, [
            'items' => [
                ['step' => 'Survey', 'title' => 'Book a survey', 'body' => 'We visit at a time that suits you.'],
                ['step' => 2, 'title' => 'Receive your quote', 'body' => 'Detailed quote within 24h.'],
            ],
        ]);

        $this->assertStringContainsString('data-svc-variant="checklist-steps"', $html);
        $this->assertStringContainsString('Survey', $html);
        $this->assertStringContainsString('Book a survey', $html);
        $this->assertStringContainsString('Receive your quote', $html);
        $this->assertMatchesRegularExpression('/>\s*2\s*</', $html);
        $this->assertStringContainsString('M5 13l4 4L19 7', $html);
        $this->assertDoesNotMatchRegularExpression('/w-6 h-6 rounded-full[^>]*>\s*Survey\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/w-6 h-6 rounded-full[^>]*>\s*2\s*</', $html);
    }

    public function test_missing_step_does_not_invent_ledger_or_circle_numbers(): void
    {
        $html = $this->render(false, 1, [
            'items' => [
                ['title' => 'Book a survey', 'body' => 'We visit at a time that suits you.'],
            ],
        ]);

        $this->assertStringContainsString('data-svc-variant="checklist-steps"', $html);
        $this->assertStringContainsString('Book a survey', $html);
        $this->assertStringContainsString('M5 13l4 4L19 7', $html);
        $this->assertStringNotContainsString('>01<', $html);
        $this->assertStringNotContainsString('>02<', $html);
        $this->assertStringNotContainsString('tabular-nums', $html);
    }

    public function test_emits_at_least_classic_editor_fields(): void
    {
        $html = $this->render(true, 4);
        $this->assertStringContainsString('data-svc-variant="checklist-steps"', $html);

        $classic = $this->editableFields($this->renderClassic(true, 4));
        $steps = $this->editableFields($html);

        $this->assertNotEmpty($classic);
        foreach ($classic as $field) {
            $this->assertContains($field, $steps, "checklist-steps missing classic field {$field}");
        }
    }

    public function test_six_items_emit_item_markers_including_step(): void
    {
        $html = $this->render(true, 6);

        $this->assertStringContainsString('data-svc-variant="checklist-steps"', $html);
        $this->assertStringContainsString('data-editable-field="eyebrow"', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.step"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.body"', $html);
        $this->assertStringContainsString('data-editable-field="items.5.step"', $html);
        $this->assertStringContainsString('data-editable-field="items.5.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.5.body"', $html);
    }

    public function test_suppressed_eyebrow_emits_hidden_marker(): void
    {
        $html = $this->render(true, 4, ['__suppress_eyebrow' => true]);

        $this->assertStringContainsString('data-svc-variant="checklist-steps"', $html);
        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringNotContainsString('Our Process</span>', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('How it works', $html);
    }

    public function test_empty_title_keeps_hidden_title_and_eyebrow_markers(): void
    {
        $html = $this->render(true, 4, ['title' => '']);

        $this->assertStringContainsString('data-svc-variant="checklist-steps"', $html);
        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="title"/',
            $html,
        );
        $this->assertStringContainsString('Step title 1', $html);
        $this->assertStringNotContainsString('How it works', $html);
    }

    public function test_matching_title_does_not_hide_process_eyebrow(): void
    {
        $html = $this->render(false, 4, ['title' => 'Our Process']);

        $this->assertStringContainsString('data-svc-variant="checklist-steps"', $html);
        $this->assertSame(2, substr_count($html, 'Our Process'));
        $this->assertStringNotContainsString('class="hidden"', $html);
    }

    public function test_band_uses_checklist_chrome(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data-svc-variant="checklist-steps"', $html);
        $this->assertStringContainsString('var(--color-surface-alt)', $html, 'elevated band surface');
        $this->assertStringContainsString('rounded-full', $html, 'brand check circles');
        $this->assertStringContainsString('w-6 h-6', $html);
        $this->assertStringContainsString('shadow-xl', $html);
        $this->assertStringContainsString('grid-cols-[2rem_1fr]', $html);
        $this->assertStringContainsString('var(--brand-primary)', $html);
        $this->assertStringNotContainsString('bg-white', $html);
        $this->assertStringNotContainsString('md:columns-2', $html);
    }

    public function test_body_renders_through_rich_html(): void
    {
        $html = $this->render(false, 1, [
            'items' => [
                ['step' => 1, 'title' => 'Book a survey', 'body' => "First line.\n\nSecond paragraph."],
            ],
        ]);

        $this->assertStringContainsString('data-svc-variant="checklist-steps"', $html);
        $this->assertStringContainsString('<p>First line.</p>', $html);
        $this->assertStringContainsString('<p>Second paragraph.</p>', $html);
        $this->assertStringContainsString('data-editable-type="rich"', $this->render(true, 1, [
            'items' => [
                ['step' => 1, 'title' => 'Book a survey', 'body' => "First line.\n\nSecond paragraph."],
            ],
        ]));
    }

    public function test_never_renders_images_in_v1(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data-svc-variant="checklist-steps"', $html);
        $this->assertStringNotContainsString('data-svc-media', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<figure', $html);
        $this->assertStringNotContainsString('lg:grid-cols-2', $html);
        $this->assertStringNotContainsString('https://example.test/hero.jpg', $html);
        $this->assertStringNotContainsString('https://example.test/band.jpg', $html);
        $this->assertStringNotContainsString('https://example.test/intro.jpg', $html);
        $this->assertStringNotContainsString('image_radius', $html);
        $this->assertStringNotContainsString('--radius-card', $html);
    }

    public function test_uses_token_surface_not_bg_white(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="checklist-steps"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface)', $html);
        $this->assertStringNotContainsString('bg-white', $html);
        $this->assertStringNotContainsString('background-color: var(--color-surface-contrast)', $html);
    }

    public function test_stamped_surface_contrast_swaps_wrapper_tokens(): void
    {
        $html = $this->render(false, 4, ['__surface' => 'contrast']);

        $this->assertStringContainsString('data-svc-variant="checklist-steps"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface-contrast)', $html);
        $this->assertStringContainsString('var(--color-text-on-contrast)', $html);
        $this->assertStringContainsString('var(--color-surface-alt)', $html, 'elevated card stays alt on the contrast band');
        $this->assertStringContainsString('site-section-spacing', $html);
        $this->assertStringNotContainsString('pt-10 lg:pt-12', $html);
    }

    public function test_mobile_stack_uses_single_column_chrome(): void
    {
        $html = $this->render(false, 4);

        $this->assertStringContainsString('data-svc-variant="checklist-steps"', $html);
        $this->assertStringContainsString('grid-cols-1', $html);
        $this->assertStringContainsString('px-7 py-10 lg:px-12 lg:py-12', $html);
        $this->assertStringNotContainsString('lg:grid-cols-4', $html);
        $this->assertStringNotContainsString('md:grid-cols-2', $html);
        $this->assertStringNotContainsString('md:grid-cols-3', $html);
        $this->assertStringNotContainsString('md:columns-2', $html);
        $this->assertStringNotContainsString('@media (max-width: 639px)', $html);
    }
}

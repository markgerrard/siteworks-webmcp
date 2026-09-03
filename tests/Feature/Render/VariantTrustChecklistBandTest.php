<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantTrustChecklistBandTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @param  array<string, mixed>  $viewOverrides
     * @return array<string, mixed>
     */
    private function trustVars(string $variant, bool $markers, int $count = 5, array $sectionOverrides = [], array $viewOverrides = []): array
    {
        $items = [];
        foreach (range(1, $count) as $n) {
            $items[] = ['title' => "Signal {$n}", 'body' => "Reason {$n}."];
        }

        return array_merge([
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
            'profile' => ['watermark_enabled' => false],
            'heroImageUrl' => 'https://example.test/hero.jpg',
            'bandImageUrl' => 'https://example.test/band.jpg',
            'introImageUrl' => 'https://example.test/intro.jpg',
        ], $viewOverrides);
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @param  array<string, mixed>  $viewOverrides
     */
    private function render(bool $markers = false, int $count = 5, array $sectionOverrides = [], array $viewOverrides = []): string
    {
        return View::make('site.sections.trust', $this->trustVars('checklist-band', $markers, $count, $sectionOverrides, $viewOverrides))->render();
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     */
    private function renderClassic(bool $markers, int $count = 5, array $sectionOverrides = []): string
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

    public function test_three_items_emit_variant_tag_and_copy(): void
    {
        $html = $this->render(false, 3);

        $this->assertStringContainsString('data-svc-variant="checklist-band"', $html);
        $this->assertStringContainsString('Why homeowners pick us', $html);
        $this->assertStringContainsString('Why Choose Us', $html);

        foreach (range(1, 3) as $n) {
            $this->assertStringContainsString("Signal {$n}", $html);
            $this->assertStringContainsString("Reason {$n}.", $html);
        }
    }

    public function test_five_items_render_all_without_clamping(): void
    {
        $html = $this->render(false, 5);

        $this->assertStringContainsString('data-svc-variant="checklist-band"', $html);
        $this->assertSame(5, preg_match_all('/M5 13l4 4L19 7/', $html));

        foreach (range(1, 5) as $n) {
            $this->assertStringContainsString("Signal {$n}", $html, "missing title {$n}");
            $this->assertStringContainsString("Reason {$n}.", $html, "missing body {$n}");
        }

        $classic = $this->renderClassic(false, 5);
        $this->assertStringNotContainsString('Signal 4', $classic);
        $this->assertStringNotContainsString('Signal 5', $classic);
    }

    public function test_does_not_emit_ordinal_markers_or_circles(): void
    {
        $html = $this->render(false, 5);

        $this->assertStringContainsString('data-svc-variant="checklist-band"', $html);
        $this->assertStringContainsString('rounded-full', $html);
        $this->assertStringNotContainsString('>01<', $html);
        $this->assertStringNotContainsString('>02<', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*0[1-9]\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*\d+\.\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*[1-8]\s*</', $html);
        $this->assertStringNotContainsString('w-14 h-14', $html);
        $this->assertStringNotContainsString('trust-item-card', $html);
    }

    public function test_emits_at_least_classic_editor_fields(): void
    {
        $html = $this->render(true, 5);
        $this->assertStringContainsString('data-svc-variant="checklist-band"', $html);

        $classic = $this->editableFields($this->renderClassic(true, 5));
        $band = $this->editableFields($html);

        $this->assertNotEmpty($classic);
        foreach ($classic as $field) {
            $this->assertContains($field, $band, "checklist-band missing classic field {$field}");
        }
    }

    public function test_five_items_emit_item_markers_classic_would_clamp(): void
    {
        $html = $this->render(true, 5);

        $this->assertStringContainsString('data-svc-variant="checklist-band"', $html);
        $this->assertStringContainsString('data-editable-field="eyebrow"', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.body"', $html);
        $this->assertStringContainsString('data-editable-field="items.4.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.4.body"', $html);
    }

    public function test_suppressed_eyebrow_emits_hidden_marker(): void
    {
        $html = $this->render(true, 5, ['__suppress_eyebrow' => true]);

        $this->assertStringContainsString('data-svc-variant="checklist-band"', $html);
        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringNotContainsString('Why Choose Us</span>', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('Why homeowners pick us', $html);
    }

    public function test_band_uses_checklist_chrome(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data-svc-variant="checklist-band"', $html);
        $this->assertStringContainsString('var(--color-surface-alt)', $html, 'elevated band surface');
        $this->assertStringContainsString('rounded-full', $html, 'brand check circles');
        $this->assertStringContainsString('w-6 h-6', $html);
        $this->assertStringContainsString('shadow-xl', $html);
        $this->assertStringContainsString('grid-cols-[2rem_1fr]', $html);
        $this->assertStringContainsString('var(--brand-primary)', $html);
        $this->assertStringNotContainsString('bg-white', $html);
        $this->assertStringNotContainsString('md:columns-2', $html);
    }

    public function test_band_slot_image_splits_the_card_like_features_checklist(): void
    {
        // v1's no-image rule was overridden: the home
        // checklist pairs the card with the page's band/intro slot image,
        // mirroring features/checklist. Band slot wins over intro.
        $html = $this->render();

        $this->assertStringContainsString('data-svc-variant="checklist-band"', $html);
        $this->assertStringContainsString('data-svc-media', $html);
        $this->assertStringContainsString('lg:grid-cols-2', $html);
        $this->assertStringContainsString('https://example.test/band.jpg', $html);
        $this->assertStringNotContainsString('https://example.test/intro.jpg', $html);
        $this->assertStringNotContainsString('https://example.test/hero.jpg', $html);
    }

    public function test_intro_slot_fills_the_image_pane_when_band_slot_is_empty(): void
    {
        $html = $this->render(false, 5, [], ['bandImageUrl' => null]);

        $this->assertStringContainsString('data-svc-media', $html);
        $this->assertStringContainsString('https://example.test/intro.jpg', $html);
    }

    public function test_card_stays_full_width_without_a_slot_image(): void
    {
        $html = $this->render(false, 5, [], ['bandImageUrl' => null, 'introImageUrl' => null]);

        $this->assertStringContainsString('data-svc-variant="checklist-band"', $html);
        $this->assertStringNotContainsString('data-svc-media', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('lg:grid-cols-2', $html);
    }

    public function test_uses_token_surface_not_bg_white(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="checklist-band"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface)', $html);
        $this->assertStringNotContainsString('bg-white', $html);
        $this->assertStringNotContainsString('background-color: var(--color-surface-contrast)', $html);
    }

    public function test_stamped_surface_contrast_swaps_wrapper_tokens(): void
    {
        $html = $this->render(false, 3, ['__surface' => 'contrast']);

        $this->assertStringContainsString('data-svc-variant="checklist-band"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface-contrast)', $html);
        $this->assertStringContainsString('var(--color-text-on-contrast)', $html);
        $this->assertStringContainsString('var(--color-surface-alt)', $html, 'elevated card stays alt on the contrast band');
        $this->assertStringContainsString('site-section-spacing', $html);
        $this->assertStringNotContainsString('pt-10 lg:pt-12', $html);
    }

    public function test_mobile_stack_uses_single_column_chrome(): void
    {
        $html = $this->render(false, 4);

        $this->assertStringContainsString('data-svc-variant="checklist-band"', $html);
        $this->assertStringContainsString('grid-cols-1', $html);
        $this->assertStringContainsString('px-7 py-10 lg:px-12 lg:py-12', $html);
        $this->assertStringNotContainsString('trust-item-card', $html);
        $this->assertStringNotContainsString('@media (max-width: 639px)', $html);
        $this->assertStringNotContainsString('md:columns-2', $html);
        $this->assertStringNotContainsString('md:grid-cols-2', $html);
        $this->assertStringNotContainsString('md:grid-cols-3', $html);
    }

    public function test_title_matching_eyebrow_hides_live_copy_keeps_marker(): void
    {
        $html = $this->render(true, 3, ['title' => 'Why Choose Us']);

        $this->assertStringContainsString('data-svc-variant="checklist-band"', $html);
        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringNotContainsString('Why Choose Us</span>', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('Why Choose Us', $html);
    }
}

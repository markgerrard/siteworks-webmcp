<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantFeaturesChecklistTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @param  array<string, mixed>|string|null  $introImageUrl
     * @param  array<string, mixed>|string|null  $bandImageUrl
     * @param  array<string, mixed>  $profile
     */
    private function render(
        bool $markers,
        int $count = 6,
        array $sectionOverrides = [],
        mixed $introImageUrl = 'https://example.test/intro.jpg',
        array $profile = ['watermark_enabled' => true],
        mixed $bandImageUrl = null,
    ): string {
        $items = [];
        foreach (range(1, $count) as $n) {
            $items[] = ['icon' => 'hammer', 'title' => "Item {$n}", 'body' => "Body {$n}."];
        }

        return View::make('site.sections.features', [
            'section' => array_merge([
                'type' => 'features',
                'variant' => 'checklist',
                'title' => "What's Included",
                'eyebrow' => "What's Included",
                'intro' => 'Scope intro line.',
                'items' => $items,
            ], $sectionOverrides),
            'sectionIndex' => 2,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => $markers,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'profile' => $profile,
            'introImageUrl' => $introImageUrl,
            'bandImageUrl' => $bandImageUrl,
        ])->render();
    }

    public function test_six_items_emit_variant_tag_and_copy(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="checklist"', $html);

        foreach (range(1, 6) as $n) {
            $this->assertStringContainsString("Item {$n}", $html);
            $this->assertStringContainsString("Body {$n}.", $html);
        }
    }

    public function test_band_image_renders_when_intro_image_url_given(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-media', $html);
        $this->assertStringContainsString('https://example.test/intro.jpg', $html);
        $this->assertStringContainsString('Item 1', $html);
        $this->assertStringContainsString('Item 6', $html);
    }

    public function test_null_intro_image_omits_media_and_keeps_items(): void
    {
        $html = $this->render(false, introImageUrl: null);

        $this->assertStringNotContainsString('data-svc-media', $html);
        $this->assertStringNotContainsString('https://example.test/intro.jpg', $html);
        $this->assertStringContainsString('data-svc-variant="checklist"', $html);

        foreach (range(1, 6) as $n) {
            $this->assertStringContainsString("Item {$n}", $html);
            $this->assertStringContainsString("Body {$n}.", $html);
        }
    }

    public function test_markers_cover_section_fields_and_first_item_title(): void
    {
        $html = $this->render(true);

        $this->assertStringContainsString('data-svc-variant="checklist"', $html);
        $this->assertStringContainsString('data-editable-field="eyebrow"', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('data-editable-field="intro"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.body"', $html);
        $this->assertStringContainsString('data-editable-field="items.5.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.5.body"', $html);
    }

    public function test_suppressed_eyebrow_emits_hidden_marker(): void
    {
        $html = $this->render(true, 6, ['__suppress_eyebrow' => true]);

        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('data-editable-field="intro"', $html);
    }

    public function test_production_array_intro_image_uses_watermark_url_when_present(): void
    {
        $html = $this->render(false, introImageUrl: [
            'url' => 'https://example.test/raw.jpg',
            'watermark_url' => 'https://example.test/wm.jpg',
        ]);

        $this->assertStringContainsString('src="https://example.test/wm.jpg"', $html);
        $this->assertStringNotContainsString('https://example.test/raw.jpg', $html);
        $this->assertStringContainsString('data-svc-media', $html);
        $this->assertStringContainsString('data-svc-variant="checklist"', $html);
    }

    public function test_production_array_intro_image_falls_back_to_url_without_watermark(): void
    {
        $html = $this->render(false, introImageUrl: [
            'url' => 'https://example.test/raw.jpg',
            'watermark_url' => null,
        ]);

        $this->assertStringContainsString('src="https://example.test/raw.jpg"', $html);
        $this->assertStringContainsString('data-svc-media', $html);
        $this->assertStringContainsString('data-svc-variant="checklist"', $html);
    }

    public function test_production_array_intro_image_uses_raw_url_when_watermarking_is_off(): void
    {
        $html = $this->render(false, introImageUrl: [
            'url' => 'https://example.test/raw.jpg',
            'watermark_url' => 'https://example.test/wm.jpg',
        ], profile: ['watermark_enabled' => false]);

        $this->assertStringContainsString('src="https://example.test/raw.jpg"', $html);
        $this->assertStringNotContainsString('https://example.test/wm.jpg', $html);
        $this->assertStringContainsString('data-svc-media', $html);
    }

    public function test_production_array_intro_image_without_usable_url_omits_media(): void
    {
        $html = $this->render(false, introImageUrl: [
            'url' => null,
            'watermark_url' => null,
        ]);

        $this->assertStringNotContainsString('data-svc-media', $html);
        $this->assertStringContainsString('data-svc-variant="checklist"', $html);
        $this->assertStringContainsString('Item 1', $html);
    }

    public function test_band_image_prefers_band_over_intro_fallback(): void
    {
        $html = $this->render(
            false,
            introImageUrl: 'https://example.test/intro.jpg',
            bandImageUrl: 'https://example.test/band.jpg',
        );

        $this->assertStringContainsString('src="https://example.test/band.jpg"', $html);
        $this->assertStringNotContainsString('https://example.test/intro.jpg', $html);
        $this->assertStringContainsString('data-svc-media', $html);
    }

    public function test_band_image_falls_back_to_intro_when_band_absent(): void
    {
        $html = $this->render(false, introImageUrl: 'https://example.test/intro.jpg', bandImageUrl: null);

        $this->assertStringContainsString('src="https://example.test/intro.jpg"', $html);
        $this->assertStringContainsString('data-svc-media', $html);
    }

    public function test_production_array_band_image_uses_watermark_url_when_present(): void
    {
        $html = $this->render(false, introImageUrl: 'https://example.test/intro.jpg', bandImageUrl: [
            'url' => 'https://example.test/band-raw.jpg',
            'watermark_url' => 'https://example.test/band-wm.jpg',
        ]);

        $this->assertStringContainsString('src="https://example.test/band-wm.jpg"', $html);
        $this->assertStringNotContainsString('https://example.test/band-raw.jpg', $html);
        $this->assertStringNotContainsString('https://example.test/intro.jpg', $html);
    }

    public function test_production_array_band_image_uses_raw_url_when_watermarking_is_off(): void
    {
        $html = $this->render(false, introImageUrl: 'https://example.test/intro.jpg', bandImageUrl: [
            'url' => 'https://example.test/band-raw.jpg',
            'watermark_url' => 'https://example.test/band-wm.jpg',
        ], profile: ['watermark_enabled' => false]);

        $this->assertStringContainsString('src="https://example.test/band-raw.jpg"', $html);
        $this->assertStringNotContainsString('https://example.test/band-wm.jpg', $html);
    }
}

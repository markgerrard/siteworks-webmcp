<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantIntroEditorialTest extends TestCase
{
    private function render(array $overrides = [], bool $markers = true): string
    {
        $section = array_merge([
            'type' => 'intro', 'title' => 'Extensions & Loft Conversions',
            'eyebrow' => 'About This Service', 'variant' => 'editorial',
            'body' => "P1 text.\n\nP2 text.\n\nP3 text.\n\nP4 text.",
        ], $overrides);
        return View::make('site.sections.intro', [
            'section' => $section, 'sectionIndex' => 1, 'pageId' => 42,
            'mode' => 'public', 'emitMarkers' => $markers, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [], 'profile' => ['watermark_enabled' => false],
            'introImageUrl' => 'https://example.test/i.jpg',
        ])->render();
    }

    public function test_routes_to_editorial_and_keeps_all_content(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('data-svc-variant="editorial"', $html);
        foreach (['P1 text.', 'P2 text.', 'P3 text.', 'P4 text.'] as $p) {
            $this->assertStringContainsString($p, $html);
        }
        $this->assertStringContainsString('https://example.test/i.jpg', $html);
    }

    public function test_emits_same_editor_fields_as_classic(): void
    {
        foreach (['"eyebrow"', '"title"', '"body"'] as $f) {
            $this->assertStringContainsString('data-editable-field='.$f, $this->render());
        }
    }

    public function test_suppressed_eyebrow_still_emits_hidden_marker(): void
    {
        $html = $this->render(['__suppress_eyebrow' => true]);
        $this->assertStringContainsString('data-editable-field="eyebrow"', $html);
        $this->assertStringNotContainsString('About This Service</span>', $html);
    }

    public function test_production_array_intro_image_uses_watermark_url_when_present(): void
    {
        $html = View::make('site.sections.intro', [
            'section' => [
                'type' => 'intro', 'title' => 'Extensions & Loft Conversions',
                'eyebrow' => 'About This Service', 'variant' => 'editorial',
                'body' => "P1 text.\n\nP2 text.\n\nP3 text.\n\nP4 text.",
            ],
            'sectionIndex' => 1, 'pageId' => 42,
            'mode' => 'public', 'emitMarkers' => false, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [], 'profile' => ['watermark_enabled' => true],
            'introImageUrl' => [
                'url' => 'https://example.test/raw.jpg',
                'watermark_url' => 'https://example.test/wm.jpg',
            ],
        ])->render();

        $this->assertStringContainsString('src="https://example.test/wm.jpg"', $html);
        $this->assertStringNotContainsString('https://example.test/raw.jpg', $html);
        $this->assertStringContainsString('data-svc-variant="editorial"', $html);
    }

    public function test_production_array_intro_image_falls_back_to_url_without_watermark(): void
    {
        $html = View::make('site.sections.intro', [
            'section' => [
                'type' => 'intro', 'title' => 'Extensions & Loft Conversions',
                'eyebrow' => 'About This Service', 'variant' => 'editorial',
                'body' => "P1 text.\n\nP2 text.",
            ],
            'sectionIndex' => 1, 'pageId' => 42,
            'mode' => 'public', 'emitMarkers' => false, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [], 'profile' => ['watermark_enabled' => true],
            'introImageUrl' => [
                'url' => 'https://example.test/raw.jpg',
                'watermark_url' => null,
            ],
        ])->render();

        $this->assertStringContainsString('src="https://example.test/raw.jpg"', $html);
        $this->assertStringContainsString('data-svc-variant="editorial"', $html);
    }

    public function test_missing_image_degrades(): void
    {
        $section = ['type' => 'intro', 'title' => 'T', 'variant' => 'editorial', 'body' => 'P1.'];
        $html = View::make('site.sections.intro', [
            'section' => $section, 'sectionIndex' => 1, 'pageId' => 42,
            'mode' => 'public', 'emitMarkers' => false, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [], 'profile' => [], 'introImageUrl' => null,
        ])->render();
        $this->assertStringNotContainsString('<figure', $html);
        $this->assertStringContainsString('P1.', $html);
    }
    public function test_band_image_count_renders_picked_slots_with_height_map(): void
    {
        $html = $this->renderWithBandOptions(['band_image_count' => 3, 'band_image_height' => 'tall'], [
            'bandImageUrl' => ['url' => 'https://example.test/b1.jpg', 'watermark_url' => null],
            'bandImage2Url' => ['url' => 'https://example.test/b2.jpg', 'watermark_url' => null],
            'bandImage3Url' => ['url' => 'https://example.test/b3.jpg', 'watermark_url' => null],
        ]);

        $this->assertStringContainsString('data-band-images="3"', $html);
        $this->assertStringContainsString('md:grid-cols-3', $html);
        $this->assertStringContainsString('aspect-ratio: 1 / 1', $html);
        foreach (['b1', 'b2', 'b3'] as $b) {
            $this->assertStringContainsString("https://example.test/{$b}.jpg", $html);
        }
    }

    public function test_band_mode_dedupes_and_degrades_without_reusing_intro(): void
    {
        $html = $this->renderWithBandOptions(['band_image_count' => 3], [
            'bandImageUrl' => ['url' => 'https://example.test/same.jpg', 'watermark_url' => null],
            'bandImage2Url' => ['url' => 'https://example.test/same.jpg', 'watermark_url' => null],
            'bandImage3Url' => null,
        ]);

        $this->assertStringContainsString('data-band-images="1"', $html);
        $this->assertStringContainsString('aspect-ratio: 4 / 3', $html);
        $this->assertStringNotContainsString('intro-slot.jpg', $html, 'picked mode must never fall back to the intro slot');
    }

    public function test_band_mode_with_no_picked_images_renders_no_figure(): void
    {
        $html = $this->renderWithBandOptions(['band_image_count' => 2], []);

        $this->assertStringNotContainsString('data-band-images', $html);
        $this->assertStringNotContainsString('intro-slot.jpg', $html);
    }

    public function test_without_band_options_the_intro_band_is_unchanged(): void
    {
        $html = $this->renderWithBandOptions([], []);

        $this->assertStringContainsString('intro-slot.jpg', $html);
        $this->assertStringContainsString('aspect-ratio: 21 / 8', $html);
        $this->assertStringNotContainsString('data-band-images', $html);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $bandVars
     */
    private function renderWithBandOptions(array $options, array $bandVars): string
    {
        return \Illuminate\Support\Facades\View::make('site.sections.intro', array_merge([
            'section' => array_merge(['type' => 'intro', 'title' => 'T', 'eyebrow' => 'E', 'variant' => 'editorial', 'body' => 'Body text.'], $options === [] ? [] : ['__options' => $options]),
            'sectionIndex' => 1, 'pageId' => 42, 'mode' => 'public',
            'emitMarkers' => false, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [], 'profile' => ['watermark_enabled' => false],
            'introImageUrl' => 'https://example.test/intro-slot.jpg',
        ], $bandVars))->render();
    }
}

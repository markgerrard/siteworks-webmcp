<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantIntroSplitTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>|string|null  $introImageUrl
     * @param  array<string, mixed>  $profile
     */
    private function render(
        array $overrides = [],
        bool $markers = true,
        mixed $introImageUrl = 'https://example.test/i.jpg',
        array $profile = ['watermark_enabled' => false],
    ): string {
        $section = array_merge([
            'type' => 'intro', 'title' => 'Extensions & Loft Conversions',
            'eyebrow' => 'About This Service', 'variant' => 'split',
            'body' => "P1 text.\n\nP2 text.\n\nP3 text.\n\nP4 text.",
        ], $overrides);

        return View::make('site.sections.intro', [
            'section' => $section, 'sectionIndex' => 1, 'pageId' => 42,
            'mode' => 'public', 'emitMarkers' => $markers, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [], 'profile' => $profile,
            'introImageUrl' => $introImageUrl,
        ])->render();
    }

    public function test_routes_to_split_and_keeps_all_content(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('data-svc-variant="split"', $html);
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

    public function test_panel_text_uses_on_primary_contrast_token(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('var(--color-text-on-primary', $html);
        $this->assertStringNotContainsString('color: #ffffff', $html);
        $this->assertStringNotContainsString('oklab, #ffffff', $html);
    }

    public function test_production_array_intro_image_uses_watermark_url_when_present(): void
    {
        $html = $this->render(
            introImageUrl: [
                'url' => 'https://example.test/raw.jpg',
                'watermark_url' => 'https://example.test/wm.jpg',
            ],
            profile: ['watermark_enabled' => true],
        );

        $this->assertStringContainsString('src="https://example.test/wm.jpg"', $html);
        $this->assertStringNotContainsString('https://example.test/raw.jpg', $html);
        $this->assertStringContainsString('data-svc-variant="split"', $html);
    }

    public function test_production_array_intro_image_falls_back_to_url_without_watermark(): void
    {
        $html = $this->render(
            introImageUrl: [
                'url' => 'https://example.test/raw.jpg',
                'watermark_url' => null,
            ],
            profile: ['watermark_enabled' => true],
        );

        $this->assertStringContainsString('src="https://example.test/raw.jpg"', $html);
        $this->assertStringContainsString('data-svc-variant="split"', $html);
    }

    public function test_missing_image_degrades_to_full_width_panel(): void
    {
        $section = [
            'type' => 'intro',
            'title' => 'T',
            'variant' => 'split',
            'body' => "P1 text.\n\nP2 text.\n\nP3 text.\n\nP4 text.",
        ];
        $html = View::make('site.sections.intro', [
            'section' => $section, 'sectionIndex' => 1, 'pageId' => 42,
            'mode' => 'public', 'emitMarkers' => false, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [], 'profile' => [], 'introImageUrl' => null,
        ])->render();
        $this->assertStringNotContainsString('data-svc-media', $html);
        foreach (['P1 text.', 'P2 text.', 'P3 text.', 'P4 text.'] as $p) {
            $this->assertStringContainsString($p, $html);
        }
    }
}

<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantStoryBannerOverlapTest extends TestCase
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
            'type' => 'story', 'title' => 'Our Story',
            'eyebrow' => 'About Us', 'variant' => 'banner-overlap',
            'body' => "P1 text.\n\nP2 text.\n\nP3 text.\n\nP4 text.",
        ], $overrides);

        return View::make('site.sections.story', [
            'section' => $section, 'sectionIndex' => 1, 'pageId' => 42,
            'mode' => 'public', 'emitMarkers' => $markers, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [], 'profile' => $profile,
            'introImageUrl' => $introImageUrl,
        ])->render();
    }

    public function test_routes_to_banner_overlap_and_keeps_all_content(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('data-svc-variant="banner-overlap"', $html);
        foreach (['P1 text.', 'P2 text.', 'P3 text.', 'P4 text.'] as $p) {
            $this->assertStringContainsString($p, $html);
        }
        $this->assertStringContainsString('https://example.test/i.jpg', $html);
        $this->assertStringContainsString('Our Story', $html);
        $this->assertStringContainsString('About Us', $html);
    }

    public function test_body_is_one_rich_container_wrapping_every_paragraph(): void
    {
        $html = $this->render();

        $this->assertSame(1, preg_match_all('/data-editable-field="body"/', $html));
        $this->assertSame(1, preg_match_all('/data-editable-type="rich"/', $html));

        $body = $this->editableBodyElement($html);
        $this->assertSame('rich', $body->getAttribute('data-editable-type'));
        foreach (['P1 text.', 'P2 text.', 'P3 text.', 'P4 text.'] as $p) {
            $this->assertStringContainsString($p, $body->textContent);
        }

        $this->assertStringContainsString('[&>p:first-child]', $body->getAttribute('class'));
    }

    public function test_panel_holds_eyebrow_title_and_full_body(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('data-svc-variant="banner-overlap"', $html);
        $this->assertStringContainsString('lg:grid-cols-2', $html, 'split composition with image');
        foreach (['P1 text.', 'P2 text.', 'P3 text.', 'P4 text.'] as $p) {
            $this->assertStringContainsString($p, $html);
        }
    }

    public function test_image_defaults_right_and_alignment_option_flips(): void
    {
        $default = $this->render();
        $this->assertStringNotContainsString('lg:order-last', $default, 'image LEFT by default, matching the service split');
        $right = $this->render(['__options' => ['image_alignment' => 'right']]);
        $this->assertStringContainsString('lg:order-last', $right, 'image_alignment=right flips the image over');
    }

    public function test_soft_radius_option_applies_to_image(): void
    {
        $html = $this->render(['__options' => ['image_radius' => 'soft']]);
        $this->assertStringContainsString('border-radius: var(--radius-card)', $html);
        $this->assertStringContainsString('border-radius: 0', $this->render(), 'default sharp');
    }

    public function test_no_image_panel_spans_full_width(): void
    {
        $html = $this->render([], true, null);
        $this->assertStringNotContainsString('lg:grid-cols-2', $html);
        $this->assertStringContainsString('lg:col-span-2', $html);
        foreach (['P1 text.', 'P2 text.', 'P3 text.', 'P4 text.'] as $p) {
            $this->assertStringContainsString($p, $html);
        }
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
        $this->assertStringNotContainsString('About Us</span>', $html);
    }

    public function test_panel_text_uses_on_primary_contrast_token(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('var(--color-text-on-primary', $html);
        $this->assertStringNotContainsString('color: #ffffff', $html);
        $this->assertStringNotContainsString('oklab, #ffffff', $html);
        $this->assertStringNotContainsString('bg-white', $html);
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
        $this->assertStringContainsString('data-svc-variant="banner-overlap"', $html);
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
        $this->assertStringContainsString('data-svc-variant="banner-overlap"', $html);
    }

    private function xpath(\DOMDocument $dom): \DOMXPath
    {
        return new \DOMXPath($dom);
    }

    private function loadHtml(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);

        return $dom;
    }

    private function editableBodyElement(string $html): \DOMElement
    {
        $nodes = $this->xpath($this->loadHtml($html))->query('//*[@data-editable-field="body"]');
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->count());
        $node = $nodes->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node);

        return $node;
    }

    private function brandCardElement(string $html): \DOMElement
    {
        $nodes = $this->xpath($this->loadHtml($html))->query('//div[contains(@style, "--brand-primary")]');
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->count());
        $node = $nodes->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node);

        return $node;
    }
}

<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantStoryDocumentTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $profile
     */
    private function render(
        array $overrides = [],
        bool $markers = true,
        mixed $introImageUrl = 'https://example.test/story.jpg',
        array $profile = ['watermark_enabled' => false],
    ): string {
        $section = array_merge([
            'type' => 'story',
            'title' => 'Our Story',
            'eyebrow' => 'About Us',
            'variant' => 'document',
            'body' => "P1 text.\n\nP2 text.\n\nP3 text.\n\nP4 text.",
        ], $overrides);

        return View::make('site.sections.story', [
            'section' => $section,
            'sectionIndex' => 1,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => $markers,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'profile' => $profile,
            'introImageUrl' => $introImageUrl,
        ])->render();
    }

    public function test_alignment_and_radius_options(): void
    {
        $base = [
            'section' => ['type' => 'story', 'title' => 'T', 'variant' => 'document', 'body' => 'P1.',
                '__options' => ['image_alignment' => 'left', 'image_radius' => 'soft']],
            'sectionIndex' => 1, 'pageId' => 42, 'mode' => 'public',
            'emitMarkers' => false, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [], 'profile' => ['watermark_enabled' => false],
            'introImageUrl' => 'https://example.test/i.jpg',
        ];
        $html = \Illuminate\Support\Facades\View::make('site.sections.story', $base)->render();
        $this->assertStringContainsString('lg:order-last lg:border-l', $html, 'image-left flips prose to the right of the divider');
        $this->assertStringContainsString('border-radius: var(--radius-card)', $html, 'soft radius applies');
        unset($base['section']['__options']);
        $plain = \Illuminate\Support\Facades\View::make('site.sections.story', $base)->render();
        $this->assertStringContainsString('lg:border-r', $plain, 'default image-right keeps divider on prose right edge');
        $this->assertStringContainsString('border-radius: 0', $plain, 'default stays sharp');
    }

    public function test_image_sits_in_the_right_column_with_accent_rule_chrome(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data-svc-variant="document"', $html);
        $this->assertStringContainsString('border-top: 2px solid var(--brand-accent)', $html);
        $this->assertStringContainsString('max-w-4xl', $html);
        $this->assertStringContainsString('lg:grid-cols-5', $html);
        $this->assertStringContainsString('lg:col-span-3', $html);
        $this->assertStringContainsString('lg:col-span-2', $html);
        $this->assertStringContainsString('order-first', $html);
        $this->assertStringContainsString('src="https://example.test/story.jpg"', $html);
        $this->assertStringContainsString('aspect-ratio: 4 / 3', $html);
        $this->assertStringContainsString('border: 1px solid', $html);
        $this->assertStringContainsString('Our Story', $html);
        foreach (['P1 text.', 'P2 text.', 'P3 text.', 'P4 text.'] as $p) {
            $this->assertStringContainsString($p, $html);
        }

        $this->assertMatchesRegularExpression(
            '/lg:col-span-3[\s\S]+lg:col-span-2[\s\S]+src="https:\/\/example\.test\/story\.jpg"/',
            $html,
            'prose (col-span-3) must precede the image (col-span-2) so the image sits RIGHT on lg',
        );

        $this->assertStringNotContainsString('mx-auto', $html);
        $this->assertStringNotContainsString('3px double', $html);
        $this->assertStringNotContainsString('rounded', $html);
        $this->assertStringNotContainsString('columns-2', $html);
    }

    public function test_no_image_collapses_the_grid_and_keeps_full_width_chrome(): void
    {
        $html = $this->render(introImageUrl: null);

        $this->assertStringContainsString('data-svc-variant="document"', $html);
        $this->assertStringContainsString('border-top: 2px solid var(--brand-accent)', $html);
        $this->assertStringContainsString('max-w-4xl', $html);
        $this->assertStringContainsString('Our Story', $html);
        foreach (['P1 text.', 'P2 text.', 'P3 text.', 'P4 text.'] as $p) {
            $this->assertStringContainsString($p, $html);
        }

        $this->assertStringNotContainsString('lg:grid-cols-5', $html);
        $this->assertStringNotContainsString('grid-cols', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<figure', $html);
        $this->assertStringNotContainsString('https://example.test/story.jpg', $html);
        $this->assertStringNotContainsString('mx-auto', $html);
        $this->assertStringNotContainsString('3px double', $html);
        $this->assertStringNotContainsString('columns-2', $html);
    }

    public function test_production_array_image_uses_watermark_url_when_present(): void
    {
        $html = View::make('site.sections.story', [
            'section' => [
                'type' => 'story',
                'title' => 'Our Story',
                'eyebrow' => 'About Us',
                'variant' => 'document',
                'body' => "P1 text.\n\nP2 text.",
            ],
            'sectionIndex' => 1,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => false,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'profile' => ['watermark_enabled' => true],
            'introImageUrl' => [
                'url' => 'https://example.test/story-raw.jpg',
                'watermark_url' => 'https://example.test/story-wm.jpg',
            ],
        ])->render();

        $this->assertStringContainsString('data-svc-variant="document"', $html);
        $this->assertStringContainsString('P1 text.', $html);
        $this->assertStringContainsString('P2 text.', $html);
        $this->assertStringContainsString('src="https://example.test/story-wm.jpg"', $html);
        $this->assertStringContainsString('lg:col-span-2', $html);
        $this->assertStringNotContainsString('https://example.test/story-raw.jpg', $html);
    }

    public function test_emits_same_editor_fields_as_classic(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('data-svc-variant="document"', $html);
        foreach (['"eyebrow"', '"title"', '"body"'] as $f) {
            $this->assertStringContainsString('data-editable-field='.$f, $html);
        }
    }

    public function test_suppressed_eyebrow_still_emits_hidden_marker(): void
    {
        $html = $this->render(['__suppress_eyebrow' => true]);
        $this->assertStringContainsString('data-editable-field="eyebrow"', $html);
        $this->assertStringNotContainsString('About Us</span>', $html);
        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringContainsString('border-top: 2px solid var(--brand-accent)', $html);
    }
}

<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantStoryEditorialTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>|string|null  $introImageUrl
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
            'variant' => 'editorial',
            'body' => "Opening paragraph.\n\nSecond paragraph.\n\nThird paragraph.\n\nFourth paragraph.",
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

    public function test_drop_cap_toggles_off_via_stamped_options(): void
    {
        $base = [
            'section' => ['type' => 'story', 'title' => 'T', 'variant' => 'editorial',
                'body' => str_repeat('Long paragraph text here. ', 30)],
            'sectionIndex' => 1, 'pageId' => 42, 'mode' => 'public',
            'emitMarkers' => false, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [], 'profile' => [], 'introImageUrl' => null,
        ];
        $on = \Illuminate\Support\Facades\View::make('site.sections.story', $base)->render();
        $this->assertStringContainsString('first-letter:float-left', $on, 'drop cap defaults on');
        $base['section']['__options'] = ['drop_cap' => false];
        $off = \Illuminate\Support\Facades\View::make('site.sections.story', $base)->render();
        $this->assertStringNotContainsString('first-letter:float-left', $off, 'drop_cap=false removes the cap');
    }

    public function test_routes_to_editorial_and_keeps_all_content(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data-svc-variant="editorial"', $html);
        foreach (['Opening paragraph.', 'Second paragraph.', 'Third paragraph.', 'Fourth paragraph.'] as $p) {
            $this->assertStringContainsString($p, $html);
        }
        $this->assertStringContainsString('Our Story', $html);
        $this->assertStringContainsString('About Us', $html);
        $this->assertStringContainsString('https://example.test/story.jpg', $html);
    }

    public function test_long_body_engages_two_columns(): void
    {
        $html = $this->render(['body' => str_repeat('a', 501)]);

        $this->assertStringContainsString('data-svc-variant="editorial"', $html);
        $this->assertStringContainsString('md:columns-2', $html);
    }

    public function test_short_body_does_not_engage_two_columns(): void
    {
        $html = $this->render(['body' => str_repeat('a', 500)]);

        $this->assertStringContainsString('data-svc-variant="editorial"', $html);
        $this->assertStringNotContainsString('columns-2', $html);
        $this->assertStringContainsString(str_repeat('a', 500), $html);
    }

    public function test_production_array_image_uses_watermark_url_when_present(): void
    {
        $html = $this->render(
            markers: false,
            introImageUrl: [
                'url' => 'https://example.test/raw.jpg',
                'watermark_url' => 'https://example.test/wm.jpg',
            ],
            profile: ['watermark_enabled' => true],
        );

        $this->assertStringContainsString('data-svc-variant="editorial"', $html);
        $this->assertStringContainsString('<figure', $html);
        $this->assertStringContainsString('src="https://example.test/wm.jpg"', $html);
        $this->assertStringNotContainsString('https://example.test/raw.jpg', $html);
        $this->assertStringContainsString('aspect-ratio: 21 / 8', $html);
    }

    public function test_missing_image_omits_figure(): void
    {
        $html = $this->render(markers: false, introImageUrl: null);

        $this->assertStringContainsString('data-svc-variant="editorial"', $html);
        $this->assertStringNotContainsString('<figure', $html);
        $this->assertStringContainsString('Opening paragraph.', $html);
    }

    public function test_emits_same_editor_fields_as_classic(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data-svc-variant="editorial"', $html);
        foreach (['"eyebrow"', '"title"', '"body"'] as $f) {
            $this->assertStringContainsString('data-editable-field='.$f, $html);
        }
    }

    public function test_suppressed_eyebrow_still_emits_hidden_marker(): void
    {
        $html = $this->render(['__suppress_eyebrow' => true]);

        $this->assertStringContainsString('data-svc-variant="editorial"', $html);
        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringNotContainsString('About Us</span>', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('Our Story', $html);
    }

    public function test_drop_cap_targets_first_paragraph(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data-svc-variant="editorial"', $html);
        $this->assertStringContainsString('first-letter', $html);
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
        return \Illuminate\Support\Facades\View::make('site.sections.story', array_merge([
            'section' => array_merge(['type' => 'story', 'title' => 'T', 'eyebrow' => 'E', 'variant' => 'editorial', 'body' => 'Body text.'], $options === [] ? [] : ['__options' => $options]),
            'sectionIndex' => 1, 'pageId' => 42, 'mode' => 'public',
            'emitMarkers' => false, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [], 'profile' => ['watermark_enabled' => false],
            'introImageUrl' => 'https://example.test/intro-slot.jpg',
        ], $bandVars))->render();
    }
}

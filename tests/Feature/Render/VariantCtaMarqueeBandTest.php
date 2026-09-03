<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantCtaMarqueeBandTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function render(array $overrides = [], bool $markers = false): string
    {
        $section = array_merge([
            'type' => 'cta',
            'title' => "Let's work together.",
            'body' => 'Get in touch today.',
            'button_label' => 'Get a quote',
            'button_url' => '#contact',
        ], $overrides);

        return View::make('site.sections.cta', [
            'section' => $section,
            'sectionIndex' => 3,
            'pageId' => 42,
            'emitMarkers' => $markers,
            'pagesBySlug' => ['contact' => '/contact'],
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function renderMarqueeOn(array $overrides = [], bool $markers = false): string
    {
        return $this->render(array_merge([
            'variant' => 'marquee-band',
            '__options' => ['marquee_band' => true],
        ], $overrides), $markers);
    }

    public function test_option_on_emits_duplicated_track_and_scoped_css_loop(): void
    {
        $html = $this->renderMarqueeOn();

        $this->assertStringContainsString('data-cta-variant="marquee-band"', $html);
        $this->assertStringContainsString('cta-marquee-track', $html);
        $this->assertStringContainsString('cta-marquee-item', $html);
        $this->assertStringContainsString('@keyframes cta-marquee-scroll', $html);
        $this->assertStringContainsString('translateX(-50%)', $html);
        $this->assertStringContainsString('Let&#039;s work together.', $html);
        $this->assertSame(1, substr_count($html, '<h2 class="cta-marquee-item"'));
        $this->assertSame(1, substr_count($html, '<span class="cta-marquee-item"'));
        $this->assertSame(2, substr_count($html, 'Let&#039;s work together.'));
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_option_off_with_variant_token_does_not_emit_marquee_device_bytes(): void
    {
        $html = $this->render([
            'variant' => 'marquee-band',
            '__options' => ['marquee_band' => false],
        ]);

        $this->assertStringContainsString('data-cta-variant="marquee-band"', $html);
        $this->assertStringNotContainsString('cta-marquee-track', $html);
        $this->assertStringNotContainsString('cta-marquee-item', $html);
        $this->assertStringNotContainsString('@keyframes cta-marquee-scroll', $html);
        $this->assertStringNotContainsString('cta-marquee-scroll', $html);
        $this->assertStringContainsString('Let&#039;s work together.', $html);
        $this->assertStringContainsString('<h2', $html);
    }

    public function test_classic_cta_is_byte_identical_regardless_of_unstamped_marquee_option(): void
    {
        $classic = $this->render();
        $optionOff = $this->render(['__options' => ['marquee_band' => false]]);
        $optionOnWithoutVariant = $this->render(['__options' => ['marquee_band' => true]]);

        $this->assertSame($classic, $optionOff);
        $this->assertSame($classic, $optionOnWithoutVariant);
        $this->assertStringNotContainsString('cta-marquee', $classic);
        $this->assertStringNotContainsString('marquee-band', $classic);
        $this->assertStringNotContainsString('@keyframes', $classic);
    }

    public function test_accent_band_is_not_hijacked_when_marquee_option_is_on(): void
    {
        $html = $this->render([
            'variant' => 'accent-band',
            '__options' => ['marquee_band' => true],
        ]);

        $this->assertStringContainsString('data-cta-variant="accent-band"', $html);
        $this->assertStringContainsString('var(--brand-accent)', $html);
        $this->assertStringNotContainsString('cta-marquee-track', $html);
        $this->assertStringNotContainsString('@keyframes cta-marquee-scroll', $html);
    }

    public function test_reduced_motion_media_query_renders_static_text_without_animation(): void
    {
        $html = $this->renderMarqueeOn();

        $this->assertMatchesRegularExpression(
            '/@media\s*\(\s*prefers-reduced-motion:\s*reduce\s*\)\s*\{/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/prefers-reduced-motion:\s*reduce[^}]*animation(?:-name)?\s*:\s*none/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/prefers-reduced-motion:\s*reduce[\s\S]*cta-marquee-item:not\(:first-child\)\s*\{[^}]*display\s*:\s*none/s',
            $html,
        );
    }

    public function test_marquee_text_from_section_content_is_html_escaped(): void
    {
        $payload = '</span><script>alert(1)</script><img src=x onerror=alert(1)>';
        $html = $this->renderMarqueeOn(['title' => $payload]);

        $this->assertStringContainsString('cta-marquee-item', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_marquee_on_keeps_classic_editor_fields(): void
    {
        $html = $this->renderMarqueeOn([], true);

        foreach (['title', 'body', 'button_label', 'button_url'] as $field) {
            $this->assertStringContainsString('data-editable-field="'.$field.'"', $html);
        }
    }

    public function test_cta_blade_does_not_interpolate_section_content_into_script_source(): void
    {
        $source = (string) file_get_contents(resource_path('views/site/sections/cta.blade.php'));

        $this->assertDoesNotMatchRegularExpression(
            '/<script\b[^>]*>[\s\S]*(\{\{\s*\$section|\{!!\s*\$section)/i',
            $source,
        );
        $this->assertStringNotContainsString('<script', $source);
    }
    public function test_reduced_motion_static_state_wraps_full_title_instead_of_clipping(): void
    {
        $html = $this->renderMarqueeOn();

        $reduced = substr($html, strpos($html, 'prefers-reduced-motion'));

        // Static state must not clip — wrap at a smaller clamp.
        $this->assertStringContainsString('white-space: normal', $reduced);
        $this->assertStringContainsString('overflow-wrap: break-word', $reduced);
        $this->assertStringContainsString('max-width: 100%', $reduced);
        $this->assertStringContainsString('font-size: clamp(2.25rem', $reduced);
        // Geometry (no clipped glyphs at 780/1440px) is verified in the
        // Stage-6 browser smoke — lanes exclude the Browser suite.
    }
}

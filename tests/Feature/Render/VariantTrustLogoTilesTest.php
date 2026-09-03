<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Accreditation / trust logo-tile grid (motion device G8).
 *
 * The variant greyscales logo tiles via CSS filter and returns them to full
 * colour on hover/focus-visible ONLY when the `logo_tile_hover` device option
 * is stamped (option off / unstamped == static colour logos, no filter
 * styles emitted). Each tile's logo comes from the trust item's existing
 * `icon` image field; content is escaped. The byte-identity harness
 * (ByteIdentityHarnessTest) proves the frozen corpus fixtures stay untouched.
 */
class VariantTrustLogoTilesTest extends TestCase
{
    /**
     * @param  bool  $hoverOption  whether `logo_tile_hover` is stamped on
     * @param  array<string, mixed>  $sectionOverrides
     * @return array<string, mixed>
     */
    private function trustVars(bool $markers, bool $hoverOption, int $count = 4, array $sectionOverrides = []): array
    {
        $items = [];
        foreach (range(1, $count) as $n) {
            $items[] = [
                'title' => "Accreditation {$n}",
                'body' => "Credential {$n}.",
                'icon' => "https://example.test/logo-{$n}.png",
            ];
        }

        $section = array_merge([
            'type' => 'trust',
            'variant' => 'logo-tiles',
            'title' => 'Recognised & accredited',
            'eyebrow' => 'Accreditations',
            'items' => $items,
        ], $sectionOverrides);

        if ($hoverOption) {
            $section = array_merge(['__options' => ['logo_tile_hover' => true]], $section);
        }

        return [
            'section' => $section,
            'sectionIndex' => 2,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => $markers,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
        ];
    }

    /**
     * @param  bool  $hoverOption
     * @param  array<string, mixed>  $sectionOverrides
     */
    private function render(bool $markers, bool $hoverOption, int $count = 4, array $sectionOverrides = []): string
    {
        return View::make('site.sections.trust', $this->trustVars($markers, $hoverOption, $count, $sectionOverrides))->render();
    }

    public function test_option_on_emits_greyscale_filter_and_hover_focus_release(): void
    {
        $html = $this->render(false, true, 3);

        $this->assertStringContainsString('data-svc-variant="logo-tiles"', $html);
        $this->assertStringContainsString('filter: grayscale(100%)', $html, 'default greyscale filter missing');
        $this->assertStringContainsString('.logo-tile-img:hover', $html, 'hover colour release missing');
        $this->assertStringContainsString('.logo-tile-img:focus-visible', $html, 'keyboard focus colour release missing');
        $this->assertStringContainsString('prefers-reduced-motion', $html, 'reduced-motion guard missing');
        $this->assertStringContainsString('transition: none', $html, 'reduced-motion should disable the transition (instant swap)');
        // The tile image itself carries the greyscaling class.
        $this->assertStringContainsString('class="max-h-16 w-auto object-contain logo-tile-img"', $html);
    }

    public function test_option_off_emits_no_filter_styles_and_full_colour_logos(): void
    {
        $html = $this->render(false, false, 4);

        $this->assertStringContainsString('data-svc-variant="logo-tiles"', $html);
        // Option off == static colour logos: no filter style block, no grayscale.
        $this->assertStringNotContainsString('grayscale', $html);
        $this->assertStringNotContainsString('filter: none', $html);
        $this->assertStringNotContainsString('.logo-tile-img', $html);
        $this->assertStringNotContainsString('prefers-reduced-motion', $html);
        // Images still render, in full colour.
        $this->assertStringContainsString('src="https://example.test/logo-1.png"', $html);
    }

    public function test_unstamped_option_is_inert_like_option_off(): void
    {
        // No __options at all — the device is not stamped, so no motion bytes.
        $html = $this->render(false, false, 4, ['__options' => null]);

        $this->assertStringContainsString('data-svc-variant="logo-tiles"', $html);
        $this->assertStringNotContainsString('grayscale', $html);
        $this->assertStringNotContainsString('.logo-tile-img', $html);
        $this->assertStringNotContainsString('prefers-reduced-motion', $html);
    }

    public function test_images_and_alt_are_escaped(): void
    {
        $sectionOverrides = [
            'items' => [
                ['title' => 'Acme & Co', 'body' => 'One.', 'icon' => 'https://example.test/a?x=1&y=2'],
                ['title' => 'Plain', 'body' => 'Two.', 'icon' => ''],
            ],
        ];

        $html = $this->render(false, true, 2, $sectionOverrides);

        // URL query string escaped (& → &amp;), alt text escaped (& → &amp;).
        $this->assertStringContainsString('src="https://example.test/a?x=1&amp;y=2"', $html);
        $this->assertStringContainsString('alt="Acme &amp; Co"', $html);
    }

    public function test_emits_superset_of_classic_trust_fields_for_marker_parity(): void
    {
        $html = $this->render(true, true, 4);

        foreach (['eyebrow', 'title', 'items.0.title', 'items.0.body', 'items.3.title', 'items.3.body', 'items.0.icon'] as $field) {
            $this->assertStringContainsString('data-editable-field="'.$field.'"', $html, "missing marker for {$field}");
        }
    }
}
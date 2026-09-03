<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantIntroSpecTest extends TestCase
{
    private function render(array $overrides = [], bool $markers = true): string
    {
        $section = array_merge([
            'type' => 'intro', 'title' => 'Extensions & Loft Conversions',
            'eyebrow' => 'About This Service', 'variant' => 'spec',
            'body' => "P1 text.\n\nP2 text.\n\nP3 text.\n\nP4 text.",
        ], $overrides);

        return View::make('site.sections.intro', [
            'section' => $section, 'sectionIndex' => 1, 'pageId' => 42,
            'mode' => 'public', 'emitMarkers' => $markers, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [], 'profile' => ['watermark_enabled' => false],
            'introImageUrl' => 'https://example.test/i.jpg',
        ])->render();
    }

    public function test_no_image_keeps_original_composition(): void
    {
        $html = \Illuminate\Support\Facades\View::make('site.sections.intro', [
            'section' => ['type' => 'intro', 'title' => 'T', 'variant' => 'spec', 'body' => 'P1.'],
            'sectionIndex' => 1, 'pageId' => 42, 'mode' => 'public',
            'emitMarkers' => false, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [], 'profile' => [], 'introImageUrl' => null,
        ])->render();
        $this->assertStringNotContainsString('<figure', $html);
        $this->assertStringContainsString('P1.', $html);
    }

    public function test_routes_to_spec_keeps_content_and_renders_optional_image(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('data-svc-variant="spec"', $html);
        $this->assertStringContainsString('Extensions & Loft Conversions', html_entity_decode($html));
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
}

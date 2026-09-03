<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceSectionExtractionSnapshotTest extends TestCase
{
    private function introVars(bool $markers): array
    {
        return [
            'section' => [
                'type' => 'intro', 'title' => 'Extensions & Loft Conversions',
                'eyebrow' => 'About This Service',
                'body' => "First paragraph of prose.\n\nSecond paragraph with detail.",
            ],
            'sectionIndex' => 1, 'pageId' => 42, 'mode' => 'public',
            'emitMarkers' => $markers, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [], 'profile' => ['watermark_enabled' => false],
            'introImageUrl' => 'https://example.test/intro.jpg',
        ];
    }

    private function featuresVars(bool $markers): array
    {
        $items = [];
        foreach (range(1, 6) as $n) {
            $items[] = ['icon' => 'hammer', 'title' => "Item {$n}", 'body' => "Body {$n}."];
        }
        return [
            'section' => [
                'type' => 'features', 'title' => "What's Included",
                'eyebrow' => "What's Included", 'intro' => 'Scope intro line.',
                'items' => $items,
            ],
            'sectionIndex' => 2, 'pageId' => 42, 'mode' => 'public',
            'emitMarkers' => $markers, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [],
        ];
    }

    public static function snapshotCases(): array
    {
        return [
            'intro-markers'    => ['site.sections.intro', 'introVars', true],
            'intro-plain'      => ['site.sections.intro', 'introVars', false],
            'features-markers' => ['site.sections.features', 'featuresVars', true],
            'features-plain'   => ['site.sections.features', 'featuresVars', false],
        ];
    }

    #[DataProvider('snapshotCases')]
    public function test_render_matches_snapshot(string $view, string $varsFn, bool $markers): void
    {
        $name = str_replace('site.sections.', '', $view).'-'.($markers ? 'markers' : 'plain');
        // Whitespace-normalised (DOM-equal) comparison: @include shifts
        // indentation legitimately; collapsed inter-tag whitespace renders
        // identically in HTML. Real content/attribute drift still fails.
        $normalize = fn (string $h): string => trim(preg_replace(['/>\s+</', '/\s+/'], ['><', ' '], $h));
        $html = $normalize(View::make($view, $this->{$varsFn}($markers))->render());
        $path = base_path("tests/fixtures/service-sections/{$name}.html");
        if (! file_exists($path)) {
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, $html);
            $this->markTestIncomplete("Snapshot created at {$path} — re-run to assert.");
        }
        $this->assertSame(file_get_contents($path), $html, "{$name} drifted from snapshot");
    }
}

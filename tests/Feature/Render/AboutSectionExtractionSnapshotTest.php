<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AboutSectionExtractionSnapshotTest extends TestCase
{
    private function storyVars(bool $markers): array
    {
        return [
            'section' => [
                'type' => 'story', 'title' => 'Our Story',
                'eyebrow' => 'About Us',
                'body' => "First paragraph of story.\n\nSecond paragraph with more detail.\n\nThird paragraph continues the narrative.\n\nFourth paragraph closes the story.",
            ],
            'sectionIndex' => 1, 'pageId' => 42, 'mode' => 'public',
            'emitMarkers' => $markers, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [], 'profile' => ['watermark_enabled' => true],
            'introImageUrl' => [
                'url' => 'https://example.test/story.jpg',
                'watermark_url' => 'https://example.test/story-wm.jpg',
            ],
        ];
    }

    private function valuesVars(bool $markers, int $count = 5): array
    {
        $items = [];
        foreach (range(1, $count) as $n) {
            $items[] = ['title' => "Value {$n}", 'body' => "Conviction {$n}."];
        }

        return [
            'section' => [
                'type' => 'values', 'title' => 'What We Stand For',
                'eyebrow' => 'Our Values',
                'items' => $items,
            ],
            'sectionIndex' => 2, 'pageId' => 42, 'mode' => 'public',
            'emitMarkers' => $markers, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [],
        ];
    }

    private function valuesSixVars(bool $markers): array
    {
        return $this->valuesVars($markers, 6);
    }

    public static function snapshotCases(): array
    {
        return [
            'story-markers'  => ['site.sections.story', 'storyVars', true],
            'story-plain'    => ['site.sections.story', 'storyVars', false],
            'values-markers' => ['site.sections.values', 'valuesVars', true],
            'values-plain'   => ['site.sections.values', 'valuesVars', false],
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
        $path = base_path("tests/fixtures/about-sections/{$name}.html");
        if (! file_exists($path)) {
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, $html);
            $this->markTestIncomplete("Snapshot created at {$path} — re-run to assert.");
        }
        $this->assertSame(file_get_contents($path), $html, "{$name} drifted from snapshot");
    }

    public function test_classic_clamps_four_items_to_three_while_new_variants_render_all(): void
    {
        $items = [];
        foreach (range(1, 4) as $n) {
            $items[] = ['title' => "Value {$n}", 'body' => "Conviction {$n}."];
        }
        $base = [
            'section' => ['type' => 'values', 'title' => 'What We Stand For', 'items' => $items],
            'sectionIndex' => 2, 'pageId' => 42, 'mode' => 'public',
            'emitMarkers' => false, 'emitFormMarkers' => false,
            'schema' => [], 'theme' => [],
        ];
        $classic = \Illuminate\Support\Facades\View::make('site.sections.values', $base)->render();
        $this->assertStringContainsString('Value 3', $classic);
        $this->assertStringNotContainsString('Value 4', $classic, 'classic 4->3 clamp arm must hold');
        foreach (['ledger', 'statements', 'markers'] as $variant) {
            $vars = $base;
            $vars['section']['variant'] = $variant;
            $html = \Illuminate\Support\Facades\View::make('site.sections.values', $vars)->render();
            $this->assertStringContainsString('Value 4', $html, "values/{$variant} must render all four items");
        }
    }

    public function test_classic_clamps_six_items_to_five_while_new_variants_render_all(): void
    {
        $classic = View::make('site.sections.values', $this->valuesVars(false, 6))->render();
        foreach (range(1, 5) as $n) {
            $this->assertStringContainsString("Value {$n}", $classic);
            $this->assertStringContainsString("Conviction {$n}.", $classic);
        }
        $this->assertStringNotContainsString('Value 6', $classic);
        $this->assertStringNotContainsString('Conviction 6.', $classic);

        foreach (['ledger', 'statements', 'markers'] as $variant) {
            $vars = $this->valuesVars(false, 6);
            $vars['section']['variant'] = $variant;
            $html = View::make('site.sections.values', $vars)->render();
            $this->assertStringContainsString("Value 6", $html, "{$variant} dropped Value 6");
            $this->assertStringContainsString("Conviction 6.", $html, "{$variant} dropped Conviction 6");
        }
    }

    public function test_six_item_classic_snapshot_locks_the_clamp(): void
    {
        $normalize = fn (string $h): string => trim(preg_replace(['/>\s+</', '/\s+/'], ['><', ' '], $h));
        $created = [];

        foreach ([true, false] as $markers) {
            $name = 'values-6items-'.($markers ? 'markers' : 'plain');
            $html = $normalize(View::make('site.sections.values', $this->valuesSixVars($markers))->render());
            $path = base_path("tests/fixtures/about-sections/{$name}.html");
            if (! file_exists($path)) {
                @mkdir(dirname($path), 0775, true);
                file_put_contents($path, $html);
                $created[] = $path;

                continue;
            }
            $this->assertSame(file_get_contents($path), $html, "{$name} drifted from snapshot");
            $this->assertStringNotContainsString('Value 6', $html);
            $this->assertStringContainsString('Value 5', $html);
        }

        if ($created !== []) {
            $this->markTestIncomplete('Snapshot created at '.implode(', ', $created).' — re-run to assert.');
        }
    }
}

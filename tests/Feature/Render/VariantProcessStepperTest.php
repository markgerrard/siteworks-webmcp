<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantProcessStepperTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @return array<string, mixed>
     */
    private function processVars(bool $markers, int $count = 4, array $sectionOverrides = []): array
    {
        $items = $sectionOverrides['items'] ?? [];
        if ($items === []) {
            foreach (range(1, $count) as $n) {
                $items[] = ['title' => "Step {$n}", 'body' => "Do {$n}.", 'step' => $n];
            }
        }
        unset($sectionOverrides['items']);

        return [
            'section' => array_merge([
                'type' => 'process',
                'variant' => 'stepper',
                'title' => 'How it works',
                'eyebrow' => 'Our Process',
                'items' => $items,
            ], $sectionOverrides),
            'sectionIndex' => 1,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => $markers,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     */
    private function render(bool $markers, int $count = 4, array $sectionOverrides = []): string
    {
        return View::make('site.sections.process', $this->processVars($markers, $count, $sectionOverrides))->render();
    }

    public function test_routes_horizontal_stepper_with_track_and_ghost_numerals(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="stepper"', $html);
        $this->assertStringContainsString('lg:grid-cols-4', $html);
        $this->assertStringContainsString('border-top: 1px solid color-mix(in oklab, var(--color-text) 16%, transparent)', $html);
        $this->assertStringContainsString('color-mix(in oklab, var(--brand-accent-text) 40%, transparent)', $html);
        $this->assertStringNotContainsString('rounded-full', $html, 'no ordinal circles — editorial DNA only');
    }

    public function test_persisted_step_values_render_and_fallback_is_index(): void
    {
        $htmlWithSteps = $this->render(false);
        $this->assertStringContainsString('>04<', $htmlWithSteps);

        $htmlNoSteps = $this->render(false, 4, ['items' => [
            ['title' => 'A', 'body' => 'a.'], ['title' => 'B', 'body' => 'b.'],
        ]]);
        $this->assertStringContainsString('>01<', $htmlNoSteps);
        $this->assertStringContainsString('>02<', $htmlNoSteps);
    }

    public function test_emits_superset_of_classic_process_fields(): void
    {
        $html = $this->render(true);

        foreach (['eyebrow', 'title', 'items.0.title', 'items.0.body', 'items.3.title'] as $field) {
            $this->assertStringContainsString('data-editable-field="'.$field.'"', $html);
        }
    }
}

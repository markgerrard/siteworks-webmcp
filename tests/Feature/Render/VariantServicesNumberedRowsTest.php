<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantServicesNumberedRowsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function servicesVars(string $variant, bool $markers, int $count = 3, array $sectionOverrides = [], array $extra = []): array
    {
        $items = $sectionOverrides['items'] ?? $this->defaultItems($count);
        unset($sectionOverrides['items']);

        return array_merge([
            'section' => array_merge([
                'type' => 'services',
                'variant' => $variant,
                'title' => 'What We Do',
                'eyebrow' => 'Our Services',
                'intro' => 'Trade services across the borough.',
                'accent_word' => 'Do',
                'items' => $items,
            ], $sectionOverrides),
            'sectionIndex' => 1,
            'pageId' => 42,
            'mode' => 'public',
            'emitMarkers' => $markers,
            'emitFormMarkers' => false,
            'schema' => [],
            'theme' => [],
            'profile' => [
                'watermark_enabled' => true,
                'geo' => ['scope' => 'local'],
            ],
            'site' => null,
            'pagesBySlug' => [
                'boiler-repair' => '/boiler-repair',
                'bathroom-fitting' => '/bathroom-fitting',
                'contact' => '/contact',
            ],
            'heroImages' => [],
            'itemsById' => collect(),
        ], $extra);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defaultItems(int $count): array
    {
        if ($count === 3) {
            return [
                [
                    'icon' => 'wrench',
                    'title' => 'Boiler repair',
                    'body' => 'Fast boiler fixes.',
                    'source_service' => 'Boiler Repair',
                    'featured' => true,
                ],
                [
                    'icon' => 'bath',
                    'title' => 'Bathroom fitting',
                    'body' => 'Full bathroom refits.',
                    'source_service' => 'Bathroom Fitting',
                ],
                [
                    'title' => 'Talk to us',
                    'body' => 'Book a visit.',
                    'contact_cta' => true,
                    'cta_label' => 'Get in touch',
                ],
            ];
        }

        $items = [];
        foreach (range(1, $count) as $n) {
            $items[] = ['icon' => 'hammer', 'title' => "Service {$n}", 'body' => "Body {$n}."];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @param  array<string, mixed>  $extra
     */
    private function render(bool $markers, int $count = 3, array $sectionOverrides = [], array $extra = []): string
    {
        return View::make('site.sections.services', $this->servicesVars('numbered-rows', $markers, $count, $sectionOverrides, $extra))->render();
    }

    /**
     * @param  array<string, mixed>  $sectionOverrides
     */
    private function renderClassic(bool $markers, int $count = 3, array $sectionOverrides = []): string
    {
        return View::make('site.sections.services', $this->servicesVars('classic', $markers, $count, $sectionOverrides))->render();
    }

    /**
     * @return list<string>
     */
    private function editableFields(string $html): array
    {
        preg_match_all('/data-editable-field="([^"]+)"/', $html, $matches);

        $fields = array_values(array_unique($matches[1] ?? []));
        sort($fields);

        return $fields;
    }

    public function test_routes_to_numbered_rows_and_keeps_all_item_copy(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('id="home-content"', $html);
        $this->assertStringContainsString('Our Services', $html);
        $this->assertStringContainsString('What We', $html);
        $this->assertStringContainsString('Trade services across the borough.', $html);
        $this->assertStringContainsString('Boiler repair', $html);
        $this->assertStringContainsString('Fast boiler fixes.', $html);
        $this->assertStringContainsString('Bathroom fitting', $html);
        $this->assertStringContainsString('Full bathroom refits.', $html);
        $this->assertStringContainsString('Talk to us', $html);
        $this->assertStringContainsString('Book a visit.', $html);
    }

    public function test_flattens_featured_and_contact_cta_into_equal_numbered_rows(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('>01<', $html);
        $this->assertStringContainsString('>02<', $html);
        $this->assertStringContainsString('>03<', $html);
        $this->assertStringNotContainsString('Most popular', $html);
        $this->assertStringNotContainsString('scale-[1.02]', $html);
        $this->assertStringNotContainsString('min-width: 280px', $html);
        $this->assertStringNotContainsString('w-12 h-12', $html);
        $this->assertStringNotContainsString('rounded-full', $html);
        $this->assertStringNotContainsString('Read more', $html);
        $this->assertStringNotContainsString('Get in touch', $html);
        $this->assertStringNotContainsString('data-service-card-photo', $html);
    }

    public function test_reuses_shipped_numbered_chrome_grid_classes(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('grid-cols-[3.5rem_1fr]', $html);
        $this->assertStringContainsString('md:grid-cols-[5rem_minmax(0,1fr)_minmax(0,1.4fr)]', $html);
        $this->assertStringContainsString('text-2xl md:text-3xl font-light tabular-nums', $html);
        $this->assertStringContainsString('color-mix(in oklab, var(--color-text) 18%, transparent)', $html);
        $this->assertStringContainsString('pt-10 lg:pt-12', $html);
        $this->assertStringContainsString('padding-bottom: var(--section-spacing)', $html);
    }

    public function test_renders_two_through_eight_items_without_clamping(): void
    {
        foreach (range(2, 8) as $count) {
            $items = [];
            foreach (range(1, $count) as $n) {
                $items[] = ['icon' => 'hammer', 'title' => "Service {$n}", 'body' => "Body {$n}."];
            }
            $html = $this->render(false, $count, ['items' => $items]);

            $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html, "count {$count}");
            $this->assertStringContainsString('>01<', $html, "count {$count}");
            $this->assertStringContainsString('>'.str_pad((string) $count, 2, '0', STR_PAD_LEFT).'<', $html, "count {$count}");

            foreach (range(1, $count) as $n) {
                $this->assertStringContainsString("Service {$n}", $html, "count {$count} missing title {$n}");
                $this->assertStringContainsString("Body {$n}.", $html, "count {$count} missing body {$n}");
            }
        }
    }

    public function test_emits_at_least_classic_editor_fields(): void
    {
        $html = $this->render(true);
        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);

        $classic = $this->editableFields($this->renderClassic(true));
        $variant = $this->editableFields($html);

        $this->assertNotEmpty($classic);
        foreach ($classic as $field) {
            $this->assertContains($field, $variant, "numbered-rows missing classic field {$field}");
        }
    }

    public function test_eight_items_emit_item_markers_classic_would_keep(): void
    {
        $html = $this->render(true, 8);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.icon"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.0.body"', $html);
        $this->assertStringContainsString('data-editable-field="items.7.icon"', $html);
        $this->assertStringContainsString('data-editable-field="items.7.title"', $html);
        $this->assertStringContainsString('data-editable-field="items.7.body"', $html);
    }

    public function test_suppressed_eyebrow_emits_hidden_marker(): void
    {
        $html = $this->render(true, 3, ['__suppress_eyebrow' => true]);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="eyebrow"/',
            $html,
        );
        $this->assertStringNotContainsString('Our Services</span>', $html);
        $this->assertStringContainsString('data-editable-field="title"', $html);
        $this->assertStringContainsString('What We', $html);
    }

    public function test_empty_intro_emits_hidden_rich_marker(): void
    {
        $html = $this->render(true, 3, ['intro' => '']);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertMatchesRegularExpression(
            '/<span class="hidden"[^>]*data-editable-field="intro"/',
            $html,
        );
        $this->assertStringContainsString('data-editable-type="rich"', $html);
    }

    public function test_uses_token_surface_and_seam_when_unstamped(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface)', $html);
        $this->assertStringContainsString('pt-10 lg:pt-12', $html);
        $this->assertStringNotContainsString('bg-white', $html);
        $this->assertStringNotContainsString('var(--color-surface-contrast)', $html);
        $this->assertStringNotContainsString('var(--color-text-on-contrast)', $html);
    }

    public function test_stamped_contrast_surface_uses_full_spacing_and_contrast_tokens(): void
    {
        $html = $this->render(false, 3, ['__surface' => 'contrast']);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('background-color: var(--color-surface-contrast)', $html);
        $this->assertStringContainsString('var(--color-text-on-contrast)', $html);
        $this->assertStringContainsString('var(--color-text-muted-on-contrast)', $html);
        $this->assertStringContainsString('site-section-spacing', $html);
        $this->assertStringNotContainsString('pt-10 lg:pt-12', $html);
        $this->assertStringNotContainsString('bg-white', $html);
    }

    public function test_renders_no_images_even_when_hero_photos_are_provided(): void
    {
        $html = $this->render(false, 3, [], [
            'heroImages' => [
                'boiler-repair' => [
                    'url' => 'https://example.test/boiler-clean.jpg',
                    'watermark_url' => 'https://example.test/boiler-wm.jpg',
                ],
                'bathroom-fitting' => [
                    'url' => 'https://example.test/bath-clean.jpg',
                    'watermark_url' => 'https://example.test/bath-wm.jpg',
                ],
            ],
        ]);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('https://example.test/boiler-wm.jpg', $html);
        $this->assertStringNotContainsString('data-service-card-photo', $html);
    }

    public function test_collapses_to_single_column_below_md(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('data-svc-variant="numbered-rows"', $html);
        $this->assertStringContainsString('grid-cols-[3.5rem_1fr]', $html);
        $this->assertStringNotContainsString('md:columns-2', $html);
        $this->assertStringNotContainsString('grid-cols-2', $html);
        $this->assertStringNotContainsString('md:grid-cols-2', $html);
    }

    public function test_links_item_titles_to_resolved_pages_and_degrades(): void
    {
        $vars = $this->servicesVars('numbered-rows', false, 3, [], ['pagesBySlug' => ['roof-repairs' => '/roof-repairs']]);
        $vars['section']['items'][0]['title'] = 'Roof Repairs';
        $html = \Illuminate\Support\Facades\View::make('site.sections.services', $vars)->render();
        $this->assertStringContainsString('href="/roof-repairs"', $html);

        $vars2 = $this->servicesVars('numbered-rows', false, 3, [], ['pagesBySlug' => []]);
        $vars2['section']['items'][0]['title'] = 'Roof Repairs';
        $plain = \Illuminate\Support\Facades\View::make('site.sections.services', $vars2)->render();
        $this->assertStringNotContainsString('href="/roof-repairs"', $plain);
        $this->assertStringContainsString('Roof Repairs', $plain);
    }
}

<?php

namespace Tests\Feature\Render;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VariantTrustBrandManifestoTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $sectionOverrides
     * @return array<string, mixed>
     */
    private function trustVars(bool $markers, int $count = 4, array $sectionOverrides = []): array
    {
        $items = [];
        foreach (range(1, $count) as $n) {
            $items[] = ['title' => "Signal {$n}", 'body' => "Proof {$n}."];
        }

        return [
            'section' => array_merge([
                'type' => 'trust',
                'variant' => 'brand-manifesto',
                'title' => 'Why homeowners pick us',
                'eyebrow' => 'Why Choose Us',
                'items' => $items,
            ], $sectionOverrides),
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
     * @param  array<string, mixed>  $sectionOverrides
     */
    private function render(bool $markers, int $count = 4, array $sectionOverrides = []): string
    {
        return View::make('site.sections.trust', $this->trustVars($markers, $count, $sectionOverrides))->render();
    }

    public function test_brand_stamp_paints_the_full_bleed_manifesto(): void
    {
        $html = $this->render(false, 4, ['__surface' => 'brand']);

        $this->assertStringContainsString('data-svc-variant="brand-manifesto"', $html);
        $this->assertStringContainsString('background-color: var(--brand-primary)', $html);
        $this->assertStringContainsString('var(--color-text-on-primary, #ffffff)', $html);
        $this->assertStringContainsString('background-color: var(--brand-accent)', $html, 'accent rule missing');
    }

    public function test_no_ordinals_anywhere(): void
    {
        $html = $this->render(false, 4, ['__surface' => 'brand']);

        $this->assertStringNotContainsString('>01</span>', $html);
        $this->assertStringNotContainsString('tabular-nums', $html);
    }

    public function test_unstamped_fallback_uses_base_tokens(): void
    {
        $html = $this->render(false, 4);

        $this->assertStringContainsString('background-color: var(--color-surface)', $html);
        $this->assertStringNotContainsString('var(--brand-primary)', $html);
    }

    public function test_registry_accepts_brand_and_rejects_contrast_for_manifesto(): void
    {
        $registry = app(\App\Services\Site\PageLayoutRegistry::class);
        $base = ['schema_version' => 1, 'variants' => ['hero' => 'boxed-left', 'trust' => 'brand-manifesto'], 'eyebrow_policy' => 'all', 'insert_sections' => []];

        $this->assertTrue($registry->isUsable($base + ['surfaces' => ['trust' => 'brand']], 'home'));
        $this->assertFalse($registry->isUsable($base + ['surfaces' => ['trust' => 'contrast']], 'home'));
    }

    public function test_emits_superset_of_classic_trust_fields(): void
    {
        $html = $this->render(true, 4, ['__surface' => 'brand']);

        foreach (['eyebrow', 'title', 'items.0.title', 'items.0.body', 'items.3.title', 'items.3.body'] as $field) {
            $this->assertStringContainsString('data-editable-field="'.$field.'"', $html);
        }
    }
}

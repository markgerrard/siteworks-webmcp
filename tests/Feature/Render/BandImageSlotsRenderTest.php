<?php

namespace Tests\Feature\Render;

use App\Models\HeroVersion;
use App\Models\LayoutPreset;
use App\Models\Site;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Full-pipeline guard for the picked band slots: HeroVersion rows →
 * PageRenderer maps → page view payload → section include vars →
 * editorial band figure. Variant tests pass vars directly, so they can
 * never catch a map that is built but not handed to the view (the
 * launch bug this test pins).
 */
class BandImageSlotsRenderTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\MakesClassicRenderSite;

    public function test_three_picked_band_slots_reach_the_about_band(): void
    {
        $keys = ['primary_color' => '#1e40af', 'accent_color' => '#f97316', 'tertiary_color' => '#0f766e',
            'surface_color' => '#ffffff', 'surface_alt_color' => '#f5f5f5', 'border_color' => '#e5e7eb',
            'text_color' => '#111827', 'text_muted_color' => '#6b7280'];
        [$site, , , $about] = $this->makeClassicSite($keys);

        $site->update(['about_layout' => 'band-demo']);
        LayoutPreset::factory()->for($site)->active()->create([
            'page_kind' => 'about',
            'key' => 'band-demo',
            'recipe' => [
                'schema_version' => 1,
                'variants' => ['story' => 'editorial'],
                'eyebrow_policy' => 'all',
                'insert_sections' => [],
                'options' => ['band_image_count' => 3, 'band_image_height' => 'tall'],
            ],
        ]);
        foreach ([['band', 'b1'], ['band_2', 'b2'], ['band_3', 'b3']] as [$slot, $tag]) {
            HeroVersion::create([
                'site_id' => $site->id, 'slot' => $slot, 'page_type' => 'about',
                'url' => "https://example.test/{$tag}.jpg", 'watermark_url' => null, 'is_active' => true,
            ]);
        }

        $html = app(PageRenderer::class)->render($site->fresh(), $about->id, mode: 'public');

        $this->assertStringContainsString('data-band-images="3"', $html);
        $this->assertStringContainsString('aspect-ratio: 1 / 1', $html);
        foreach (['b1', 'b2', 'b3'] as $tag) {
            $this->assertStringContainsString("https://example.test/{$tag}.jpg", $html);
        }
    }
}

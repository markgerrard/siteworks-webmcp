<?php

namespace Tests\Feature\Render;

use App\Enums\PageKind;
use App\Enums\PreviewLayout;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicesLayoutStackedRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_stacked_public_render_stamps_service_sections_not_home(): void
    {
        $site = Site::factory()->create([
            'business_name' => 'Acme Roofing',
            'theme' => 'trades-bold',
            'preview_layout' => PreviewLayout::OnePage->value,
            'services_layout' => 'editorial',
        ]);

        $home = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'home',
            'kind' => PageKind::Core,
        ]);
        $roofing = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'roofing',
            'kind' => PageKind::Service,
        ]);
        $extensions = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'extensions',
            'kind' => PageKind::Service,
        ]);

        $homeRev = PageRevision::factory()->for($home, 'page')->create([
            'content_data' => ['sections' => [
                ['type' => 'intro', 'title' => 'Home Intro Block', 'eyebrow' => 'Home', 'body' => 'Home intro prose.'],
                [
                    'type' => 'features',
                    'title' => 'Home Features Block',
                    'items' => [['icon' => 'hammer', 'title' => 'Home Item', 'body' => 'Home item body.']],
                ],
            ]],
        ]);
        $roofingRev = PageRevision::factory()->for($roofing, 'page')->create([
            'content_data' => ['sections' => [
                ['type' => 'intro', 'title' => 'Roofing Intro Block', 'eyebrow' => 'Roofing Intro Eyebrow', 'body' => 'Roofing intro prose.'],
                [
                    'type' => 'features',
                    'title' => 'Roofing Features Block',
                    'eyebrow' => 'Roofing Features Eyebrow',
                    'items' => [['icon' => 'hammer', 'title' => 'Roofing Item', 'body' => 'Roofing item body.']],
                ],
            ]],
        ]);
        $extensionsRev = PageRevision::factory()->for($extensions, 'page')->create([
            'content_data' => ['sections' => [
                ['type' => 'intro', 'title' => 'Extensions Intro Block', 'eyebrow' => 'Extensions Intro Eyebrow', 'body' => 'Extensions intro prose.'],
                [
                    'type' => 'features',
                    'title' => 'Extensions Features Block',
                    'eyebrow' => 'Extensions Features Eyebrow',
                    'items' => [['icon' => 'hammer', 'title' => 'Extensions Item', 'body' => 'Extensions item body.']],
                ],
            ]],
        ]);
        $home->update(['published_revision_id' => $homeRev->id]);
        $roofing->update(['published_revision_id' => $roofingRev->id]);
        $extensions->update(['published_revision_id' => $extensionsRev->id]);

        $version = SiteVersion::create([
            'site_id' => $site->id,
            'version' => 1,
            'composition' => [
                'nav' => ['items' => []],
                'footer' => ['columns' => [], 'show_credit' => true],
                'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
                'homepage_page_id' => $home->id,
            ],
            'page_revisions' => [
                ['page_id' => $home->id, 'revision_id' => $homeRev->id],
                ['page_id' => $roofing->id, 'revision_id' => $roofingRev->id],
                ['page_id' => $extensions->id, 'revision_id' => $extensionsRev->id],
            ],
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create([
            'site_id' => $site->id,
            'version_id' => $version->id,
            'updated_at' => now(),
        ]);

        $html = app(PageRenderer::class)->renderStacked($site, mode: 'public');

        $this->assertStringContainsString('Home Intro Block', $html);
        $this->assertStringContainsString('Roofing Intro Block', $html);
        $this->assertStringContainsString('Extensions Intro Block', $html);

        $homeChunk = strstr($html, 'id="roofing"', true);
        $this->assertNotFalse($homeChunk);
        $this->assertStringContainsString('Home Intro Block', $homeChunk);
        $this->assertStringContainsString('Home Features Block', $homeChunk);
        $this->assertStringNotContainsString('data-svc-variant="editorial"', $homeChunk);
        $this->assertStringNotContainsString('data-svc-variant="numbered"', $homeChunk);

        $roofingStart = strpos($html, 'id="roofing"');
        $extensionsStart = strpos($html, 'id="extensions"');
        $this->assertNotFalse($roofingStart);
        $this->assertNotFalse($extensionsStart);
        $this->assertGreaterThan($roofingStart, $extensionsStart);

        $roofingChunk = substr($html, $roofingStart, $extensionsStart - $roofingStart);
        $this->assertStringContainsString('data-svc-variant="editorial"', $roofingChunk);
        $this->assertStringContainsString('data-svc-variant="numbered"', $roofingChunk);
        $this->assertStringContainsString('Roofing Intro Block', $roofingChunk);
        $this->assertStringContainsString('Roofing Features Block', $roofingChunk);
        $this->assertStringContainsString('Roofing Item', $roofingChunk);
        $this->assertStringContainsString('Roofing Intro Eyebrow', $roofingChunk);
        $this->assertStringNotContainsString('Roofing Features Eyebrow', $roofingChunk);

        $extensionsChunk = substr($html, $extensionsStart);
        $this->assertStringContainsString('data-svc-variant="editorial"', $extensionsChunk);
        $this->assertStringContainsString('data-svc-variant="numbered"', $extensionsChunk);
        $this->assertStringContainsString('Extensions Intro Block', $extensionsChunk);
        $this->assertStringContainsString('Extensions Features Block', $extensionsChunk);
        $this->assertStringContainsString('Extensions Intro Eyebrow', $extensionsChunk);
        $this->assertStringNotContainsString('Extensions Features Eyebrow', $extensionsChunk);
    }
}

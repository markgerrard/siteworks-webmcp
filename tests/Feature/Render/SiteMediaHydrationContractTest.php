<?php

namespace Tests\Feature\Render;

use App\Models\Site;
use App\Models\SiteMedia;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteMediaHydrationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_extract_referenced_media_ids_handles_gallery_and_member_entry_lists(): void
    {
        $sections = [
            [
                'type' => 'gallery',
                'image_ids' => [101, '102', 103],
            ],
            [
                'type' => 'team',
                'members' => [
                    ['name' => 'Alice', 'role' => 'Founder', 'image_id' => 201, 'alternate_image_id' => 202],
                    ['name' => 'Bob', 'role' => 'Lead', 'image_id' => '203'],
                    ['name' => 'Charlie', 'role' => 'Designer', 'alternate_image_id' => 204],
                ],
            ],
            [
                'type' => 'custom_section',
                'items' => [
                    ['title' => 'Item A', 'image_id' => 301, 'alternate_image_id' => '302'],
                ],
            ],
            [
                'type' => 'spotlight',
                'image_id' => 401,
                'alternate_image_id' => 402,
            ],
            [
                'type' => 'malformed',
                'image_ids' => ['invalid', null, -5, ''],
                'members' => [
                    ['image_id' => 'abc', 'alternate_image_id' => null],
                ],
            ],
        ];

        $extracted = PageRenderer::extractReferencedMediaIds($sections);

        $expected = [101, 102, 103, 201, 202, 203, 204, 301, 302, 401, 402];
        sort($extracted);
        sort($expected);

        $this->assertSame($expected, $extracted);
    }

    public function test_hydrate_media_by_id_scopes_strictly_to_site_id(): void
    {
        $siteA = Site::factory()->create(['business_name' => 'Site A']);
        $siteB = Site::factory()->create(['business_name' => 'Site B']);

        $mediaA1 = SiteMedia::factory()->create(['site_id' => $siteA->id]);
        $mediaA2 = SiteMedia::factory()->create(['site_id' => $siteA->id]);
        $mediaB1 = SiteMedia::factory()->create(['site_id' => $siteB->id]);

        $sections = [
            [
                'type' => 'team',
                'members' => [
                    ['name' => 'Member 1', 'image_id' => $mediaA1->id, 'alternate_image_id' => $mediaA2->id],
                    ['name' => 'Foreign Member', 'image_id' => $mediaB1->id],
                ],
            ],
        ];

        $renderer = app(PageRenderer::class);
        $hydrated = $renderer->hydrateMediaById($siteA, $sections);

        $this->assertTrue($hydrated->has($mediaA1->id));
        $this->assertTrue($hydrated->has($mediaA2->id));
        $this->assertFalse(
            $hydrated->has($mediaB1->id),
            'Cross-site media id from Site B must not be hydrated under Site A',
        );
        $this->assertCount(2, $hydrated);
    }

    public function test_hydrate_media_by_id_returns_empty_collection_for_empty_or_invalid_sections(): void
    {
        $site = Site::factory()->create();
        $renderer = app(PageRenderer::class);

        $empty = $renderer->hydrateMediaById($site, []);
        $this->assertTrue($empty->isEmpty());

        $noMedia = $renderer->hydrateMediaById($site, [
            ['type' => 'hero', 'title' => 'No media'],
            ['type' => 'cta', 'button_label' => 'Click'],
        ]);
        $this->assertTrue($noMedia->isEmpty());
    }
}

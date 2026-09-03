<?php

namespace Tests\Feature\Render;

use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PublicPageCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ServicesLayoutCacheTest extends TestCase
{
    use RefreshDatabase;

    private string $host = 'svclayout.example';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'site.use_versioned_renderer' => true,
            'site.public_cache_enabled' => true,
        ]);
    }

    public function test_services_layout_flip_plus_invalidate_changes_public_markup(): void
    {
        $site = Site::factory()->create([
            'custom_domain' => $this->host,
            'custom_domain_status' => 'active',
            'services_layout' => 'classic',
        ]);

        $home = GeneratedPage::factory()->for($site)->create(['page_type' => 'home']);
        $service = GeneratedPage::factory()->for($site)->create(['page_type' => 'extensions']);

        $homeRev = PageRevision::factory()->for($home, 'page')->create([
            'content_data' => ['sections' => [['type' => 'hero', 'title' => 'Home']]],
        ]);
        $serviceRev = PageRevision::factory()->for($service, 'page')->create([
            'content_data' => ['sections' => [[
                'type' => 'intro',
                'title' => 'Extensions & Loft Conversions',
                'eyebrow' => 'About This Service',
                'body' => 'First paragraph of prose.',
            ]]],
        ]);
        $home->update(['published_revision_id' => $homeRev->id]);
        $service->update(['published_revision_id' => $serviceRev->id]);

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
                ['page_id' => $service->id, 'revision_id' => $serviceRev->id],
            ],
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create([
            'site_id' => $site->id,
            'version_id' => $version->id,
            'updated_at' => now(),
        ]);

        $url = 'http://'.$this->host.'/extensions';

        $classic = $this->get($url)->assertSuccessful()->getContent();
        $this->assertStringContainsString('py-20', $classic);
        $this->assertStringNotContainsString('data-svc-variant="spec"', $classic);

        $site->services_layout = 'precision';
        $site->save();

        // Livewire injects assets into the HTTP response after cache put(),
        // so full-HTML identity is not a stable cache-hit signal. The spec
        // variant marker is: a miss after the column flip would already
        // contain it; a hit must still be classic.
        $stale = $this->get($url)->assertSuccessful()->getContent();
        $this->assertStringNotContainsString('data-svc-variant="spec"', $stale);

        app(PublicPageCache::class)->invalidate($site);

        $fresh = $this->get($url)->assertSuccessful()->getContent();
        $this->assertStringContainsString('data-svc-variant="spec"', $fresh);
    }
}

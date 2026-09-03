<?php

namespace Tests\Feature\Render;

use App\Enums\Archetype;
use App\Enums\PageKind;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossPageScrubTest extends TestCase
{
    use RefreshDatabase;

    public function test_motion_and_previous_stamps_are_scrubbed_from_cross_page_injections(): void
    {
        $site = Site::factory()->create(['business_name' => 'Acme Test', 'theme' => 'trades-bold']);
        BusinessProfile::factory()->for($site)->create([
            'profile_data' => [
                'archetype' => Archetype::EmergencyTrade->value,
                'lead_form_policy' => 'all',
                'contact' => ['phones' => ['0161 123 4567'], 'emails' => ['hello@acme.test']],
                'geo' => ['service_area' => 'Manchester'],
            ],
        ]);

        $homePage = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'home',
            'kind' => PageKind::Core,
        ]);
        $servicePage = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'boiler-repair',
            'kind' => PageKind::Service,
            'nav_label' => 'Boiler Repair',
        ]);

        $homeLeadForm = [
            'type' => 'lead_form',
            'title' => 'Home Form',
            '__stored_index' => 4,
            'variant' => 'centered',
            '__options' => ['motion_tier' => 'expressive', 'marquee_band' => true],
            '__surface' => 'contrast',
            '__motion' => true,
            '__motion_tier' => 'expressive',
            '__motion_options' => ['stat_count_up' => true],
            '__previous' => 'services',
            '__previous_surface' => 'brand',
        ];

        $homeRev = PageRevision::factory()->for($homePage, 'page')->create([
            'content_data' => [
                'sections' => [
                    ['type' => 'hero', 'title' => 'Welcome'],
                    $homeLeadForm,
                    ['type' => 'cta', 'title' => 'CTA'],
                ],
            ],
        ]);
        $homePage->update(['published_revision_id' => $homeRev->id]);

        $serviceRev = PageRevision::factory()->for($servicePage, 'page')->create([
            'content_data' => [
                'sections' => [
                    ['type' => 'intro', 'title' => 'Boiler Repair'],
                    ['type' => 'cta', 'title' => 'Call Us'],
                ],
            ],
        ]);
        $servicePage->update(['published_revision_id' => $serviceRev->id]);

        $version = SiteVersion::create([
            'site_id' => $site->id,
            'version' => 1,
            'composition' => [
                'nav' => ['items' => []],
                'footer' => ['columns' => [], 'show_credit' => true],
                'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
                'homepage_page_id' => $homePage->id,
            ],
            'page_revisions' => [
                ['page_id' => $homePage->id, 'revision_id' => $homeRev->id],
                ['page_id' => $servicePage->id, 'revision_id' => $serviceRev->id],
            ],
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

        $renderer = app(PageRenderer::class);
        $method = new \ReflectionMethod($renderer, 'injectServiceLeadForm');
        $method->setAccessible(true);

        $sections = [
            ['type' => 'intro', 'title' => 'Boiler Repair'],
            ['type' => 'cta', 'title' => 'Call Us'],
        ];

        $injected = $method->invoke($renderer, $site, $servicePage, $sections);

        $injectedForm = collect($injected)->firstWhere('type', 'lead_form');
        $this->assertNotNull($injectedForm, 'lead_form must be injected into service sections');

        $forbiddenKeys = [
            '__stored_index',
            'variant',
            '__options',
            '__surface',
            '__motion',
            '__motion_tier',
            '__motion_options',
            '__previous',
            '__previous_surface',
        ];

        foreach ($forbiddenKeys as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $injectedForm,
                "Injected lead_form must not carry [{$key}] metadata from the home page",
            );
        }

        $phoneStrip = collect($injected)->firstWhere('type', 'phone_cta_strip');
        $this->assertNotNull($phoneStrip, 'phone_cta_strip must be injected');
        foreach ($forbiddenKeys as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $phoneStrip,
                "Injected phone_cta_strip must not carry [{$key}] metadata",
            );
        }
    }
}

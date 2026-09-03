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

/**
 * Cross-kind isolation: home + about + service presets on one stacked
 * input. Preset keys come from config (first non-classic per kind);
 * stamped variant names come from those recipes so extra families are
 * auto-covered when later waves merge.
 */
class MixedKindStampingMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    private function callHome(Site $site, GeneratedPage $page, array $sections): array
    {
        $renderer = app(PageRenderer::class);
        $method = new \ReflectionMethod($renderer, 'applyHomeLayout');
        $method->setAccessible(true);

        return $method->invoke($renderer, $site, $page, $sections, 'public');
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @param  list<string>|null  $pageTypes
     * @return array<int, array<string, mixed>>
     */
    private function callPageKind(Site $site, ?GeneratedPage $page, array $sections, string $kind, ?array $pageTypes = null): array
    {
        $renderer = app(PageRenderer::class);
        $method = new \ReflectionMethod($renderer, 'applyPageKindLayout');
        $method->setAccessible(true);

        return $method->invoke($renderer, $site, $page, $sections, $kind, $pageTypes);
    }

    private function firstNonClassicPreset(string $configKey): string
    {
        foreach (array_keys(config($configKey) ?? []) as $key) {
            if (is_string($key) && $key !== 'classic') {
                return $key;
            }
        }

        $this->fail("{$configKey} has no non-classic preset");
    }

    /**
     * @return array{home: string, about: string, service: string, homeRecipe: array<string, mixed>, aboutRecipe: array<string, mixed>, serviceRecipe: array<string, mixed>}
     */
    private function nonClassicPresets(): array
    {
        $home = $this->firstNonClassicPreset('site_home_layouts');
        $about = $this->firstNonClassicPreset('site_about_layouts');
        $service = $this->firstNonClassicPreset('site_service_layouts');

        $homeRecipe = config("site_home_layouts.{$home}");
        $aboutRecipe = config("site_about_layouts.{$about}");
        $serviceRecipe = config("site_service_layouts.{$service}");

        $this->assertIsArray($homeRecipe);
        $this->assertIsArray($aboutRecipe);
        $this->assertIsArray($serviceRecipe);

        return [
            'home' => $home,
            'about' => $about,
            'service' => $service,
            'homeRecipe' => $homeRecipe,
            'aboutRecipe' => $aboutRecipe,
            'serviceRecipe' => $serviceRecipe,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stackedSections(): array
    {
        return [
            ['type' => 'hero', '__page_type' => 'home', 'title' => 'Home Hero Block'],
            ['type' => 'values', '__page_type' => 'home', 'title' => 'Home Convictions Block', 'eyebrow' => 'Home Values Eyebrow'],
            ['type' => 'services', '__page_type' => 'home', 'title' => 'Home Services Block'],
            ['type' => 'trust', '__page_type' => 'home', 'title' => 'Home Trust Block', 'eyebrow' => 'Home Trust Eyebrow'],
            ['type' => 'process', '__page_type' => 'home', 'title' => 'Home Process Block', 'eyebrow' => 'Home Process Eyebrow'],
            ['type' => 'hero', '__page_type' => 'about', 'title' => 'About Hero Block'],
            ['type' => 'story', '__page_type' => 'about', 'title' => 'About Story Block', 'eyebrow' => 'About Story Eyebrow'],
            ['type' => 'values', '__page_type' => 'about', 'title' => 'About Convictions Block', 'eyebrow' => 'About Values Eyebrow'],
            ['type' => 'intro', '__page_type' => 'roofing', 'title' => 'Roofing Intro Block', 'eyebrow' => 'Roofing Intro Eyebrow'],
            ['type' => 'features', '__page_type' => 'roofing', 'title' => 'Roofing Features Block', 'eyebrow' => 'Roofing Features Eyebrow'],
            ['type' => 'intro', '__page_type' => 'extensions', 'title' => 'Extensions Intro Block', 'eyebrow' => 'Extensions Intro Eyebrow'],
            ['type' => 'features', '__page_type' => 'extensions', 'title' => 'Extensions Features Block', 'eyebrow' => 'Extensions Features Eyebrow'],
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $recipe
     */
    private function assertSectionFollowsKindRecipe(array $section, array $recipe, string $type): void
    {
        $expected = $recipe['variants'][$type] ?? null;
        if (! is_string($expected) || $expected === '') {
            $this->assertArrayNotHasKey(
                'variant',
                $section,
                "{$type} ({$section['__page_type']}) should not be stamped by this kind",
            );

            return;
        }

        $this->assertSame(
            $expected,
            $section['variant'] ?? null,
            "{$type} ({$section['__page_type']}) should take this kind's {$expected}",
        );
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  list<int>  $indexes
     * @param  array<string, mixed>  $recipe
     */
    private function assertEyebrowPolicyOn(array $sections, array $indexes, array $recipe): void
    {
        $policy = $recipe['eyebrow_policy'] ?? 'all';
        $eyebrowSections = is_array($recipe['eyebrow_sections'] ?? null)
            ? $recipe['eyebrow_sections']
            : array_keys($recipe['variants'] ?? []);
        $seen = false;

        foreach ($indexes as $i) {
            $type = $sections[$i]['type'] ?? '';
            if (! in_array($type, $eyebrowSections, true)) {
                $this->assertArrayNotHasKey(
                    '__suppress_eyebrow',
                    $sections[$i],
                    "section {$i} ({$type}) is outside eyebrow_sections",
                );

                continue;
            }

            if ($policy === 'first-only' && $seen) {
                $this->assertTrue(
                    $sections[$i]['__suppress_eyebrow'] ?? false,
                    "section {$i} ({$type}) should be first-only suppressed",
                );
            } else {
                $this->assertArrayNotHasKey(
                    '__suppress_eyebrow',
                    $sections[$i],
                    "section {$i} ({$type}) should keep its eyebrow",
                );
            }

            if ($policy === 'first-only') {
                $seen = true;
            }
        }
    }

    public function test_transform_stamps_each_kind_independently_on_stacked_input(): void
    {
        $presets = $this->nonClassicPresets();
        $site = new Site([
            'home_layout' => $presets['home'],
            'about_layout' => $presets['about'],
            'services_layout' => $presets['service'],
        ]);
        $home = new GeneratedPage(['page_type' => 'home']);

        $out = $this->callHome($site, $home, $this->stackedSections());
        $out = $this->callPageKind($site, $home, $out, 'service', ['roofing', 'extensions']);
        $out = $this->callPageKind($site, $home, $out, 'about', ['about']);

        $this->assertCount(12, $out);

        $this->assertArrayHasKey('trust', $presets['homeRecipe']['variants'], 'home non-classic recipe must stamp trust');
        $this->assertArrayHasKey('process', $presets['homeRecipe']['variants'], 'home non-classic recipe must stamp process');

        $this->assertSectionFollowsKindRecipe($out[0], $presets['homeRecipe'], 'hero');
        $this->assertArrayNotHasKey('variant', $out[1], 'home-tagged values must not be stamped by home or about');
        $this->assertSectionFollowsKindRecipe($out[2], $presets['homeRecipe'], 'services');
        $this->assertSectionFollowsKindRecipe($out[3], $presets['homeRecipe'], 'trust');
        $this->assertSectionFollowsKindRecipe($out[4], $presets['homeRecipe'], 'process');

        $this->assertArrayNotHasKey('variant', $out[5], 'about hero is not a v1 about recipe target');
        $this->assertSectionFollowsKindRecipe($out[6], $presets['aboutRecipe'], 'story');
        $this->assertSectionFollowsKindRecipe($out[7], $presets['aboutRecipe'], 'values');

        $this->assertSectionFollowsKindRecipe($out[8], $presets['serviceRecipe'], 'intro');
        $this->assertSectionFollowsKindRecipe($out[9], $presets['serviceRecipe'], 'features');
        $this->assertSectionFollowsKindRecipe($out[10], $presets['serviceRecipe'], 'intro');
        $this->assertSectionFollowsKindRecipe($out[11], $presets['serviceRecipe'], 'features');

        $aboutValuesVariant = $presets['aboutRecipe']['variants']['values'] ?? null;
        if (is_string($aboutValuesVariant)) {
            $this->assertNotSame(
                $aboutValuesVariant,
                $out[1]['variant'] ?? null,
                'home-tagged values must stay untouched by the about recipe',
            );
        }

        $homeHeroVariant = $presets['homeRecipe']['variants']['hero'] ?? null;
        if (is_string($homeHeroVariant) && $homeHeroVariant !== '') {
            $this->assertNotSame($homeHeroVariant, $out[5]['variant'] ?? null);
            $this->assertNotSame($homeHeroVariant, $out[6]['variant'] ?? null);
            $this->assertNotSame($homeHeroVariant, $out[8]['variant'] ?? null);
        }

        $homeServicesSurface = $presets['homeRecipe']['surfaces']['services'] ?? null;
        if (is_string($homeServicesSurface) && $homeServicesSurface !== '') {
            $this->assertSame($homeServicesSurface, $out[2]['__surface'] ?? null);
            $this->assertArrayNotHasKey('__surface', $out[1]);
            $this->assertArrayNotHasKey('__surface', $out[6]);
        }

        $this->assertArrayNotHasKey('__suppress_eyebrow', $out[1]);
        $this->assertEyebrowPolicyOn($out, [2, 3, 4], $presets['homeRecipe']);
        $this->assertEyebrowPolicyOn($out, [5, 6, 7], $presets['aboutRecipe']);
        $this->assertEyebrowPolicyOn($out, [8, 9], $presets['serviceRecipe']);
        $this->assertEyebrowPolicyOn($out, [10, 11], $presets['serviceRecipe']);
    }

    public function test_stacked_public_render_keeps_each_kind_in_its_own_chunk(): void
    {
        $presets = $this->nonClassicPresets();

        $site = Site::factory()->create([
            'business_name' => 'Acme Roofing',
            'theme' => 'trades-bold',
            'preview_layout' => PreviewLayout::OnePage->value,
            'home_layout' => $presets['home'],
            'about_layout' => $presets['about'],
            'services_layout' => $presets['service'],
        ]);

        $home = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'home',
            'kind' => PageKind::Core,
        ]);
        $about = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'about',
            'kind' => PageKind::Core,
        ]);
        $roofing = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'roofing',
            'kind' => PageKind::Service,
        ]);

        $homeRev = PageRevision::factory()->for($home, 'page')->create([
            'content_data' => ['sections' => [
                ['type' => 'hero', 'title' => 'Home Hero Block', 'subtitle' => 'Home hero subtitle.', 'cta_label' => 'Call'],
                [
                    'type' => 'values',
                    'title' => 'Home Convictions Block',
                    'eyebrow' => 'Home Values Eyebrow',
                    'items' => [['title' => 'Home Value Item', 'body' => 'Home value body.']],
                ],
                [
                    'type' => 'services',
                    'title' => 'Home Services Block',
                    'items' => [['title' => 'Home Service Item', 'body' => 'Home service body.']],
                ],
                [
                    'type' => 'trust',
                    'title' => 'Home Trust Block',
                    'eyebrow' => 'Home Trust Eyebrow',
                    'items' => [['title' => 'Home Trust Item', 'body' => 'Home trust body.']],
                ],
                [
                    'type' => 'process',
                    'title' => 'Home Process Block',
                    'eyebrow' => 'Home Process Eyebrow',
                    'items' => [['title' => 'Home Process Item', 'body' => 'Home process body.', 'step' => '01']],
                ],
            ]],
        ]);
        $aboutRev = PageRevision::factory()->for($about, 'page')->create([
            'content_data' => ['sections' => [
                [
                    'type' => 'story',
                    'title' => 'About Story Block',
                    'eyebrow' => 'About Story Eyebrow',
                    'body' => 'About story prose.',
                ],
                [
                    'type' => 'values',
                    'title' => 'About Convictions Block',
                    'eyebrow' => 'About Values Eyebrow',
                    'items' => [['title' => 'About Value Item', 'body' => 'About value body.']],
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
        $home->update(['published_revision_id' => $homeRev->id]);
        $about->update(['published_revision_id' => $aboutRev->id]);
        $roofing->update(['published_revision_id' => $roofingRev->id]);

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
                ['page_id' => $about->id, 'revision_id' => $aboutRev->id],
                ['page_id' => $roofing->id, 'revision_id' => $roofingRev->id],
            ],
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create([
            'site_id' => $site->id,
            'version_id' => $version->id,
            'updated_at' => now(),
        ]);

        $html = app(PageRenderer::class)->renderStacked($site, mode: 'public');

        $this->assertStringContainsString('Home Convictions Block', $html);
        $this->assertStringContainsString('About Story Block', $html);
        $this->assertStringContainsString('About Convictions Block', $html);
        $this->assertStringContainsString('Roofing Intro Block', $html);

        $aboutStart = strpos($html, 'id="about"');
        $roofingStart = strpos($html, 'id="roofing"');
        $this->assertNotFalse($aboutStart);
        $this->assertNotFalse($roofingStart);
        $this->assertGreaterThan($aboutStart, $roofingStart);

        $homeChunk = substr($html, 0, $aboutStart);
        $aboutChunk = substr($html, $aboutStart, $roofingStart - $aboutStart);
        $roofingChunk = substr($html, $roofingStart);

        $this->assertStringContainsString('Home Convictions Block', $homeChunk);
        $this->assertStringContainsString('Home Value Item', $homeChunk);
        $this->assertStringContainsString('Home Services Block', $homeChunk);
        $this->assertStringContainsString('Home Trust Block', $homeChunk);
        $this->assertStringContainsString('Home Process Block', $homeChunk);
        $this->assertStringNotContainsString('About Convictions Block', $homeChunk);
        $this->assertStringNotContainsString('Roofing Intro Block', $homeChunk);

        $this->assertStringContainsString('About Story Block', $aboutChunk);
        $this->assertStringContainsString('About Convictions Block', $aboutChunk);
        $this->assertStringContainsString('About Value Item', $aboutChunk);
        $this->assertStringNotContainsString('Home Convictions Block', $aboutChunk);
        $this->assertStringNotContainsString('Roofing Intro Block', $aboutChunk);

        $this->assertStringContainsString('Roofing Intro Block', $roofingChunk);
        $this->assertStringContainsString('Roofing Features Block', $roofingChunk);
        $this->assertStringNotContainsString('Home Convictions Block', $roofingChunk);
        $this->assertStringNotContainsString('About Convictions Block', $roofingChunk);

        $this->assertFileBackedVariantOnlyIn($homeChunk, 'values', $presets['aboutRecipe']['variants']['values'] ?? null, expectPresent: false);
        $this->assertFileBackedVariantOnlyIn($homeChunk, 'services', $presets['homeRecipe']['variants']['services'] ?? null, expectPresent: true);
        $this->assertFileBackedVariantOnlyIn($homeChunk, 'trust', $presets['homeRecipe']['variants']['trust'] ?? null, expectPresent: true);
        $this->assertFileBackedVariantOnlyIn($homeChunk, 'process', $presets['homeRecipe']['variants']['process'] ?? null, expectPresent: true);
        $this->assertFileBackedVariantOnlyIn($aboutChunk, 'story', $presets['aboutRecipe']['variants']['story'] ?? null, expectPresent: true);
        $this->assertFileBackedVariantOnlyIn($aboutChunk, 'values', $presets['aboutRecipe']['variants']['values'] ?? null, expectPresent: true);
        $this->assertFileBackedVariantOnlyIn($roofingChunk, 'intro', $presets['serviceRecipe']['variants']['intro'] ?? null, expectPresent: true);
        $this->assertFileBackedVariantOnlyIn($roofingChunk, 'features', $presets['serviceRecipe']['variants']['features'] ?? null, expectPresent: true);

        $homeHeroVariant = $presets['homeRecipe']['variants']['hero'] ?? null;
        if (is_string($homeHeroVariant) && $homeHeroVariant !== '') {
            $this->assertStringContainsString('data-hero-variant="'.$homeHeroVariant.'"', $homeChunk);
            $this->assertStringNotContainsString('data-hero-variant="'.$homeHeroVariant.'"', $aboutChunk);
            $this->assertStringNotContainsString('data-hero-variant="'.$homeHeroVariant.'"', $roofingChunk);
        }
    }

    public function test_about_override_keeps_null_variant_and_restamps_dead_token_like_home(): void
    {
        $site = Site::factory()->create([
            'about_layout' => 'classic',
            'home_layout' => 'editorial',
        ]);
        $about = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'about',
            'kind' => PageKind::Core,
            'layout_preset_key' => 'editorial',
        ]);
        $home = GeneratedPage::factory()->for($site)->create([
            'page_type' => 'home',
            'kind' => PageKind::Core,
        ]);

        $aboutOut = $this->callPageKind($site, $about, [
            ['type' => 'story', 'variant' => null, '__page_type' => 'about'],
            ['type' => 'story', 'variant' => 'no-such-variant', '__page_type' => 'about'],
        ], 'about', ['about']);

        $this->assertArrayHasKey('variant', $aboutOut[0]);
        $this->assertNull($aboutOut[0]['variant']);

        $aboutStory = config('site_about_layouts.editorial.variants.story');
        $this->assertIsString($aboutStory);
        $this->assertSame($aboutStory, $aboutOut[1]['variant']);

        $homeOut = $this->callHome($site, $home, [
            ['type' => 'hero', 'variant' => 'no-such-variant'],
        ]);
        $homeHero = config('site_home_layouts.editorial.variants.hero');
        $this->assertIsString($homeHero);
        $this->assertSame($homeHero, $homeOut[0]['variant']);
    }

    private function assertFileBackedVariantOnlyIn(string $chunk, string $family, mixed $variant, bool $expectPresent): void
    {
        if (! is_string($variant) || $variant === '') {
            return;
        }

        $partial = resource_path("views/site/sections/variants/{$family}/{$variant}.blade.php");
        if (! is_file($partial)) {
            return;
        }
        if (! str_contains((string) file_get_contents($partial), 'data-svc-variant=')) {
            return;
        }

        $needle = 'data-svc-variant="'.$variant.'"';
        if ($expectPresent) {
            $this->assertStringContainsString($needle, $chunk, "{$family}/{$variant} missing from expected chunk");
        } else {
            $this->assertStringNotContainsString($needle, $chunk, "{$family}/{$variant} leaked into the wrong chunk");
        }
    }
}

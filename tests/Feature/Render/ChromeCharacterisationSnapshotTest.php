<?php

namespace Tests\Feature\Render;

use App\Enums\PageKind;
use App\Enums\ProjectItemSource;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\LogoConcept;
use App\Models\ProjectItem;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChromeCharacterisationSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Shared contact/lead custom fields: one select (with options), one radio, one date.
     *
     * @return list<array<string, mixed>>
     */
    public static function formFields(): array
    {
        return [
            ['name' => 'service', 'type' => 'select', 'label' => 'Service', 'options' => ['A', 'B']],
            ['name' => 'urgency', 'type' => 'radio', 'label' => 'Urgency', 'options' => ['Now', 'Later']],
            ['name' => 'when', 'type' => 'date', 'label' => 'When'],
        ];
    }

    /**
     * Everything the lead_form extraction can break that formFields() does not pin:
     * benefits, the tel/postcode/address inference arms, an operator textarea, a
     * required field, and 11 operator fields (MAX_FIELDS = 10 ⇒ the clamp drops one).
     *
     * @return list<array<string, mixed>>
     */
    public static function fullFormFields(): array
    {
        $fields = [
            ['name' => 'phone', 'type' => 'tel', 'label' => 'Phone', 'required' => true],
            ['name' => 'postcode', 'type' => 'text', 'label' => 'Postcode'],
            ['name' => 'address', 'type' => 'text', 'label' => 'Address'],
            ['name' => 'details', 'type' => 'textarea', 'label' => 'Details'],
            ['name' => 'service', 'type' => 'select', 'label' => 'Service', 'options' => ['A', 'B']],
            ['name' => 'urgency', 'type' => 'radio', 'label' => 'Urgency', 'options' => ['Now', 'Later']],
            ['name' => 'when', 'type' => 'date', 'label' => 'When'],
        ];
        for ($i = 1; $i <= 4; $i++) {
            $fields[] = ['name' => "extra{$i}", 'type' => 'text', 'label' => "Extra {$i}"];
        }

        return $fields; // 11 operator fields
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $items = [
            ['title' => 'Flat roofs', 'body' => 'b'],
            ['title' => 'Pitched', 'body' => 'b'],
        ];
        $hero = ['type' => 'hero', 'title' => 'Roofing you can trust', 'accent_word' => 'trust', 'subtitle' => 's'];
        $services = static fn (string $variant): array => [[
            'type' => 'services',
            'variant' => $variant,
            'title' => 'Our Services',
            'accent_word' => 'Services',
            'items' => $items,
        ]];

        $groupedNav = [
            ['type' => 'external', 'label' => 'About', 'url' => '/about'],
            [
                'type' => 'group',
                'label' => 'Services',
                'children' => [
                    ['type' => 'external', 'label' => 'Roofing', 'url' => '/roofing'],
                ],
            ],
        ];

        return [
            'home-wordmark-topbar' => ['home', [], ['top_bar_enabled' => true], []],
            'home-logo-large-dark-header' => ['home', ['logo_size' => 'large', 'header_bg' => '#111827'], [], []],
            'home-compact-nocredit' => ['home', ['logo_size' => 'compact'], ['footer' => ['show_credit' => false]], []],
            'contact-details-absorbed-form' => ['contact', [], [], ['fields' => self::formFields()]],
            'lead-form-page' => ['roofing', [], ['home_lead_form_enabled' => true], ['extra_fields' => self::formFields()]],
            'lead-form-full' => ['roofing', [], ['home_lead_form_enabled' => true], [
                'extra_fields' => self::fullFormFields(),
                'benefits' => ['Free no-obligation quote', 'Local specialists', 'Fully insured'],
            ]],
            'projects-gallery' => ['projects', [], [], []],
            'home-grouped-nav' => ['home', [], ['top_bar_enabled' => true], ['nav' => $groupedNav]],
            'home-image-logo' => ['home', [], [], ['logo' => true]],
            'home-phone-link' => ['home', [], ['contact' => ['phones' => ['0161 123 4567']]], []],
            'projects-gallery-empty' => ['projects', [], [], ['gallery' => 'empty']],
            'projects-gallery-example-badge' => ['projects', ['honest_project_framing' => true], [], ['gallery' => 'example']],
            'accent-hero-compact' => ['planning', [], [], ['sections' => [[
                'type' => 'hero_compact',
                'title' => 'Roofing you can trust',
                'accent_word' => 'trust',
            ]]]],
            'accent-hero-scene' => ['home', [], [], ['scene' => true, 'sections' => [$hero]]],
            'accent-features-cards' => ['home', [], [], ['sections' => [[
                'type' => 'features',
                'variant' => 'cards',
                'title' => 'Our Services',
                'accent_word' => 'Services',
                'items' => $items,
            ]]]],
            'accent-services-classic' => ['home', [], [], ['sections' => $services('classic')]],
            'accent-services-editorial-grid' => ['home', [], [], ['sections' => $services('editorial-grid')]],
            'accent-services-featured-ledger' => ['home', [], [], ['sections' => $services('featured-ledger')]],
            'accent-services-featured-stories' => ['home', [], [], ['sections' => $services('featured-stories')]],
            'accent-services-numbered-rows' => ['home', [], [], ['sections' => $services('numbered-rows')]],
            'accent-services-photo-cards' => ['home', [], [], ['sections' => $services('photo-cards')]],
            'accent-services-split-bands' => ['home', [], [], ['sections' => $services('split-bands')]],
        ];
    }

    /**
     * @param  array<string, mixed>  $siteAttrs
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $opts
     */
    #[DataProvider('cases')]
    public function test_null_knob_render_matches_fixture(string $pageType, array $siteAttrs, array $profile, array $opts): void
    {
        $site = Site::factory()->create(['business_name' => 'Acme Roofing', 'theme' => 'trades-bold'] + $siteAttrs);

        $footer = ['columns' => [], 'show_credit' => true];
        if (isset($profile['footer']) && is_array($profile['footer'])) {
            $footer = array_merge($footer, $profile['footer']);
            unset($profile['footer']);
        }

        // Plan matrix uses the legacy home-only flag on a service page.
        // Today's policy maps that to Home, which skips service lead_form.
        // home_services is required for the roofing form to actually render.
        if ($pageType === 'roofing' && ($profile['home_lead_form_enabled'] ?? false) === true) {
            $profile['lead_form_policy'] = 'home_services';
        }

        if ($profile !== []) {
            BusinessProfile::factory()->for($site)->create(['profile_data' => $profile]);
        }

        if (($opts['logo'] ?? false) === true) {
            Storage::fake('s3');
            LogoConcept::factory()->for($site)->selected()->create([
                'path' => 'logos/acme-logo.png',
            ]);
        }

        if (($opts['scene'] ?? false) === true) {
            $this->attachTwoSlideScene($site);
        }

        $page = GeneratedPage::factory()->for($site)->create([
            'page_type' => $pageType,
            'kind' => $pageType === 'roofing' ? PageKind::Service : PageKind::Core,
        ]);

        $sections = self::sectionsFor($pageType, $opts);

        $galleryMode = $opts['gallery'] ?? 'populated';
        if ($pageType === 'projects' && $galleryMode !== 'empty') {
            $source = $galleryMode === 'example'
                ? ProjectItemSource::AiGenerated
                : null;
            $items = [];
            foreach (['Kitchens', 'Extensions', 'kitchens '] as $index => $category) {
                $attrs = [
                    'category' => $category,
                    'title' => 'Project '.trim($category).' '.$index,
                    'description' => 'd',
                    'sort_order' => $index,
                ];
                if ($source !== null) {
                    $attrs['source'] = $source;
                }
                $items[] = ProjectItem::factory()->gallery()->published()->for($site)->create($attrs);
            }
            foreach ($sections as $i => $section) {
                if (($section['type'] ?? null) === 'project_gallery') {
                    $sections[$i]['item_ids'] = array_map(
                        fn (ProjectItem $item): int => $item->id,
                        $items,
                    );
                }
            }
        }

        $revision = PageRevision::factory()->for($page, 'page')->create([
            'content_data' => ['sections' => $sections],
        ]);
        $page->update(['published_revision_id' => $revision->id]);

        self::publish($site, $page->fresh(), $footer, $opts['nav'] ?? []);

        $html = $this->normalise(app(PageRenderer::class)->render($site, $page->id, mode: 'public'));
        $fixture = base_path("tests/Fixtures/ChromeSnapshots/{$this->dataName()}.html");
        if (getenv('CHROME_SNAPSHOTS_UPDATE') === '1') {
            $dir = dirname($fixture);
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($fixture, $html);
        }
        $this->assertFileExists($fixture, 'run once with CHROME_SNAPSHOTS_UPDATE=1');
        $this->assertSame(file_get_contents($fixture), $html);
    }

    private function normalise(string $html): string
    {
        $html = preg_replace('/name="_token" value="[^"]+"/', 'name="_token" value="X"', $html) ?? $html;
        $html = preg_replace('/\?v=\d+/', '?v=N', $html) ?? $html;
        $html = preg_replace('/build\/assets\/[a-z0-9-]+\.[a-f0-9]{8}\./', 'build/assets/X.', $html) ?? $html;
        // Vite 8 emits `site-RD9xqmdA.css` (dash + mixed-case), not `site.deadbeef.`.
        $html = preg_replace('/build\/assets\/[A-Za-z0-9._-]+/', 'build/assets/X', $html) ?? $html;
        $html = preg_replace('/\b(lf|cf)-\d+-\d+/', '$1-N-N', $html) ?? $html;

        return preg_replace('/wire:id="[^"]+"/', 'wire:id="X"', $html) ?? $html;
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return list<array<string, mixed>>
     */
    private static function sectionsFor(string $pageType, array $opts): array
    {
        if (isset($opts['sections']) && is_array($opts['sections'])) {
            return $opts['sections'];
        }

        return match ($pageType) {
            'home' => [
                ['type' => 'hero', 'title' => 'Roofing you can trust', 'accent_word' => 'trust', 'subtitle' => 's'],
                ['type' => 'services', 'title' => 'Our Services', 'items' => [['title' => 'Flat roofs', 'body' => 'b'], ['title' => 'Pitched', 'body' => 'b']]],
                ['type' => 'trust', 'title' => 'Why choose us', 'items' => [['title' => 'Local', 'body' => 'b'], ['title' => 'Insured', 'body' => 'b']]],
            ],
            // details.blade.php only absorbs contact_form when an email item
            // is present ($hasForm = $hasEmail && $form). Empty items would
            // skip the standalone form AND skip the absorbed form.
            'contact' => [
                ['type' => 'details', 'title' => 'Contact', 'items' => [['label' => 'Email', 'value' => 'hello@acme.test']]],
                array_filter([
                    'type' => 'contact_form',
                    'title' => 'Get in touch',
                    'fields' => $opts['fields'] ?? null,
                ], fn (mixed $v): bool => $v !== null),
            ],
            'roofing' => [
                ['type' => 'hero', 'title' => 'Roofing', 'accent_word' => 'Roofing'],
                array_filter([
                    'type' => 'lead_form',
                    'title' => 'Quote',
                    'extra_fields' => $opts['extra_fields'] ?? null,
                    ...(array_key_exists('benefits', $opts) ? ['benefits' => $opts['benefits']] : []),
                ], fn (mixed $v): bool => $v !== null),
            ],
            'projects' => [['type' => 'projects_hero', 'title' => 'Projects'], ['type' => 'project_gallery', 'title' => 'Recent Work', 'item_ids' => []]],
            default => [],
        };
    }

    private function attachTwoSlideScene(Site $site): void
    {
        $slides = [];
        foreach (['Roofing you can trust', 'Quality workmanship'] as $n => $heading) {
            $hv = HeroVersion::create([
                'site_id' => $site->id,
                'page_type' => 'home',
                'slot' => 'hero',
                'url' => 'https://cdn.example/scene-slide-'.($n + 1).'.webp',
                'source' => 'user_upload',
                'is_active' => false,
            ]);
            $slides[] = [
                'asset_type' => 'hero_version',
                'asset_id' => $hv->id,
                'heading' => $heading,
                'subheading' => null,
                'cta_label' => 'Get a quote',
                'text_zone' => 'middle-left',
                'text_color' => 'white',
                'overlay_strength' => 'light',
                'dwell_secs' => 7,
            ];
        }

        $site->update([
            'home_hero_video_enabled' => false,
            'home_hero_scene' => [
                'kind' => 'image',
                'slides' => $slides,
                'transitions' => [['type' => 'fade', 'duration_secs' => 1.0]],
            ],
        ]);
    }

    /**
     * @param  array{columns: array<int, mixed>, show_credit: bool}  $footer
     * @param  list<array<string, mixed>>  $navItems
     */
    private static function publish(Site $site, GeneratedPage $page, array $footer, array $navItems = []): void
    {
        $version = SiteVersion::create([
            'site_id' => $site->id,
            'version' => 1,
            'composition' => [
                'nav' => ['items' => $navItems],
                'footer' => $footer,
                'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
                'homepage_page_id' => $page->id,
            ],
            'page_revisions' => [
                ['page_id' => $page->id, 'revision_id' => $page->published_revision_id],
            ],
            'published_at' => now(),
        ]);
        SiteVersionCurrent::create([
            'site_id' => $site->id,
            'version_id' => $version->id,
            'updated_at' => now(),
        ]);
    }
}

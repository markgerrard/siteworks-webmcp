<?php

use App\Enums\LogoSize;
use App\Enums\PageKind;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\LogoConcept;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\HeaderPresentation;
use App\Services\Site\PageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $siteAttrs
 * @param  list<array<string, mixed>>  $sections
 * @param  array<string, mixed>  $profileData
 * @return array{0: Site, 1: GeneratedPage}
 */
function makeHomeWithHeroImage(array $siteAttrs = [], array $sections = [], array $profileData = []): array
{
    $site = Site::factory()->create([
        'business_name' => 'Acme Plumbing',
        'theme' => 'trades-bold',
    ] + $siteAttrs);

    BusinessProfile::factory()->for($site)->create([
        'profile_data' => [
            'top_bar_enabled' => true,
            'contact' => ['phones' => ['0161 123 4567']],
            'geo' => ['service_area' => 'Manchester'],
        ] + $profileData,
    ]);

    HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/hero-home.jpg',
    ]);

    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
    ]);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => $sections !== [] ? $sections : [
            ['type' => 'hero', 'title' => 'Welcome to Acme'],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    headerOverlayPublish($site, $page, $rev, [
        ['type' => 'page', 'label' => 'Home', 'page_id' => $page->id],
    ]);

    return [$site, $page];
}

/**
 * @param  array<string, mixed>  $siteAttrs
 * @param  list<array<string, mixed>>  $sections
 * @param  array<string, mixed>  $profileData
 * @return array{0: Site, 1: GeneratedPage}
 */
function makeServiceWithHeroImage(array $siteAttrs = [], array $sections = [], array $profileData = [], bool $withImage = true): array
{
    $site = Site::factory()->create([
        'business_name' => 'Acme Plumbing',
        'theme' => 'trades-bold',
    ] + $siteAttrs);

    BusinessProfile::factory()->for($site)->create([
        'profile_data' => [
            'top_bar_enabled' => true,
            'contact' => ['phones' => ['0161 123 4567']],
            'geo' => ['service_area' => 'Manchester'],
        ] + $profileData,
    ]);

    if ($withImage) {
        HeroVersion::factory()->for($site)->active()->create([
            'page_type' => 'roofing',
            'slot' => 'hero',
            'url' => 'https://cdn.example/hero-roofing.jpg',
        ]);
    }

    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'roofing',
        'kind' => PageKind::Service,
        'hero_source' => 'dedicated',
    ]);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => $sections !== [] ? $sections : [
            ['type' => 'hero', 'title' => 'Roofing'],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    headerOverlayPublish($site, $page, $rev, [
        ['type' => 'page', 'label' => 'Roofing', 'page_id' => $page->id],
    ]);

    return [$site, $page];
}

/**
 * @param  array<string, mixed>  $siteAttrs
 * @param  list<array<string, mixed>>  $sections
 * @param  array<string, mixed>  $profileData
 * @return array{0: Site, 1: GeneratedPage}
 */
function makeProjectsWithHeroImage(array $siteAttrs = [], array $sections = [], array $profileData = [], bool $withImage = true, bool $heroEnabled = true): array
{
    $site = Site::factory()->create([
        'business_name' => 'Acme Plumbing',
        'theme' => 'trades-bold',
    ] + $siteAttrs);

    BusinessProfile::factory()->for($site)->create([
        'profile_data' => [
            'top_bar_enabled' => true,
            'contact' => ['phones' => ['0161 123 4567']],
            'geo' => ['service_area' => 'Manchester'],
        ] + $profileData,
    ]);

    if ($withImage) {
        HeroVersion::factory()->for($site)->active()->create([
            'page_type' => 'projects',
            'slot' => 'hero',
            'url' => 'https://cdn.example/hero-projects.jpg',
        ]);
    }

    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'projects',
        'kind' => PageKind::Core,
        'hero_source' => 'dedicated',
    ]);
    $defaultSection = ['type' => 'projects_hero', 'title' => 'Our Work'];
    if (! $heroEnabled) {
        $defaultSection['hero_enabled'] = false;
    }
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => $sections !== [] ? $sections : [
            $defaultSection,
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    headerOverlayPublish($site, $page, $rev, [
        ['type' => 'page', 'label' => 'Our Work', 'page_id' => $page->id],
    ]);

    return [$site, $page];
}

/**
 * @param  array<string, mixed>  $siteAttrs
 * @return array{0: Site, 1: GeneratedPage}
 */
function makeContactPage(array $siteAttrs = []): array
{
    $site = Site::factory()->create([
        'business_name' => 'Acme Plumbing',
        'theme' => 'trades-bold',
    ] + $siteAttrs);

    BusinessProfile::factory()->for($site)->create([
        'profile_data' => [
            'top_bar_enabled' => true,
            'contact' => ['phones' => ['0161 123 4567']],
            'geo' => ['service_area' => 'Manchester'],
        ],
    ]);

    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'contact',
        'kind' => PageKind::Core,
    ]);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'details', 'title' => 'Contact', 'items' => [['label' => 'Email', 'value' => 'hello@acme.test']]],
            ['type' => 'contact_form', 'title' => 'Get in touch'],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    headerOverlayPublish($site, $page, $rev, [
        ['type' => 'page', 'label' => 'Contact', 'page_id' => $page->id],
    ]);

    return [$site, $page];
}

/**
 * @param  list<array<string, mixed>>  $navItems
 */
function headerOverlayPublish(Site $site, GeneratedPage $page, PageRevision $rev, array $navItems = []): void
{
    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => $navItems],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);
}

function render(Site $site, GeneratedPage $page): string
{
    return app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');
}

/**
 * The projects_hero wrapper is the parent of the accent bar. Pins F1:
 * the bar is the last element child, and the wrapper itself has no
 * height/min-height (those live on the copy container).
 */
function projectsHeroWrapper(string $html): DOMElement
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument;
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors(false);

    $bars = (new DOMXPath($dom))->query('//div[contains(concat(" ", normalize-space(@class), " "), " h-1.5 ")]');
    expect($bars->length)->toBe(1);

    $bar = $bars->item(0);
    expect($bar)->toBeInstanceOf(DOMElement::class);

    $wrapper = $bar->parentNode;
    expect($wrapper)->toBeInstanceOf(DOMElement::class);

    $lastElement = null;
    foreach ($wrapper->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $lastElement = $child;
        }
    }

    expect($lastElement)->not->toBeNull()
        ->and($lastElement->isSameNode($bar))->toBeTrue();

    return $wrapper;
}

function headerOverlayNormalise(string $html): string
{
    $html = preg_replace('/name="_token" value="[^"]+"/', 'name="_token" value="X"', $html) ?? $html;
    $html = preg_replace('/\?v=\d+/', '?v=N', $html) ?? $html;
    $html = preg_replace('/build\/assets\/[a-z0-9-]+\.[a-f0-9]{8}\./', 'build/assets/X.', $html) ?? $html;
    $html = preg_replace('/build\/assets\/[A-Za-z0-9._-]+/', 'build/assets/X', $html) ?? $html;
    $html = preg_replace('/\b(lf|cf)-\d+-\d+/', '$1-N-N', $html) ?? $html;

    return preg_replace('/wire:id="[^"]+"/', 'wire:id="X"', $html) ?? $html;
}

it('solid mode markup is byte-identical to the fixture', function () {
    $site = Site::factory()->create(['business_name' => 'Acme Roofing', 'theme' => 'trades-bold', 'header_mode' => null]);
    BusinessProfile::factory()->for($site)->create(['profile_data' => ['top_bar_enabled' => true]]);
    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'home',
        'kind' => PageKind::Core,
    ]);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Roofing you can trust', 'accent_word' => 'trust', 'subtitle' => 's'],
            ['type' => 'services', 'title' => 'Our Services', 'items' => [['title' => 'Flat roofs', 'body' => 'b'], ['title' => 'Pitched', 'body' => 'b']]],
            ['type' => 'trust', 'title' => 'Why choose us', 'items' => [['title' => 'Local', 'body' => 'b'], ['title' => 'Insured', 'body' => 'b']]],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);
    headerOverlayPublish($site, $page, $rev);

    $html = headerOverlayNormalise(render($site, $page));
    $fixture = base_path('tests/Fixtures/ChromeSnapshots/home-wordmark-topbar.html');

    if (getenv('CHROME_SNAPSHOTS_UPDATE')) {
        file_put_contents($fixture, $html);
    }

    expect($html)->toBe(file_get_contents($fixture))
        ->not->toContain('padding-top: calc(var(--overlay-header-h');
});

it('overlay on a capable home emits fixed header, no top bar, top scrim, md phone link, light links', function () {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    $html = render($site, $page);
    expect($html)->toContain('data-header-mode="overlay"')
        ->toContain('fixed top-0 inset-x-0')
        ->toContain('from-black/55')
        ->toContain('md:flex lg:hidden')
        ->toContain('overlay-hero-copy')
        ->toContain('max(7rem, calc(var(--overlay-header-h')
        ->not->toContain('truncate max-w-[28ch] md:max-w-[42ch]')
        ->not->toContain('sticky top-0');
});

it('overlay falls back to solid on a contact page and keeps the top bar', function () {
    [$site, $page] = makeContactPage(['header_mode' => 'overlay']);
    $html = render($site, $page);
    expect($html)->not->toContain('data-header-mode="overlay"')
        ->toContain('sticky top-0')
        ->toContain('truncate max-w-[28ch] md:max-w-[42ch]')
        ->toContain('Serving Manchester')
        ->not->toContain('padding-top: calc(var(--overlay-header-h')
        ->not->toContain('fixed top-0 inset-x-0');
});

it('right_action cta renders the CTA and a bad url suppresses it', function () {
    [$site, $page] = makeHomeWithHeroImage([
        'right_action' => 'cta',
        'nav_cta_label' => 'Book',
        'nav_cta_url' => '/book',
    ]);
    expect(render($site, $page))->toContain('href="/book"')->toContain('>Book<');
    $site->update(['nav_cta_url' => '//evil.example']);
    expect(render($site, $page))->not->toContain('evil.example')->toContain('tel:');
});

it('overlay header height var is declared on the page root so the hero inherits it', function () {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    $html = render($site, $page);
    $headerPos = strpos($html, '<header');
    $varPos = strpos($html, '--overlay-header-h:');
    expect($headerPos)->not->toBeFalse()
        ->and($varPos)->not->toBeFalse()
        ->and($varPos)->toBeLessThan($headerPos);
    expect($html)->toContain('overlay-hero-copy')
        ->toContain('max(7rem, calc(var(--overlay-header-h')
        ->toContain('max(9rem, calc(var(--overlay-header-h');
});

it('overlay first-paint static classes are the solid set so a restored-scroll load does not flash transparent', function () {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    $html = render($site, $page);
    expect($html)->toContain('data-header-mode="overlay"')
        ->toContain('class="border-b border-gray-200 fixed top-0 inset-x-0 z-50"')
        ->toContain('style="background-color: #ffffff;')
        ->toContain(":class=\"[(! scrolled) ? 'shadow-none' : 'shadow-md'")
        ->toContain(":style=\"(! scrolled) ? 'background-color: transparent; border-color: transparent;'")
        ->not->toContain("? 'shadow-none border-transparent'")
        ->not->toContain('class="border-b border-gray-200 fixed top-0 inset-x-0 z-50 shadow-md"');
});

it('overlay tablet slot renders the CTA when right_action is cta', function () {
    [$site, $page] = makeHomeWithHeroImage([
        'header_mode' => 'overlay',
        'right_action' => 'cta',
        'nav_cta_label' => 'Book',
        'nav_cta_url' => '/book',
    ]);
    $html = render($site, $page);
    expect($html)->toMatch('/href="\/book"[^>]*class="[^"]*hidden md:inline-flex lg:hidden[^"]*"[^>]*>Book</')
        ->not->toMatch('/href="tel:[^"]+"[^>]*class="hidden md:flex lg:hidden/');
});

it('overlay mobile phone icon uses the same ready and not-scrolled bind as the burger', function () {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    $html = render($site, $page);
    expect($html)->toMatch('/<a href="tel:[^"]+"\s+class="p-2 rounded-md js-ovl"[^>]*:class="\(! scrolled\)/');
});

it('overlay mobile phone icon has no inline colour and solid stays byte-identical', function () {
    [$overlaySite, $overlayPage] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    [$solidSite, $solidPage] = makeHomeWithHeroImage();

    expect(preg_match('/<a href="tel:[^"]+"\s+class="p-2 rounded-md js-ovl"[^>]*>/', render($overlaySite, $overlayPage), $overlayMatch))->toBe(1)
        ->and(preg_match('/<a href="tel:[^"]+"\s+class="p-2 rounded-md"[^>]*>/', render($solidSite, $solidPage), $solidMatch))->toBe(1);

    expect($overlayMatch[0])->not->toContain('style=')
        ->toContain(':class="(! scrolled) ? \'text-white/85\'')
        ->toContain('[color:var(--brand-accent-text)]');

    expect($solidMatch[0])->toBe('<a href="tel:01611234567"
                       class="p-2 rounded-md"
                       aria-label="Call 0161 123 4567"
                       style="color: var(--brand-accent-text);">')
        ->not->toContain(':class')
        ->not->toContain(':style');
});

it('overlay dropdown colour bind does not reach through Alpine.$data', function () {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    $html = render($site, $page);
    expect($html)->not->toContain('Alpine.$data')
        ->not->toContain('$el.closest(\'header\')');
});

it('overlay header does not redeclare --overlay-header-h', function () {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    $html = render($site, $page);
    $height = HeaderPresentation::overlayHeaderHeight($site);
    $mobile = HeaderPresentation::overlayHeaderHeightMobile($site);

    expect(substr_count($html, '--overlay-header-h:'))->toBe(2)
        ->and($html)->toContain('<div class="overlay-header-scope" style="flex: 1 0 auto; --overlay-header-h: '.$mobile.'">')
        ->and($html)->toContain('@media (min-width: 768px) { .overlay-header-scope { --overlay-header-h: '.$height.'; } }')
        ->and($html)->not->toContain('transparent; --overlay-header-h')
        ->and($html)->not->toMatch('/background-color: #[0-9a-f]+;[^"\']*--overlay-header-h/');
});

it('overlay nav unscrolled md height equals overlayHeaderHeight for every logo size', function (LogoSize $size) {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay', 'logo_size' => $size]);
    $html = render($site, $page);
    $expected = HeaderPresentation::overlayHeaderHeight($site);

    expect(preg_match(
        "/'h-\[[0-9.]+rem\] md:h-\[([0-9.]+rem)\]': !scrolled/",
        $html,
        $match,
    ))->toBe(1)
        ->and($match[1])->toBe($expected);
})->with([
    LogoSize::Standard,
    LogoSize::Large,
    LogoSize::Compact,
]);

it('overlay hero copy clearance is header height plus 0.5rem for every logo size', function (LogoSize $size) {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay', 'logo_size' => $size]);
    $html = render($site, $page);
    $height = HeaderPresentation::overlayHeaderHeight($site);

    expect($html)->toContain('--overlay-header-h: '.$height)
        ->toContain('padding-top: max(7rem, calc(var(--overlay-header-h, 0px) + 0.5rem)) !important')
        ->toContain('padding-top: max(9rem, calc(var(--overlay-header-h, 0px) + 0.5rem)) !important')
        ->toContain('padding-top: max(10rem, calc(var(--overlay-header-h, 0px) + 0.5rem)) !important');
})->with([
    LogoSize::Standard,
    LogoSize::Large,
    LogoSize::Compact,
]);

it('scene boxed-left null-path min-height does not grow a trailing semicolon', function () {
    $blade = file_get_contents(resource_path('views/site/sections/_hero_scene.blade.php'));
    expect($blade)->not->toContain('min-height: {{ $heroH }};@else');
});

it('an enabled lead_form rendering before the hero is not overlay-capable', function () {
    [$site, $page] = makeHomeWithHeroImage(
        ['header_mode' => 'overlay'],
        [
            ['type' => 'lead_form', 'title' => 'Get a quote'],
            ['type' => 'hero', 'title' => 'Welcome to Acme'],
        ],
        ['lead_form_policy' => 'home'],
    );
    expect(render($site, $page))->not->toContain('data-header-mode="overlay"');
});

it('a disabled lead_form before the hero still overlays a photographic home', function () {
    [$site, $page] = makeHomeWithHeroImage(
        ['header_mode' => 'overlay'],
        [
            ['type' => 'lead_form', 'title' => 'Get a quote'],
            ['type' => 'hero', 'title' => 'Welcome to Acme'],
        ],
        ['lead_form_policy' => 'off'],
    );
    expect(render($site, $page))->toContain('data-header-mode="overlay"');
});

it('an enabled unabsorbed contact_form rendering before the hero is not overlay-capable', function () {
    [$site, $page] = makeHomeWithHeroImage(
        ['header_mode' => 'overlay'],
        [
            ['type' => 'contact_form', 'title' => 'Get in touch'],
            ['type' => 'hero', 'title' => 'Welcome to Acme'],
        ],
        ['contact_form_enabled' => true],
    );
    expect(render($site, $page))->not->toContain('data-header-mode="overlay"');
});

/**
 * @return array<string, callable(): array{0: Site, 1: GeneratedPage}>
 */
function overlayCapableSetups(): array
{
    return [
        'photographic home' => fn (): array => makeHomeWithHeroImage(['header_mode' => 'overlay']),
        'skipped disabled lead_form' => fn (): array => makeHomeWithHeroImage(
            ['header_mode' => 'overlay'],
            [
                ['type' => 'lead_form', 'title' => 'Get a quote'],
                ['type' => 'hero', 'title' => 'Welcome to Acme'],
            ],
            ['lead_form_policy' => 'off'],
        ),
        'skipped absorbed contact_form' => fn (): array => makeHomeWithHeroImage(
            ['header_mode' => 'overlay'],
            [
                ['type' => 'contact_form', 'title' => 'Get in touch'],
                ['type' => 'hero', 'title' => 'Welcome to Acme'],
            ],
            ['contact_form_enabled' => false],
        ),
        'section background_image' => function (): array {
            $site = Site::factory()->create([
                'business_name' => 'Acme Plumbing',
                'theme' => 'trades-bold',
                'header_mode' => 'overlay',
            ]);
            BusinessProfile::factory()->for($site)->create([
                'profile_data' => [
                    'top_bar_enabled' => true,
                    'contact' => ['phones' => ['0161 123 4567']],
                    'geo' => ['service_area' => 'Manchester'],
                ],
            ]);
            $page = GeneratedPage::factory()->for($site)->create([
                'page_type' => 'home',
                'kind' => PageKind::Core,
            ]);
            $rev = PageRevision::factory()->for($page, 'page')->create([
                'content_data' => ['sections' => [
                    ['type' => 'hero', 'title' => 'Welcome to Acme', 'background_image' => 'https://cdn.example/section-hero.jpg'],
                ]],
            ]);
            $page->update(['published_revision_id' => $rev->id]);
            headerOverlayPublish($site, $page, $rev, [
                ['type' => 'page', 'label' => 'Home', 'page_id' => $page->id],
            ]);

            return [$site, $page];
        },
        'scene home' => function (): array {
            [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
            attachOverlayScene($site);

            return [$site->fresh(), $page];
        },
    ];
}

function attachOverlayScene(Site $site): void
{
    $slides = [];
    foreach (['Welcome to Acme', 'Quality workmanship'] as $n => $heading) {
        $heroVersion = HeroVersion::create([
            'site_id' => $site->id,
            'page_type' => 'home',
            'slot' => 'hero',
            'url' => 'https://cdn.example/scene-slide-'.($n + 1).'.webp',
            'source' => 'user_upload',
            'is_active' => false,
        ]);
        $slides[] = [
            'asset_type' => 'hero_version',
            'asset_id' => $heroVersion->id,
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

it('every capable overlay case emits the top scrim and the hero copy scrim', function (callable $setup) {
    [$site, $page] = $setup();
    $html = render($site, $page);

    expect($html)->toContain('data-header-mode="overlay"')
        ->toContain('from-black/55')
        ->toContain('from-black/70 via-black/40');
})->with(overlayCapableSetups());

it('overlay hero copy box grows with min-height and safe-center alignment', function (callable $setup) {
    [$site, $page] = $setup();
    $html = render($site, $page);

    expect($html)->toContain('justify-content: safe center')
        ->toContain('overlay-hero-copy');

    expect(preg_match('/class="[^"]*overlay-hero-copy[^"]*"\s+style="([^"]*)"/', $html, $match))->toBe(1);

    expect($match[1])->toContain('min-height: 100vh; min-height: 100dvh')
        ->not->toMatch('/(?<!-)height:/')
        ->not->toContain('72vh')
        ->not->toContain('min-height: 55vh');
})->with(overlayCapableSetups());

it('absent overlay knob keeps the home hero copy box inline size', function () {
    [$site, $page] = makeHomeWithHeroImage();
    $html = render($site, $page);

    expect(preg_match(
        '/class="relative z-\[2\][^"]*site-shell-container[^"]*overflow-hidden"\s+style="([^"]*)"/',
        $html,
        $match,
    ))->toBe(1);

    expect($match[1])->toBe(' height: 55vh; min-height: 280px')
        ->and($html)->not->toContain('min-height: 100dvh')
        ->and($html)->not->toContain('min-height: 100vh; min-height')
        ->and($html)->not->toContain('overlay-hero-copy');
});

it('non-capable overlay contact page does not emit fold min-height', function () {
    [$site, $page] = makeContactPage(['header_mode' => 'overlay']);
    $html = render($site, $page);

    expect($html)->not->toContain('data-header-mode="overlay"')
        ->not->toContain('min-height: 100dvh')
        ->not->toContain('min-height: 100vh; min-height');
});

it('overlay header delays the solid state; the solid header stays byte-identical', function () {
    [$overlaySite, $overlayPage] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    [$solidSite, $solidPage] = makeHomeWithHeroImage();

    $overlay = render($overlaySite, $overlayPage);
    $solid = render($solidSite, $solidPage);

    expect($overlay)->toContain('data-header-mode="overlay"')
        ->toContain('html { overflow-anchor: none; }')
        ->toContain('x-data="{ scrolled: false, ready: false, settled: false, pending: false, _t: null }"')
        ->toContain('x-init="scrolled = window.scrollY > 10; $nextTick(() => { ready = true; requestAnimationFrame(() => requestAnimationFrame(() => settled = true)) })"')
        ->toContain('@scroll.window="window.scrollY <= 10 ? (clearTimeout(_t), pending = false, scrolled = false) : ((scrolled || pending) ? null : (pending = true, _t = setTimeout(() => { scrolled = window.scrollY > 10; pending = false }, 180)))"')
        ->toContain('transition-[background-color,border-color,box-shadow] duration-700')
        ->toContain(':data-logo-state="scrolled ? \'main\' : \'overlay\'"')
        ->not->toContain("ready ? 'transition-shadow duration-200' : ''");

    expect($solid)->toContain('x-data="{ scrolled: false, ready: false }"')
        ->not->toContain('overflow-anchor')
        ->toContain('x-init="scrolled = window.scrollY > 10; $nextTick(() => ready = true)"')
        ->toContain('@scroll.window="scrolled = window.scrollY > 10"')
        ->toContain(":class=\"[scrolled ? 'shadow-md' : 'shadow-sm', ready ? 'transition-shadow duration-200' : '']\"")
        ->not->toContain('pending')
        ->not->toContain('data-logo-state')
        ->not->toContain('clearTimeout')
        ->not->toContain('transition-[background-color,border-color,box-shadow]')
        ->not->toContain('min-height: 100dvh');
});

it('overlay nav links compose two complete class lists with no static colour left on the element', function () {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    $html = render($site, $page);

    expect($html)->toContain(':class="(! scrolled) ? \'text-white/85 hover:text-white\' : \'text-gray-600 hover:text-gray-900\'"')
        ->toMatch('/class="text-sm font-medium transition-colors js-ovl"[^>]*:class="\(! scrolled\) \? \'text-white\/85 hover:text-white\'/')
        ->not->toMatch('/class="text-sm font-medium transition-colors text-gray-600 hover:text-gray-900"[^>]*:class="\(! scrolled\) \? \'text-white\/85/');
});

it('a CTA label of 0 still renders the CTA rather than falling back to the phone', function () {
    [$site, $page] = makeHomeWithHeroImage([
        'header_mode' => 'overlay',
        'right_action' => 'cta',
        'nav_cta_label' => '0',
        'nav_cta_url' => '/book',
    ]);
    $html = render($site, $page);

    expect($html)->toContain('href="/book"')
        ->toContain('>0<')
        ->not->toContain('hidden lg:flex items-center gap-2 px-5 py-2.5')
        ->not->toMatch('/class="p-2 rounded-md js-ovl"[^>]*aria-label="Call 0161/');
});

it('overlay image-logo backing plate is present for an opaque selected logo', function () {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/opaque.png',
        'metadata' => [],
    ]);

    expect(render($site, $page))->toContain("? 'bg-white/85 rounded px-2' : ''");
});

it('overlay keeps the backing plate when a transparent logo lacks reads_on_dark', function () {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/transparent.png',
        'metadata' => ['transparent' => true],
    ]);

    expect(render($site, $page))->toContain("? 'bg-white/85 rounded px-2' : ''");
});

it('overlay keeps the backing plate for a transparent logo that does not read on dark', function () {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/hunt.png',
        'metadata' => [
            'transparent' => true,
            'reads_on_dark' => false,
            'mark_luma_mean' => 40.0,
        ],
    ]);

    expect(render($site, $page))->toContain("? 'bg-white/85 rounded px-2' : ''");
});

it('overlay omits the backing plate for a transparent logo that reads on dark', function () {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/eden.png',
        'metadata' => [
            'transparent' => true,
            'reads_on_dark' => true,
            'mark_luma_mean' => 250.0,
        ],
    ]);

    expect(render($site, $page))->not->toContain('bg-white/85 rounded px-2');
});

it('overlay image-logo backing plate skips only for literal boolean true', function (mixed $transparent, bool $platePresent) {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/mark.png',
        'metadata' => $transparent === '__absent' ? [] : ['transparent' => $transparent],
    ]);

    $html = render($site, $page);

    if ($platePresent) {
        expect($html)->toContain("? 'bg-white/85 rounded px-2' : ''");
    } else {
        expect($html)->not->toContain('bg-white/85 rounded px-2');
    }
})->with([
    'string false' => ['false', true],
    'int 1' => [1, true],
    'absent' => ['__absent', true],
    'boolean false' => [false, true],
    'boolean true' => [true, true],
]);

it('non-overlay markup with a selected logo does not emit the overlay backing plate', function () {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage();
    LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/transparent.png',
        'metadata' => ['transparent' => true, 'reads_on_dark' => false],
    ]);

    expect(render($site, $page))->not->toContain('bg-white/85 rounded px-2');
});

function headerLogoAnchor(string $html): string
{
    expect(preg_match('/<a href="\/"[^>]*>.*?<\/a>/s', $html, $match))->toBe(1);

    return $match[0];
}

it('overlay with an overlay logo renders both imgs with the x-show pair and no plate', function () {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/selected.png',
        'metadata' => ['transparent' => true, 'reads_on_dark' => false],
    ]);
    $overlay = LogoConcept::factory()->for($site)->create([
        'path' => 'logos/inverted.png',
        'metadata' => [
            'transparent' => true,
            'variant' => 'inverted',
            'reads_on_dark' => true,
        ],
    ]);
    $site->update(['overlay_logo_concept_id' => $overlay->id]);

    $html = render($site->fresh(), $page);
    $logo = headerLogoAnchor($html);

    expect($html)->not->toContain('bg-white/85 rounded px-2')
        ->and($logo)->not->toContain('x-show=')
        ->and($logo)->toContain('js-overlay-logo')
        ->and($logo)->toMatch('/class="[^"]*\bjs-main-logo\b[^"]*"/')
        ->and($logo)->toContain('.js-overlay-logo{opacity:1}.js-main-logo{opacity:0}')
        ->and($logo)->toContain('header[data-logo-state="main"] .js-main-logo{opacity:1}')
        ->and($logo)->toContain('<noscript><style>.js-overlay-logo{opacity:0}.js-main-logo{opacity:1}</style></noscript>')
        ->and($logo)->toContain('<span class="relative inline-flex">')
        ->and(substr_count($logo, '<img '))->toBe(2)
        ->and($logo)->not->toContain('x-cloak')
        ->and($logo)->toContain('inverted.png')
        ->and($logo)->toContain('selected.png');

    expect($logo)->toMatch('/<img[^>]*inverted\.png[^>]*>/s');
    preg_match('/<img[^>]*inverted\.png[^>]*>/s', $logo, $overlayImg);
    expect($overlayImg[0])->toContain('js-overlay-logo')
        ->not->toContain('x-cloak')
        ->toContain('aria-hidden="true"')
        ->toContain('absolute left-0 top-1/2 -translate-y-1/2');

    preg_match('/<img[^>]*selected\.png[^>]*>/s', $logo, $selectedImg);
    expect($selectedImg[0])->toMatch('/class="w-auto object-contain js-main-logo [^"]*"/')
        ->not->toContain('x-cloak')
        ->not->toContain('x-show=');
});

it('overlay without an overlay logo stays on the plate lane', function () {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/hunt.png',
        'metadata' => [
            'transparent' => true,
            'reads_on_dark' => false,
            'mark_luma_mean' => 40.0,
        ],
    ]);

    $html = render($site, $page);
    $logo = headerLogoAnchor($html);

    expect($html)->toContain("? 'bg-white/85 rounded px-2' : ''")
        ->and(substr_count($logo, '<img '))->toBe(1)
        ->and($logo)->not->toContain('js-main-logo');
});

it('setting an overlay logo does not change non-overlay markup', function () {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage();
    LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/selected.png',
    ]);
    $overlay = LogoConcept::factory()->for($site)->create([
        'path' => 'logos/inverted.png',
        'metadata' => ['transparent' => true, 'variant' => 'inverted'],
    ]);

    $without = headerOverlayNormalise(render($site, $page));
    $site->update(['overlay_logo_concept_id' => $overlay->id]);
    $with = headerOverlayNormalise(render($site->fresh(), $page));

    expect($with)->toBe($without)
        ->not->toContain('js-main-logo');
});

it('ignores a soft-deleted or cross-site overlay logo concept', function () {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/selected.png',
        'metadata' => [],
    ]);
    $deleted = LogoConcept::factory()->for($site)->create([
        'path' => 'logos/inverted.png',
        'metadata' => ['transparent' => true, 'variant' => 'inverted'],
    ]);
    $deleted->delete();
    $site->update(['overlay_logo_concept_id' => $deleted->id]);

    $html = render($site->fresh(), $page);
    $logo = headerLogoAnchor($html);

    expect(substr_count($logo, '<img '))->toBe(1)
        ->and($html)->toContain("? 'bg-white/85 rounded px-2' : ''");

    $foreign = LogoConcept::factory()->create([
        'path' => 'logos/foreign.png',
        'metadata' => ['transparent' => true],
    ]);
    $site->update(['overlay_logo_concept_id' => $foreign->id]);

    $html = render($site->fresh(), $page);
    $logo = headerLogoAnchor($html);

    expect(substr_count($logo, '<img '))->toBe(1)
        ->and($logo)->not->toContain('foreign.png');
});

const OVERLAY_LOGO_DEFAULT_HEADER_UNSCROLLED = 'h-[7.5rem] md:h-[8.75rem]';
const OVERLAY_LOGO_LARGE_HEADER_UNSCROLLED = 'h-[9.375rem] md:h-[10.9375rem]';
const OVERLAY_LOGO_COMPACT_HEADER_UNSCROLLED = 'h-[5rem] md:h-[5.75rem]';
const OVERLAY_LOGO_DEFAULT_IMG_UNSCROLLED = 'h-[4.375rem] max-w-[250px] md:h-28 md:max-w-[455px]';
const OVERLAY_LOGO_LARGE_IMG_UNSCROLLED = 'h-[5.46875rem] max-w-[313px] md:h-[8.75rem] md:max-w-[569px]';
const OVERLAY_LOGO_COMPACT_IMG_UNSCROLLED = 'h-12 max-w-[200px] md:h-14 md:max-w-[280px]';

/**
 * @return array{0: string, 1: string}
 */
function overlayAndSelectedImgs(string $html): array
{
    $logo = headerLogoAnchor($html);
    expect(preg_match('/<img[^>]*inverted\.png[^>]*>/s', $logo, $overlayImg))->toBe(1);
    expect(preg_match('/<img[^>]*selected\.png[^>]*>/s', $logo, $selectedImg))->toBe(1);

    return [$overlayImg[0], $selectedImg[0]];
}

function attachSelectedAndOverlayLogos(Site $site): void
{
    LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/selected.png',
        'metadata' => ['transparent' => true, 'reads_on_dark' => false],
    ]);
    $overlay = LogoConcept::factory()->for($site)->create([
        'path' => 'logos/inverted.png',
        'metadata' => [
            'transparent' => true,
            'variant' => 'inverted',
            'reads_on_dark' => true,
        ],
    ]);
    $site->update(['overlay_logo_concept_id' => $overlay->id]);
}

it('overlay logo large with standard logo_size sizes the floating img and the floating bar', function () {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay', 'logo_size' => LogoSize::Standard]);
    attachSelectedAndOverlayLogos($site);
    $site->update(['overlay_logo_size' => LogoSize::Large]);

    $html = render($site->fresh(), $page);
    [$overlayImg, $selectedImg] = overlayAndSelectedImgs($html);

    expect($overlayImg)->toContain(OVERLAY_LOGO_LARGE_IMG_UNSCROLLED)
        ->and($overlayImg)->not->toContain(OVERLAY_LOGO_DEFAULT_IMG_UNSCROLLED)
        // both marks share the floating size while floating (no ghosting in the crossfade)
        ->and($selectedImg)->toContain(OVERLAY_LOGO_LARGE_IMG_UNSCROLLED)
        ->and($selectedImg)->not->toContain(OVERLAY_LOGO_DEFAULT_IMG_UNSCROLLED)
        // the bar is sized to the floating logo while it shows; solid keeps the main row
        ->and($html)->toContain(OVERLAY_LOGO_LARGE_HEADER_UNSCROLLED)
        ->and($html)->not->toContain(OVERLAY_LOGO_DEFAULT_HEADER_UNSCROLLED);
});

it('overlay logo compact with standard logo_size sizes the floating img and the floating bar', function () {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay', 'logo_size' => LogoSize::Standard]);
    attachSelectedAndOverlayLogos($site);
    $site->update(['overlay_logo_size' => LogoSize::Compact]);

    $html = render($site->fresh(), $page);
    [$overlayImg, $selectedImg] = overlayAndSelectedImgs($html);

    expect($overlayImg)->toContain(OVERLAY_LOGO_COMPACT_IMG_UNSCROLLED)
        ->and($selectedImg)->toContain(OVERLAY_LOGO_COMPACT_IMG_UNSCROLLED)
        ->and($selectedImg)->not->toContain(OVERLAY_LOGO_DEFAULT_IMG_UNSCROLLED)
        ->and($html)->toContain(OVERLAY_LOGO_COMPACT_HEADER_UNSCROLLED)
        ->and($html)->not->toContain(OVERLAY_LOGO_DEFAULT_HEADER_UNSCROLLED);
});

it('null overlay_logo_size keeps both imgs on the logo_size matrix', function () {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay', 'logo_size' => LogoSize::Standard]);
    attachSelectedAndOverlayLogos($site);

    $html = render($site->fresh(), $page);
    [$overlayImg, $selectedImg] = overlayAndSelectedImgs($html);

    expect($overlayImg)->toContain(OVERLAY_LOGO_DEFAULT_IMG_UNSCROLLED)
        ->and($selectedImg)->toContain(OVERLAY_LOGO_DEFAULT_IMG_UNSCROLLED)
        ->and($overlayImg)->not->toContain(OVERLAY_LOGO_LARGE_IMG_UNSCROLLED)
        ->and($html)->toContain(OVERLAY_LOGO_DEFAULT_HEADER_UNSCROLLED);
});

it('overlay_logo_size does not change solid-header markup', function () {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage();
    LogoConcept::factory()->for($site)->selected()->create([
        'path' => 'logos/selected.png',
    ]);
    $overlay = LogoConcept::factory()->for($site)->create([
        'path' => 'logos/inverted.png',
        'metadata' => ['transparent' => true, 'variant' => 'inverted'],
    ]);
    $site->update(['overlay_logo_concept_id' => $overlay->id]);

    $without = headerOverlayNormalise(render($site->fresh(), $page));
    $site->update(['overlay_logo_size' => LogoSize::Large]);
    $with = headerOverlayNormalise(render($site->fresh(), $page));

    expect($with)->toBe($without)
        ->not->toContain('opacity: (! scrolled)');
});

it('hero scroll cue fades out once scrolling has begun and smooth-scrolls on click', function () {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    $html = render($site, $page);

    expect(preg_match('/<a href="#after-hero" aria-label="Scroll to content".*?duration-300">/s', $html, $cue))->toBe(1);
    expect($cue[0])->toContain('x-data="{ hidden: window.scrollY > 40 }"')
        ->toContain('@scroll.window.passive="hidden = hidden || window.scrollY > 40"')
        ->toContain(':style="hidden ? \'opacity: 0; pointer-events: none;\' : \'\'"')
        ->toContain("@click.prevent=\"document.getElementById('after-hero')?.scrollIntoView(")
        ->toContain('transition-opacity duration-300');
    expect($html)->toContain('<svg class="w-8 h-8 animate-bounce motion-reduce:animate-none"')
        ->toContain('<div id="after-hero" class="scroll-mt-24 md:scroll-mt-28" aria-hidden="true"></div>');
});
it('overlay_glass null equals the overlay render with no column change', function () {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);

    $baseline = headerOverlayNormalise(render($site, $page));

    expect($baseline)->not->toContain('data-overlay-glass')
        ->not->toContain('backdrop-filter:blur')
        ->not->toContain('color-mix(in srgb,')
        ->toContain('js-scrim')
        ->toContain('from-black/55')
        ->toContain('header[data-header-mode="overlay"]:not([data-logo-state]){background-color:transparent!important');

    $site->update(['overlay_glass' => 'floating']);
    expect(headerOverlayNormalise(render($site->fresh(), $page)))->not->toBe($baseline);

    $site->update(['overlay_glass' => null]);
    expect(headerOverlayNormalise(render($site->fresh(), $page)))->toBe($baseline);
});

it('overlay_glass floating emits glass rules, drops the scrim, and keeps the solid scrolled colour', function () {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay', 'overlay_glass' => 'floating']);
    $html = render($site, $page);

    expect($html)->toContain('data-overlay-glass="floating"')
        ->toContain(':data-scrolled="scrolled ? \'true\' : \'false\'"')
        ->toContain('color-mix(in srgb, #ffffff 45%, transparent)')
        ->toContain('-webkit-backdrop-filter:blur(12px) saturate(1.4)')
        ->toContain('backdrop-filter:blur(12px) saturate(1.4)')
        ->toContain('border-color:rgba(0,0,0,.08)')
        ->toContain('header[data-header-mode="overlay"][data-overlay-glass]:not([data-logo-state])')
        ->toContain('header[data-header-mode="overlay"][data-overlay-glass="floating"][data-logo-state="main"]')
        ->toContain('background-color:#ffffff!important')
        ->toContain(":style=\"(! scrolled) ? 'background-color: transparent; border-color: transparent;' : 'background-color: #ffffff;")
        ->toContain('@supports not (backdrop-filter: blur(1px))')
        ->toContain('@media (prefers-reduced-transparency: reduce)')
        ->toContain('color-mix(in srgb, #ffffff 75%, transparent)')
        ->not->toContain('js-scrim')
        ->not->toContain('from-black/55')
        ->not->toContain('header[data-header-mode="overlay"]:not([data-logo-state]){background-color:transparent!important');
});

it('overlay_glass always emits both tints', function () {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay', 'overlay_glass' => 'always']);
    $html = render($site, $page);

    expect($html)->toContain('data-overlay-glass="always"')
        ->toContain('color-mix(in srgb, #ffffff 45%, transparent)')
        ->toContain('color-mix(in srgb, #ffffff 85%, transparent)')
        ->toContain('-webkit-backdrop-filter:blur(12px) saturate(1.4)')
        ->toContain('header[data-header-mode="overlay"][data-overlay-glass="always"][data-logo-state="main"]')
        ->not->toContain('js-scrim')
        ->not->toContain('from-black/55');
});

it('overlay_glass uses a light hairline on a dark header', function () {
    [$site, $page] = makeHomeWithHeroImage([
        'header_mode' => 'overlay',
        'overlay_glass' => 'floating',
        'header_bg' => '#1a1a1c',
    ]);
    $html = render($site, $page);

    expect($html)->toContain('color-mix(in srgb, #1a1a1c 45%, transparent)')
        ->toContain('border-color:rgba(255,255,255,.10)')
        ->toContain('background-color:#1a1a1c!important');
});

it('overlay_glass floating leaves solid-header markup untouched; always adds only the solid-glass surface', function () {
    [$site, $page] = makeHomeWithHeroImage();
    $without = headerOverlayNormalise(render($site, $page));

    $site->update(['overlay_glass' => 'floating']);
    expect(headerOverlayNormalise(render($site->fresh(), $page)))->toBe($without);

    $site->update(['overlay_glass' => 'always']);
    $with = headerOverlayNormalise(render($site->fresh(), $page));
    expect($with)->not->toContain('data-overlay-glass')
        ->not->toContain('data-header-mode="overlay"')
        ->toContain('data-solid-glass="always"')
        ->toContain('backdrop-filter');
});

it('overlay_glass always gives a non-overlay contact page the solid glass, never the overlay arm', function () {
    [$site, $page] = makeContactPage(['header_mode' => 'overlay']);
    $without = headerOverlayNormalise(render($site, $page));

    $site->update(['overlay_glass' => 'always']);
    $with = headerOverlayNormalise(render($site->fresh(), $page));

    expect($with)->not->toContain('data-header-mode="overlay"')
        ->not->toContain('data-overlay-glass')
        ->toContain('data-solid-glass="always"');
    expect(str_replace(['data-solid-glass="always"', ''], '', $with))->not->toBe($without); // surface differs by design
});


it('overlay header class binding closes cleanly (no stray quote after the transition branch)', function () {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    $html = render($site, $page);

    expect($html)->toContain("duration-700' : '']\"")
        ->not->toContain("duration-700' : '']'\"");
});

it('overlay glass scrolled: transparent floating state with scrim, glass once scrolled', function () {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    $site->update(['overlay_glass' => 'scrolled']);
    $html = render($site->fresh(), $page);

    expect($html)->toContain('data-overlay-glass="scrolled"')
        ->toContain('header[data-header-mode="overlay"]:not([data-logo-state]){background-color:transparent!important')
        ->toContain('class="js-scrim')
        ->toContain('header[data-header-mode="overlay"][data-overlay-glass="scrolled"][data-scrolled="true"]{background-color:color-mix(in srgb, #')
        ->toContain('backdrop-filter:blur(12px) saturate(1.4)!important')
        ->not->toContain('[data-overlay-glass="floating"]:not([data-logo-state="main"])');
});

it('overlay glass always uses the selected logo, not the inverted copy', function () {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    attachSelectedAndOverlayLogos($site);
    $site->update(['overlay_glass' => 'always']);
    $html = render($site->fresh(), $page);

    expect($html)->toContain('data-overlay-glass="always"')
        ->not->toContain('js-overlay-logo')
        ->not->toContain('js-main-logo');
    $logo = substr($html, strpos($html, '<header'), 12000);
    expect(substr_count($logo, '<img '))->toBe(1)
        // sized on the MAIN logo's track, not the floating one (bar and logo must agree)
        ->and($logo)->toContain(OVERLAY_LOGO_DEFAULT_IMG_UNSCROLLED)
        ->and($logo)->not->toContain(OVERLAY_LOGO_LARGE_IMG_UNSCROLLED);
});

it('floating logo margin pads only the overlay img; blank inherits logo_margin', function () {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay', 'logo_margin' => 5]);
    attachSelectedAndOverlayLogos($site);
    [$overlayImg, $selectedImg] = overlayAndSelectedImgs(render($site->fresh(), $page));
    expect($overlayImg)->toContain('padding-top: 5px; padding-bottom: 5px;')
        ->and($selectedImg)->toContain('padding-top: 5px; padding-bottom: 5px;');

    $site->update(['overlay_logo_margin' => 2]);
    [$overlayImg, $selectedImg] = overlayAndSelectedImgs(render($site->fresh(), $page));
    expect($overlayImg)->toContain('padding-top: 2px; padding-bottom: 2px;')
        ->and($selectedImg)->toContain('padding-top: 5px; padding-bottom: 5px;');

    $site->update(['overlay_logo_margin' => 0]);
    [$overlayImg] = overlayAndSelectedImgs(render($site->fresh(), $page));
    expect($overlayImg)->not->toContain('padding-top:');
});

it('nav_case upper and lower recase the desktop, dropdown and mobile links; normal is byte-identical', function () {
    [$site, $page] = makeHomeWithHeroImage();
    $normal = render($site, $page);
    $site->update(['nav_case' => 'normal']);
    expect(render($site->fresh(), $page))->toBe($normal);

    $site->update(['nav_case' => 'upper']);
    $upper = render($site->fresh(), $page);
    expect(substr_count($upper, ' uppercase tracking-[0.1em] !text-[0.8125rem]'))->toBeGreaterThanOrEqual(2)
        ->and($upper)->toContain('rounded-md uppercase tracking-[0.1em] !text-[0.8125rem]');

    $site->update(['nav_case' => 'lower']);
    expect(render($site->fresh(), $page))->toContain(' lowercase"');
});

it('header padding and sticky shrink: padding styles the bar container, shrink off keeps full-size rows; null is byte-identical', function () {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    $baseline = render($site, $page);
    expect($baseline)->not->toContain('<nav class="site-shell-container px-4 sm:px-6 lg:px-8" style=');

    $site->update(['header_padding' => 6]);
    $html = render($site->fresh(), $page);
    expect($html)->toContain('<nav class="site-shell-container px-4 sm:px-6 lg:px-8" style="padding-top: 6px; padding-bottom: 6px;">')
        ->toContain('--overlay-header-h: calc(');

    $site->update(['header_padding' => null, 'header_shrink' => 'off']);
    $html = render($site->fresh(), $page);
    $heights = \App\Services\Site\HeaderPresentation::headerHeightClasses($site->fresh());
    expect($heights['scrolled'])->toBe($heights['unscrolled'])
        ->and($html)->toContain("'".$heights['unscrolled']."': !scrolled, '".$heights['unscrolled']."': scrolled");
});

it('overlay_logo_size sizes the floating bar even without a floating mark (glass always / no overlay logo)', function () {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay', 'logo_size' => LogoSize::Large]);
    LogoConcept::factory()->for($site)->selected()->create(['path' => 'logos/selected.png', 'metadata' => ['transparent' => true, 'reads_on_dark' => true]]);
    $site->update(['overlay_logo_size' => LogoSize::Standard, 'overlay_glass' => 'always']);
    $html = render($site->fresh(), $page);
    $standard = \App\Services\Site\HeaderPresentation::headerHeightClasses(new Site(['logo_size' => LogoSize::Standard]));
    $large = \App\Services\Site\HeaderPresentation::headerHeightClasses(new Site(['logo_size' => LogoSize::Large]));

    // floating row = Standard, solid row = Large's scrolled row: the bar grows into the solid state
    expect($html)->toContain("'".$standard['unscrolled']."': !scrolled, '".$large['scrolled']."': scrolled")
        ->and($html)->toContain('--overlay-header-h: '.\App\Services\Site\HeaderPresentation::overlayHeaderHeight($site->fresh(), true));
    expect($html)->toContain(OVERLAY_LOGO_DEFAULT_IMG_UNSCROLLED)
        ->not->toContain('js-overlay-logo');
});

it('header_fit tight renders the tight rows on the bar; comfortable is byte-identical', function () {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay', 'logo_size' => LogoSize::Standard]);
    $baseline = render($site, $page);
    $site->update(['header_fit' => 'comfortable']);
    expect(render($site->fresh(), $page))->toBe($baseline);

    $site->update(['header_fit' => 'tight']);
    $html = render($site->fresh(), $page);
    expect($html)->toContain("'h-[5.375rem] md:h-[8rem]': !scrolled, 'h-[4.95rem] md:h-[7.3rem]': scrolled")
        ->toContain('--overlay-header-h: 8rem');
});

it('renders every nav container style and fill on standard and overlay headers', function (string $headerMode, string $style, string $fill) {
    [$site, $page] = makeHomeWithHeroImage([
        'header_mode' => $headerMode,
        'nav_container_style' => $style,
        'nav_container_fill' => $fill,
    ]);

    $html = render($site, $page);
    $renderedStyle = $style === 'band' ? 'plate' : $style;
    $linkToken = $fill === 'brand' ? '--color-text-on-primary' : '--color-text';

    expect($html)->toContain('data-nav-container-style="'.$renderedStyle.'"')
        ->toContain('data-nav-container-fill="'.$fill.'"')
        ->toContain('--nav-container-bg:')
        ->toContain('--nav-container-ink: var('.$linkToken.')')
        ->toContain('[data-nav-container][data-nav-container-style]>a:not(:hover)')
        ->toContain("scrolled ? 'px-3 py-1.5' : 'px-5 py-2'");
})->with(['solid', 'overlay'])
    ->with(['pill', 'plate', 'band'])
    ->with(['surface', 'glass', 'brand', 'pattern']);

it('shrinks standard nav container padding on scroll only when header shrink is on', function () {
    [$shrinkingSite, $shrinkingPage] = makeHomeWithHeroImage([
        'nav_container_style' => 'pill',
        'header_shrink' => 'on',
    ]);
    [$fixedSite, $fixedPage] = makeHomeWithHeroImage([
        'nav_container_style' => 'pill',
        'header_shrink' => 'off',
    ]);

    expect(render($shrinkingSite, $shrinkingPage))
        ->toContain("scrolled ? 'px-3 py-1.5' : 'px-5 py-2'")
        ->and(render($fixedSite, $fixedPage))
        ->toContain('data-nav-container')
        ->toContain('px-5 py-2')
        ->not->toContain("scrolled ? 'px-3 py-1.5' : 'px-5 py-2'");
});

it('keeps standard and overlay headers byte-identical when the nav container style is none', function (string $headerMode) {
    [$defaultSite, $defaultPage] = makeHomeWithHeroImage(['header_mode' => $headerMode]);
    [$noneSite, $nonePage] = makeHomeWithHeroImage([
        'header_mode' => $headerMode,
        'nav_container_style' => 'none',
        'nav_container_fill' => 'brand',
    ]);

    expect(render($noneSite, $nonePage))->toBe(render($defaultSite, $defaultPage));
})->with(['solid', 'overlay']);

it('solid-header pages get the scrolled-state glass on scrolled/always, nothing on floating or off', function () {
    [$site, $page] = makeHomeWithHeroImage(); // no header_mode ⇒ solid header
    $baseline = render($site, $page);
    expect($baseline)->not->toContain('data-solid-glass');

    $site->update(['overlay_glass' => 'always']);
    $html = render($site->fresh(), $page);
    expect($html)->toContain('<header x-data="{ scrolled: false, ready: false }" data-solid-glass="always"')
        ->toContain('header[data-solid-glass]{background-color:color-mix(in srgb, #')
        ->toContain(' 85%, transparent)!important')
        ->toContain('backdrop-filter:blur(12px) saturate(1.4)!important');

    $site->update(['overlay_glass' => 'scrolled']);
    expect(render($site->fresh(), $page))->toContain('data-solid-glass="scrolled"')->toContain(' 78%, transparent)!important');

    $site->update(['overlay_glass' => 'floating']);
    expect(render($site->fresh(), $page))->not->toContain('data-solid-glass');

    $site->update(['overlay_glass' => null]);
    expect(render($site->fresh(), $page))->toBe($baseline);
});

it('a service page with an image hero renders the overlay arm and the hero grows by the header height', function () {
    [$site, $page] = makeServiceWithHeroImage(['header_mode' => 'overlay']);
    $html = render($site, $page);
    expect($html)->toContain('data-header-mode="overlay"')
        ->toContain('--overlay-header-h:')
        ->toContain('class="overlay-header-scope" style="flex: 1 0 auto; --overlay-header-h: 7.5rem"')
        ->toContain('@media (min-width: 768px) { .overlay-header-scope { --overlay-header-h: 8.75rem; } }')
        ->toContain('overlay-hero-copy-inner')
        ->not->toContain('overlay-hero-copy"')
        ->toContain('min-height: calc(35vh + var(--overlay-header-h, 0px))')
        ->not->toContain('min-height: 100vh; min-height: 100dvh')   // home-only sizing
        ->not->toContain('data-solid-glass');

    [$homeSite, $homePage] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    expect(render($homeSite, $homePage))->toContain('overlay-hero-copy');
});

it('a service page with an image hero and overlay_glass always renders the overlay glass, not the solid glass', function () {
    [$site, $page] = makeServiceWithHeroImage(['header_mode' => 'overlay', 'overlay_glass' => 'always']);
    $html = render($site, $page);
    expect($html)->toContain('data-header-mode="overlay"')->toContain('data-overlay-glass="always"')->not->toContain('data-solid-glass');
});

it('a service page without a hero image keeps the solid header', function () {
    [$site, $page] = makeServiceWithHeroImage(['header_mode' => 'overlay'], withImage: false);
    $html = render($site, $page);
    expect($html)->not->toContain('data-header-mode="overlay"')->toContain('sticky top-0');
});

it('emits the mobile header height inline and the md override in the overlay scope', function () {
    [$site, $page] = makeHomeWithHeroImage(['header_mode' => 'overlay']);
    $html = render($site, $page);
    expect($html)->toContain('class="overlay-header-scope" style="flex: 1 0 auto; --overlay-header-h: 7.5rem"')
        ->toContain('@media (min-width: 768px) { .overlay-header-scope { --overlay-header-h: 8.75rem; } }');
});

it('a projects page with a visible projects_hero image renders the overlay arm and the band grows by the header height', function () {
    [$site, $page] = makeProjectsWithHeroImage(['header_mode' => 'overlay']);
    $html = render($site, $page);
    $wrapper = projectsHeroWrapper($html);
    expect($html)->toContain('data-header-mode="overlay"')
        ->toContain('overlay-hero-copy-inner')
        ->toContain('min-height: calc(35vh + var(--overlay-header-h, 0px))')
        ->not->toContain('data-solid-glass')
        ->toContain('relative z-[3] h-1.5');   // bottom accent bar, parity with hero.blade
    expect($wrapper->getAttribute('style'))->toBe('background-color: var(--color-surface);');
});

it('a projects page with hero_enabled false keeps the solid header and the exact non-overlay band', function () {
    [$site, $page] = makeProjectsWithHeroImage(['header_mode' => 'overlay'], heroEnabled: false);
    $html = render($site, $page);
    $wrapper = projectsHeroWrapper($html);
    expect($html)->not->toContain('data-header-mode="overlay"')->toContain('sticky top-0')
        ->toContain('height: 35vh; min-height: 260px;');
    expect($wrapper->getAttribute('style'))->toBe('background-color: var(--color-surface);');
    expect($wrapper->getAttribute('class'))->toContain('overflow-hidden');
});

it('overlay_inner_scale main sizes an inner overlay page by the main logo track', function () {
    Storage::fake('s3');
    [$site, $page] = makeServiceWithHeroImage([
        'header_mode' => 'overlay',
        'overlay_logo_size' => LogoSize::Large,
        'overlay_inner_scale' => 'main',
    ]);
    attachSelectedAndOverlayLogos($site);
    $html = render($site->fresh(), $page);
    $fresh = $site->fresh();
    $mainHeights = HeaderPresentation::headerHeightClasses($fresh, false);
    [$overlayImg] = overlayAndSelectedImgs($html);

    expect($html)->toContain('class="overlay-header-scope" style="flex: 1 0 auto; --overlay-header-h: '.HeaderPresentation::overlayHeaderHeightMobile($fresh, false).'"')
        ->toContain('@media (min-width: 768px) { .overlay-header-scope { --overlay-header-h: '.HeaderPresentation::overlayHeaderHeight($fresh, false).'; } }')
        ->toContain("'".$mainHeights['unscrolled']."': !scrolled, '".$mainHeights['scrolled']."': scrolled")
        ->not->toContain(OVERLAY_LOGO_LARGE_HEADER_UNSCROLLED)
        ->and($overlayImg)->toContain(OVERLAY_LOGO_DEFAULT_IMG_UNSCROLLED)
        ->and($overlayImg)->not->toContain(OVERLAY_LOGO_LARGE_IMG_UNSCROLLED);
});

it('the same inner overlay fixture without overlay_inner_scale emits the overlay logo track', function () {
    Storage::fake('s3');
    [$site, $page] = makeServiceWithHeroImage([
        'header_mode' => 'overlay',
        'overlay_logo_size' => LogoSize::Large,
    ]);
    attachSelectedAndOverlayLogos($site);
    $html = render($site->fresh(), $page);
    $fresh = $site->fresh();
    $overlayHeights = HeaderPresentation::headerHeightClasses($fresh, true);
    [$overlayImg] = overlayAndSelectedImgs($html);

    expect($html)->toContain('class="overlay-header-scope" style="flex: 1 0 auto; --overlay-header-h: '.HeaderPresentation::overlayHeaderHeightMobile($fresh, true).'"')
        ->toContain('@media (min-width: 768px) { .overlay-header-scope { --overlay-header-h: '.HeaderPresentation::overlayHeaderHeight($fresh, true).'; } }')
        ->toContain("'".$overlayHeights['unscrolled']."': !scrolled, '".$overlayHeights['scrolled']."': scrolled")
        ->toContain(OVERLAY_LOGO_LARGE_HEADER_UNSCROLLED)
        ->and($overlayImg)->toContain(OVERLAY_LOGO_LARGE_IMG_UNSCROLLED);
});

it('overlay_inner_scale main leaves the home page on the overlay logo track', function () {
    Storage::fake('s3');
    [$site, $page] = makeHomeWithHeroImage([
        'header_mode' => 'overlay',
        'overlay_logo_size' => LogoSize::Large,
        'overlay_inner_scale' => 'main',
    ]);
    attachSelectedAndOverlayLogos($site);
    $html = render($site->fresh(), $page);
    $fresh = $site->fresh();
    $overlayHeights = HeaderPresentation::headerHeightClasses($fresh, true);
    [$overlayImg] = overlayAndSelectedImgs($html);

    expect($html)->toContain('class="overlay-header-scope" style="flex: 1 0 auto; --overlay-header-h: '.HeaderPresentation::overlayHeaderHeightMobile($fresh, true).'"')
        ->toContain('@media (min-width: 768px) { .overlay-header-scope { --overlay-header-h: '.HeaderPresentation::overlayHeaderHeight($fresh, true).'; } }')
        ->toContain("'".$overlayHeights['unscrolled']."': !scrolled, '".$overlayHeights['scrolled']."': scrolled")
        ->toContain(OVERLAY_LOGO_LARGE_HEADER_UNSCROLLED)
        ->and($overlayImg)->toContain(OVERLAY_LOGO_LARGE_IMG_UNSCROLLED);
});

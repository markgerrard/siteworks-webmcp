<?php

use App\Enums\Archetype;
use App\Enums\PageKind;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\PageRenderer;

/**
 * @param  array<string, mixed>  $siteAttrs
 * @param  list<array<string, mixed>>  $sections
 * @param  array<string, mixed>  $profileData
 * @return array{0: Site, 1: GeneratedPage}
 */
function makeNavCtaSite(
    array $siteAttrs = [],
    array $sections = [],
    array $profileData = [],
    string $pageType = 'home',
    ?PageKind $kind = null,
): array {
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

    if ($pageType === 'home') {
        HeroVersion::factory()->for($site)->active()->create([
            'page_type' => 'home',
            'slot' => 'hero',
            'url' => 'https://cdn.example/hero-home.jpg',
        ]);
    }

    $page = GeneratedPage::factory()->for($site)->create([
        'page_type' => $pageType,
        'kind' => $kind ?? PageKind::Core,
    ]);
    $rev = PageRevision::factory()->for($page, 'page')->create([
        'content_data' => ['sections' => $sections !== [] ? $sections : [
            ['type' => 'hero', 'title' => 'Welcome to Acme'],
        ]],
    ]);
    $page->update(['published_revision_id' => $rev->id]);

    $version = SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => [['type' => 'page', 'label' => 'Home', 'page_id' => $page->id]]],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => null, 'accent_override' => null],
            'homepage_page_id' => $page->id,
        ],
        'page_revisions' => [['page_id' => $page->id, 'revision_id' => $rev->id]],
        'published_at' => now(),
    ]);
    SiteVersionCurrent::create(['site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now()]);

    return [$site, $page];
}

function renderNavCta(Site $site, GeneratedPage $page): string
{
    return app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');
}

function navCtaHeader(string $html): string
{
    expect(preg_match('/<header\b.*<\/header>/s', $html, $match))->toBe(1);

    return $match[0];
}

function navCtaEnquireHandler(): string
{
    return "document.getElementById('enquire')?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })";
}

it('phone right action is byte-identical to the null default', function () {
    [$site, $page] = makeNavCtaSite();
    $baseline = renderNavCta($site, $page);
    $site->update(['right_action' => 'phone']);

    expect(renderNavCta($site, $page))->toBe($baseline);
});

it('cta right action with a url target keeps today\'s button markup and rejects a bad url', function () {
    [$site, $page] = makeNavCtaSite([
        'right_action' => 'cta',
        'nav_cta_label' => 'Book',
        'nav_cta_url' => '/book',
    ]);
    $html = renderNavCta($site, $page);
    $header = navCtaHeader($html);

    expect($header)->toContain('href="/book"')
        ->toContain('>Book<')
        ->toContain('hidden lg:inline-flex items-center gap-2 px-5 py-2.5 rounded-md font-bold text-sm shadow-md')
        ->not->toContain('id="enquire"')
        ->not->toContain("getElementById('enquire')")
        ->not->toContain('text-white/70')
        ->not->toContain('hidden lg:flex items-center gap-10');

    $site->update(['nav_cta_url' => '//evil.example']);
    $rejected = renderNavCta($site, $page);
    expect($rejected)->not->toContain('evil.example')
        ->not->toContain('href="/book"')
        ->toContain('tel:');
});

it('renders each right action at lg, md and mobile', function (string $action, callable $assert) {
    [$solidSite, $solidPage] = makeNavCtaSite([
        'right_action' => $action,
        'nav_cta_label' => 'Book',
        'nav_cta_url' => '/book',
    ]);
    [$overlaySite, $overlayPage] = makeNavCtaSite([
        'header_mode' => 'overlay',
        'right_action' => $action,
        'nav_cta_label' => 'Book',
        'nav_cta_url' => '/book',
    ]);

    $assert(navCtaHeader(renderNavCta($solidSite, $solidPage)), overlay: false);
    $assert(navCtaHeader(renderNavCta($overlaySite, $overlayPage)), overlay: true);
})->with([
    'phone' => ['phone', function (string $header, bool $overlay): void {
        expect($header)->toContain('hidden lg:flex items-center gap-2 px-5 py-2.5 rounded-md font-bold text-sm shadow-md')
            ->toContain('<svg class="w-4 h-4"')
            ->toContain('0161 123 4567')
            ->not->toContain('hidden lg:inline-flex')
            ->not->toContain('>Book<')
            ->toContain('md:hidden')
            ->toContain('p-2 rounded-md');
        if ($overlay) {
            expect($header)->toMatch('/class="hidden md:flex lg:hidden items-center p-2 js-ovl"/');
        } else {
            expect($header)->not->toContain('hidden md:flex lg:hidden');
        }
    }],
    'cta' => ['cta', function (string $header, bool $overlay): void {
        expect($header)->toContain('href="/book"')
            ->toContain('hidden lg:inline-flex items-center gap-2 px-5 py-2.5 rounded-md font-bold text-sm shadow-md')
            ->toContain('px-3 py-1.5 rounded text-xs font-bold')
            ->toContain('>Book<')
            ->not->toContain('hidden lg:flex items-center gap-2 px-5 py-2.5 rounded-md font-bold text-sm shadow-md')
            ->not->toContain('block w-full text-center');
        if ($overlay) {
            expect($header)->toContain('hidden md:inline-flex lg:hidden items-center gap-2 px-5 py-2.5 rounded-md font-bold text-sm shadow-md');
        } else {
            expect($header)->not->toContain('hidden md:inline-flex lg:hidden');
        }
    }],
    'phone_cta' => ['phone_cta', function (string $header, bool $overlay): void {
        expect($header)->toContain('hidden lg:flex items-center gap-10')
            ->toContain('font-medium text-sm')
            ->toContain('href="tel:0161 123 4567"')
            ->toContain('href="/book"')
            ->toContain('>Book<')
            ->toContain('hidden md:flex lg:hidden items-center gap-3')
            ->toContain('aria-label="Call 0161 123 4567"')
            ->toContain('block w-full text-center px-5 py-2.5 rounded-md font-bold text-sm')
            ->not->toContain('hidden lg:flex items-center gap-2 px-5 py-2.5 rounded-md font-bold')
            ->not->toContain('px-3 py-1.5 rounded text-xs font-bold');
        if ($overlay) {
            expect($header)->toContain("(! scrolled) ? 'text-white/70 hover:text-white'")
                ->toContain('js-ovl');
        } else {
            expect($header)->not->toContain('text-white/70');
        }
        expect($header)->not->toMatch('/hidden lg:flex items-center gap-10[\s\S]*<svg class="w-4 h-4"/');
    }],
    'none' => ['none', function (string $header, bool $overlay): void {
        expect($header)->not->toContain('href="/book"')
            ->not->toContain('>Book<')
            ->not->toContain('hidden lg:flex items-center gap-2 px-5 py-2.5')
            ->not->toContain('hidden lg:inline-flex')
            ->not->toContain('hidden lg:flex items-center gap-10')
            ->not->toContain('aria-label="Call 0161 123 4567"')
            ->toContain('aria-controls="mobile-nav-panel"');
        if ($overlay) {
            expect($header)->not->toContain('hidden md:flex lg:hidden');
        }
    }],
]);

it('form target on a lead_form page anchors the first form only and wires the click handler', function () {
    [$site, $page] = makeNavCtaSite(
        [
            'right_action' => 'cta',
            'nav_cta_label' => 'Book',
            'nav_cta_target' => 'form',
            'nav_cta_url' => 'javascript:alert(1)',
        ],
        [
            ['type' => 'hero', 'title' => 'Welcome to Acme'],
            ['type' => 'lead_form', 'title' => 'First form', 'intro' => 'A', 'submit_label' => 'Send'],
            ['type' => 'lead_form', 'title' => 'Second form', 'intro' => 'B', 'submit_label' => 'Send'],
        ],
        ['lead_form_policy' => 'home'],
    );
    $html = renderNavCta($site, $page);
    $header = navCtaHeader($html);

    expect($header)->toContain('href="#enquire"')
        ->toContain('>Book<')
        ->toContain(navCtaEnquireHandler())
        ->not->toContain('javascript:')
        ->not->toContain('href="/contact"');
    expect(substr_count($html, 'id="enquire"'))->toBe(1)
        ->and($html)->toContain('id="enquire"')
        ->and($html)->toContain('scroll-mt-24 md:scroll-mt-28')
        ->and($html)->toContain('First form')
        ->and($html)->toContain('Second form');
    expect(preg_match_all('/id="enquire"/', $html))->toBe(1);
});

it('form target on a service page with an injected lead form uses #enquire', function () {
    $site = Site::factory()->create([
        'business_name' => 'Acme Plumbing',
        'theme' => 'trades-bold',
        'right_action' => 'cta',
        'nav_cta_label' => 'Book',
        'nav_cta_target' => 'form',
    ]);
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => [
            'archetype' => Archetype::EmergencyTrade->value,
            'lead_form_policy' => 'all',
            'contact' => ['phones' => ['0161 123 4567']],
        ],
    ]);
    $homePage = GeneratedPage::factory()->for($site)->create(['page_type' => 'home', 'kind' => PageKind::Core]);
    $homeRev = PageRevision::factory()->for($homePage, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'hero', 'title' => 'Home Hero'],
            ['type' => 'lead_form', 'title' => 'Get a quote', 'intro' => 'Tell us about it.', 'submit_label' => 'Send'],
        ]],
    ]);
    $homePage->update(['published_revision_id' => $homeRev->id]);
    $servicePage = GeneratedPage::factory()->for($site)->create([
        'page_type' => 'plumbing-emergency',
        'kind' => PageKind::Service,
        'nav_label' => 'Emergency Plumbing',
    ]);
    $serviceRev = PageRevision::factory()->for($servicePage, 'page')->create([
        'content_data' => ['sections' => [
            ['type' => 'intro', 'title' => 'Service intro'],
            ['type' => 'cta', 'title' => 'Ready to book?'],
        ]],
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

    $html = renderNavCta($site, $servicePage);

    expect(navCtaHeader($html))->toContain('href="#enquire"')
        ->toContain(navCtaEnquireHandler());
    expect(substr_count($html, 'id="enquire"'))->toBe(1)
        ->and($html)->toContain('Get a free Emergency Plumbing quote');
});

it('form target on a contact page with an absorbed form uses #enquire', function () {
    [$site, $page] = makeNavCtaSite(
        [
            'right_action' => 'cta',
            'nav_cta_label' => 'Book',
            'nav_cta_target' => 'form',
        ],
        [
            ['type' => 'details', 'title' => 'Contact', 'items' => [['label' => 'Email', 'value' => 'hello@acme.test']]],
            ['type' => 'contact_form', 'title' => 'Get in touch'],
        ],
        [],
        'contact',
    );
    $html = renderNavCta($site, $page);

    expect(navCtaHeader($html))->toContain('href="#enquire"')
        ->toContain(navCtaEnquireHandler());
    expect(substr_count($html, 'id="enquire"'))->toBe(1)
        ->and($html)->toContain('id="enquire"')
        ->and($html)->toContain('scroll-mt-24 md:scroll-mt-28')
        ->and($html)->toContain('id="contact"');
});

it('form target on About falls back to /contact with no id or handler', function () {
    [$site, $page] = makeNavCtaSite(
        [
            'right_action' => 'cta',
            'nav_cta_label' => 'Book',
            'nav_cta_target' => 'form',
            'nav_cta_url' => '/book',
        ],
        [
            ['type' => 'story', 'title' => 'Our Story', 'body' => 'Once upon a time.'],
        ],
        [],
        'about',
    );
    $html = renderNavCta($site, $page);
    $header = navCtaHeader($html);

    expect($header)->toContain('href="/contact"')
        ->toContain('>Book<')
        ->not->toContain('href="#enquire"')
        ->not->toContain(navCtaEnquireHandler())
        ->not->toContain('href="/book"');
    expect($html)->not->toContain('id="enquire"');
});

it('form mode with an empty label defaults to Get a free quote', function () {
    [$site, $page] = makeNavCtaSite(
        [
            'right_action' => 'phone_cta',
            'nav_cta_target' => 'form',
        ],
        [
            ['type' => 'hero', 'title' => 'Welcome to Acme'],
            ['type' => 'lead_form', 'title' => 'Quote', 'intro' => 'A', 'submit_label' => 'Send'],
        ],
        ['lead_form_policy' => 'home'],
    );
    $html = renderNavCta($site, $page);

    expect(navCtaHeader($html))->toContain('>Get a free quote<')
        ->toContain('href="#enquire"')
        ->toContain('hidden lg:flex items-center gap-10');
});

it('none also removes the utility-bar phone', function () {
    [$site, $page] = makeNavCtaSite(['right_action' => 'none']);
    $html = renderNavCta($site, $page);
    // utility bar + header only — the footer legitimately prints the phone
    $chrome = substr($html, 0, strpos($html, '</header>'));

    expect($chrome)->not->toContain('href="tel:')
        ->not->toContain('0161 123 4567');
});

it('form target on a page whose lead form is policy-suppressed falls back to /contact', function () {
    [$site, $page] = makeNavCtaSite(
        ['right_action' => 'cta', 'nav_cta_label' => 'Book', 'nav_cta_target' => 'form'],
        [
            ['type' => 'hero', 'title' => 'Roofing'],
            ['type' => 'lead_form', 'title' => 'Get in touch', 'extra_fields' => []],
        ],
        ['lead_form_policy' => 'home'],
        'roofing',
        PageKind::Service,
    );
    $html = renderNavCta($site, $page);
    $header = navCtaHeader($html);

    expect($header)->toContain('href="/contact"')
        ->not->toContain('href="#enquire"')
        ->not->toContain(navCtaEnquireHandler());
    expect($html)->not->toContain('id="enquire"');
});

it('the enquire anchor gets a scroll margin that follows the solid header height; absent otherwise', function () {
    [$site, $page] = makeNavCtaSite(
        ['right_action' => 'cta', 'nav_cta_label' => 'Book', 'nav_cta_target' => 'form', 'header_padding' => 3],
        [['type' => 'hero', 'title' => 'Welcome'], ['type' => 'lead_form', 'title' => 'Get in touch', 'extra_fields' => []]],
        ['lead_form_policy' => 'home', 'home_lead_form_enabled' => true],
    );
    $html = renderNavCta($site, $page);
    $expected = \App\Services\Site\HeaderPresentation::scrolledHeaderHeight($site->fresh());
    expect($html)->toContain('<style>#enquire{scroll-margin-top:calc('.$expected.' + 0.5rem)}</style>')
        ->and($expected)->toBe('calc(7.875rem + 6px)');

    [$site2, $page2] = makeNavCtaSite(['right_action' => 'cta', 'nav_cta_label' => 'Book', 'nav_cta_target' => 'url', 'nav_cta_url' => 'https://example.com/book']);
    expect(renderNavCta($site2, $page2))->not->toContain('#enquire{scroll-margin-top');
});

<?php

use App\Enums\PageKind;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Site;
use App\Models\Site\PageRevision;
use App\Models\Site\SiteVersion;
use App\Models\Site\SiteVersionCurrent;
use App\Services\Site\HeaderPresentation;
use App\Services\Site\PageRenderer;

/**
 * Home page for hero-CTA target pins. nav_cta_target is never set — the
 * smart hero path must work with the knob at its default (url).
 *
 * @param  array<string, mixed>  $siteAttrs
 * @param  list<array<string, mixed>>  $sections
 * @param  array<string, mixed>  $profileData
 * @return array{0: Site, 1: GeneratedPage}
 */
function makeHeroCtaHome(
    array $sections = [],
    array $profileData = [],
    array $siteAttrs = [],
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
            ['type' => 'hero', 'title' => 'Welcome to Acme', 'cta_label' => 'Get a quote'],
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

function renderHeroCta(Site $site, GeneratedPage $page): string
{
    return app(PageRenderer::class)->render($site->fresh(), $page->id, mode: 'public');
}

function heroCtaTag(string $html): string
{
    expect(preg_match(
        '/<a href="[^"]*"[^>]*class="inline-flex items-center justify-center gap-2 font-bold px-8 py-4[^"]*"[^>]*>/s',
        $html,
        $match,
    ))->toBe(1);

    return $match[0];
}

/**
 * @return list<string>
 */
function heroCtaTags(string $html): array
{
    expect(preg_match_all(
        '/<a href="[^"]*"[^>]*class="inline-flex items-center justify-center gap-2 font-bold px-8 py-4[^"]*"[^>]*>/s',
        $html,
        $matches,
    ))->toBeGreaterThan(0);

    return $matches[0];
}

/**
 * Two-slide image scene so hero.blade.php delegates to _hero_scene.
 *
 * @param  list<string|null>|string|null  $ctaActions  per-slide cta_action, or one value for every slide
 */
function attachHeroCtaScene(Site $site, array|string|null $ctaActions = null): void
{
    $actions = is_array($ctaActions) ? $ctaActions : [$ctaActions, $ctaActions];
    $slides = [];
    foreach ([1, 2] as $n) {
        $hv = HeroVersion::factory()->for($site)->create([
            'page_type' => 'home',
            'slot' => 'hero',
            'url' => "https://cdn.example/scene-slide-{$n}.webp",
            'watermark_url' => null,
            'prompt' => "slide {$n}",
            'is_active' => false,
        ]);
        $slides[] = [
            'asset_type' => 'hero_version',
            'asset_id' => $hv->id,
            'heading' => "Slide {$n}",
            'subheading' => null,
            'cta_label' => 'Get a quote',
            'cta_action' => $actions[$n - 1] ?? null,
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

function attachHeroCtaPublishedPage(Site $site, string $pageType): GeneratedPage
{
    $extra = GeneratedPage::factory()->for($site)->create([
        'page_type' => $pageType,
        'kind' => PageKind::Core,
    ]);
    $rev = PageRevision::factory()->for($extra, 'page')->create([
        'content_data' => ['sections' => [['type' => 'hero', 'title' => ucfirst($pageType)]]],
    ]);
    $extra->update(['published_revision_id' => $rev->id]);

    $current = SiteVersionCurrent::query()->where('site_id', $site->id)->firstOrFail();
    $version = SiteVersion::query()->findOrFail($current->version_id);
    $pageRevisions = $version->page_revisions;
    $pageRevisions[] = ['page_id' => $extra->id, 'revision_id' => $rev->id];
    $version->update(['page_revisions' => $pageRevisions]);

    return $extra;
}

function heroCtaEnquireHandler(): string
{
    return "document.getElementById('enquire')?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })";
}

function heroCtaHeader(string $html): string
{
    expect(preg_match('/<header\b.*<\/header>/s', $html, $match))->toBe(1);

    return $match[0];
}

it('a home page with a lead form points the hero CTA at #enquire, with one anchor, handler, and scroll-margin', function () {
    [$site, $page] = makeHeroCtaHome(
        [
            ['type' => 'hero', 'title' => 'Welcome to Acme', 'cta_label' => 'Get a quote'],
            ['type' => 'lead_form', 'title' => 'Get a quote', 'intro' => 'Tell us about it.', 'submit_label' => 'Send'],
        ],
        ['lead_form_policy' => 'home'],
    );
    expect($site->nav_cta_target)->toBeNull();

    $html = renderHeroCta($site, $page);
    $tag = heroCtaTag($html);

    expect($tag)->toContain('href="#enquire"')
        ->toContain(heroCtaEnquireHandler())
        ->toContain('x-data @click.prevent=');
    expect(substr_count($html, 'id="enquire"'))->toBe(1)
        ->and($html)->toContain('scroll-mt-24 md:scroll-mt-28')
        ->and($html)->toContain('<style>#enquire{scroll-margin-top:calc('.HeaderPresentation::scrolledHeaderHeight($site->fresh()).' + 0.5rem)}</style>');
    expect(heroCtaHeader($html))->not->toContain('href="#enquire"')
        ->not->toContain("getElementById('enquire')");
});

it('a home page without a lead form keeps today\'s contact URL on the hero CTA', function () {
    [$site, $page] = makeHeroCtaHome();
    expect($site->nav_cta_target)->toBeNull();

    $html = renderHeroCta($site, $page);
    $tag = heroCtaTag($html);

    expect($tag)->toContain('href="#contact"')
        ->not->toContain('href="#enquire"')
        ->not->toContain("getElementById('enquire')");
    expect($html)->not->toContain('id="enquire"')
        ->not->toContain('#enquire{scroll-margin-top');
});

it('an explicit authored hero cta_url other than #contact is never overridden', function () {
    [$site, $page] = makeHeroCtaHome(
        [
            ['type' => 'hero', 'title' => 'Welcome to Acme', 'cta_label' => 'Book now', 'cta_url' => '/book'],
            ['type' => 'lead_form', 'title' => 'Get a quote', 'intro' => 'Tell us about it.', 'submit_label' => 'Send'],
        ],
        ['lead_form_policy' => 'home'],
    );
    expect($site->nav_cta_target)->toBeNull();

    $html = renderHeroCta($site, $page);
    $tag = heroCtaTag($html);

    expect($tag)->toContain('href="/book"')
        ->not->toContain('href="#enquire"')
        ->not->toContain("getElementById('enquire')");
    expect(substr_count($html, 'id="enquire"'))->toBe(1);
});

it('an authored hero cta_url of #contact is rewritten to #enquire when the home form exists', function () {
    [$site, $page] = makeHeroCtaHome(
        [
            ['type' => 'hero', 'title' => 'Welcome to Acme', 'cta_label' => 'Get a quote', 'cta_url' => '#contact'],
            ['type' => 'lead_form', 'title' => 'Get a quote', 'intro' => 'Tell us about it.', 'submit_label' => 'Send'],
        ],
        ['lead_form_policy' => 'home'],
    );
    expect($site->nav_cta_target)->toBeNull();

    $tag = heroCtaTag(renderHeroCta($site, $page));

    expect($tag)->toContain('href="#enquire"')
        ->toContain(heroCtaEnquireHandler());
});

it('a scene-mode home with a lead form points every slide CTA at #enquire, with one anchor, handler, and scroll-margin', function () {
    [$site, $page] = makeHeroCtaHome(
        [
            ['type' => 'hero', 'title' => 'Welcome to Acme', 'cta_label' => 'Get a quote'],
            ['type' => 'lead_form', 'title' => 'Get a quote', 'intro' => 'Tell us about it.', 'submit_label' => 'Send'],
        ],
        ['lead_form_policy' => 'home'],
    );
    attachHeroCtaScene($site);
    expect($site->fresh()->nav_cta_target)->toBeNull();

    $html = renderHeroCta($site, $page);
    $tags = heroCtaTags($html);

    expect($tags)->toHaveCount(2);
    foreach ($tags as $tag) {
        expect($tag)->toContain('href="#enquire"')
            ->toContain(heroCtaEnquireHandler())
            ->toContain('x-data @click.prevent=');
    }
    expect(substr_count($html, 'id="enquire"'))->toBe(1)
        ->and($html)->toContain('scroll-mt-24 md:scroll-mt-28')
        ->and($html)->toContain('<style>#enquire{scroll-margin-top:calc('.HeaderPresentation::scrolledHeaderHeight($site->fresh()).' + 0.5rem)}</style>');
    expect(heroCtaHeader($html))->not->toContain('href="#enquire"')
        ->not->toContain("getElementById('enquire')");
});

it('a scene-mode home without a lead form keeps today\'s contact URL on every slide CTA', function () {
    [$site, $page] = makeHeroCtaHome();
    attachHeroCtaScene($site);
    expect($site->fresh()->nav_cta_target)->toBeNull();

    $html = renderHeroCta($site, $page);
    $tags = heroCtaTags($html);

    expect($tags)->toHaveCount(2);
    foreach ($tags as $tag) {
        expect($tag)->toContain('href="#contact"')
            ->not->toContain('href="#enquire"')
            ->not->toContain("getElementById('enquire')");
    }
    expect($html)->not->toContain('id="enquire"')
        ->not->toContain('#enquire{scroll-margin-top');
});

it('an authored scene slide cta_action other than contact is never overridden', function () {
    [$site, $page] = makeHeroCtaHome(
        [
            ['type' => 'hero', 'title' => 'Welcome to Acme', 'cta_label' => 'Get a quote'],
            ['type' => 'lead_form', 'title' => 'Get a quote', 'intro' => 'Tell us about it.', 'submit_label' => 'Send'],
        ],
        ['lead_form_policy' => 'home'],
    );
    attachHeroCtaPublishedPage($site, 'about');
    attachHeroCtaScene($site, 'about');
    expect($site->fresh()->nav_cta_target)->toBeNull();

    $html = renderHeroCta($site, $page);
    $tags = heroCtaTags($html);

    expect($tags)->toHaveCount(2);
    foreach ($tags as $tag) {
        expect($tag)->toContain('href="/about"')
            ->not->toContain('href="#enquire"')
            ->not->toContain("getElementById('enquire')");
    }
    expect(substr_count($html, 'id="enquire"'))->toBe(1);
});

it('an authored scene slide cta_action of contact resolves to the contact page URL when the home form exists', function () {
    [$site, $page] = makeHeroCtaHome(
        [
            ['type' => 'hero', 'title' => 'Welcome to Acme', 'cta_label' => 'Get a quote'],
            ['type' => 'lead_form', 'title' => 'Get a quote', 'intro' => 'Tell us about it.', 'submit_label' => 'Send'],
        ],
        ['lead_form_policy' => 'home'],
    );
    attachHeroCtaPublishedPage($site, 'contact');
    attachHeroCtaScene($site, 'contact');
    expect($site->fresh()->nav_cta_target)->toBeNull();

    $html = renderHeroCta($site, $page);
    $tags = heroCtaTags($html);

    expect($tags)->toHaveCount(2);
    foreach ($tags as $tag) {
        expect($tag)->toContain('href="/contact"')
            ->not->toContain('href="#enquire"')
            ->not->toContain("getElementById('enquire')");
    }
    expect(substr_count($html, 'id="enquire"'))->toBe(1);
});

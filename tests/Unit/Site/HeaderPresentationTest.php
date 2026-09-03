<?php

use App\Enums\LogoSize;
use App\Enums\PageKind;
use App\Models\BusinessProfile;
use App\Models\GeneratedPage;
use App\Models\HeroVersion;
use App\Models\Site;
use App\Services\Site\HeaderPresentation;
use App\Services\Site\HeroSceneService;

function hp(
    array $site = [],
    string $pageType = 'home',
    array $sections = [],
    array $heroImages = [],
    ?bool $leadFormAllowedHere = null,
    ?bool $contactFormRendered = null,
): bool {
    $s = new Site($site);
    $s->id = 1;
    $p = new GeneratedPage(['page_type' => $pageType, 'kind' => PageKind::Core]);
    $presentation = app(HeaderPresentation::class);

    if ($leadFormAllowedHere === null && $contactFormRendered === null) {
        return $presentation->overlayCapable($s, $p, $sections, $heroImages);
    }

    return $presentation->overlayCapable(
        $s,
        $p,
        $sections,
        $heroImages,
        $leadFormAllowedHere ?? false,
        $contactFormRendered ?? false,
    );
}

test('home hero with an active legacy image is capable', fn () => expect(hp([], 'home', [['type' => 'hero', 'title' => 'x']], ['home' => ['url' => 'https://x/h.jpg']]))->toBeTrue());

test('boxed-left and panel-left effective variants are not capable', function (string $v) {
    expect(hp([], 'home', [['type' => 'hero', 'variant' => $v]], ['home' => ['url' => 'u']]))->toBeFalse();
})->with(['boxed-left', 'panel-left']);

test('video counts only with a hero image present', function () {
    expect(hp(['home_hero_video_enabled' => true, 'home_hero_video_path' => 'v.mp4'], 'home', [['type' => 'hero']], []))->toBeFalse();
    expect(hp(['home_hero_video_enabled' => true, 'home_hero_video_path' => 'v.mp4'], 'home', [['type' => 'hero']], ['home' => ['url' => 'u']]))->toBeTrue();
});

test('leading __anchor is skipped', fn () => expect(hp([], 'home', [['type' => '__anchor', 'slug' => 'home'], ['type' => 'hero']], ['home' => ['url' => 'u']]))->toBeTrue());

test('home hero without image or scene or video is not capable', fn () => expect(hp([], 'home', [['type' => 'hero']], []))->toBeFalse());

test('hero_compact and feature_hero are never capable', function (string $pageType, string $type) {
    expect(hp([], $pageType, [['type' => $type]], ['home' => ['url' => 'u'], $pageType => ['url' => 'u']]))->toBeFalse();
})->with([['home', 'hero_compact'], ['home', 'feature_hero']]);

test('a projects page whose projects_hero has a visible image is capable', function () {
    expect(hp([], 'projects', [['type' => 'projects_hero']], ['home' => ['url' => 'u'], 'projects' => ['url' => 'u']]))->toBeTrue();
});

test('a projects_hero with hero_enabled false or no image is not capable', function () {
    expect(hp([], 'projects', [['type' => 'projects_hero', 'hero_enabled' => false]], ['home' => ['url' => 'u'], 'projects' => ['url' => 'u']]))->toBeFalse()
        ->and(hp([], 'projects', [['type' => 'projects_hero']], ['home' => ['url' => 'u']]))->toBeFalse();
});

test('an inner page whose first section is an image hero is capable', function () {
    expect(hp([], 'roofing', [['type' => 'hero']], ['home' => ['url' => 'u'], 'roofing' => ['url' => 'u']]))->toBeTrue();
});

test('an inner page hero without an image is not capable, and inner pages never consult the stored home scene', function () {
    $site = Site::factory()->create();
    $hero = HeroVersion::factory()->for($site)->active()->create([
        'page_type' => 'home',
        'slot' => 'hero',
        'url' => 'https://cdn.example/hero-home.jpg',
    ]);
    $site->home_hero_scene = [
        'kind' => 'image',
        'slides' => [['asset_type' => 'hero_version', 'asset_id' => $hero->id]],
    ];
    $page = new GeneratedPage(['page_type' => 'roofing', 'kind' => PageKind::Core]);

    expect(app(HeaderPresentation::class)->overlayCapable(
        $site,
        $page,
        [['type' => 'hero']],
        ['home' => ['url' => 'u']],
    ))->toBeFalse();
});

test('a disabled lead_form before the hero is skipped', fn () => expect(hp([], 'home', [['type' => 'lead_form'], ['type' => 'hero']], ['home' => ['url' => 'u']]))->toBeTrue());

test('an enabled lead_form rendering before the hero is not capable', fn () => expect(hp([], 'home', [['type' => 'lead_form'], ['type' => 'hero']], ['home' => ['url' => 'u']], leadFormAllowedHere: true))->toBeFalse());

test('an enabled unabsorbed contact_form rendering before the hero is not capable', fn () => expect(hp([], 'home', [['type' => 'contact_form'], ['type' => 'hero']], ['home' => ['url' => 'u']], contactFormRendered: true))->toBeFalse());

test('an absorbed or disabled contact_form before the hero is skipped', fn () => expect(hp([], 'home', [['type' => 'contact_form'], ['type' => 'hero']], ['home' => ['url' => 'u']], contactFormRendered: false))->toBeTrue());

test('placeholder heroes are not capable', function () {
    expect(hp([], 'home', [['type' => 'hero', 'placeholder' => true]], ['home' => ['url' => 'u']]))->toBeFalse();
});

test('overlay header height follows the logo size matrix', function (LogoSize $size, string $expected) {
    expect(HeaderPresentation::overlayHeaderHeight(new Site(['logo_size' => $size])))->toBe($expected);
})->with([
    [LogoSize::Standard, '8.75rem'],
    [LogoSize::Large, '10.9375rem'],
    [LogoSize::Compact, '5.75rem'],
]);

test('header height classes and overlayHeaderHeight share one matrix', function (LogoSize $size, string $mdUnscrolled) {
    $site = new Site(['logo_size' => $size]);
    $classes = HeaderPresentation::headerHeightClasses($site);
    $row = HeaderPresentation::HEADER_HEIGHTS[HeaderPresentation::logoSizeKey($site)];

    expect($classes['unscrolled'])->toBe($row['unscrolled']['mobile'].' '.$row['unscrolled']['md'])
        ->and($classes['unscrolled'])->toContain('md:h-['.$mdUnscrolled.']')
        ->and($row['unscrolled']['mobile'])->toStartWith('h-[')
        ->and($row['unscrolled']['md'])->toStartWith('md:h-[')
        ->and(HeaderPresentation::overlayHeaderHeight($site))->toBe($mdUnscrolled);
})->with([
    [LogoSize::Standard, '8.75rem'],
    [LogoSize::Large, '10.9375rem'],
    [LogoSize::Compact, '5.75rem'],
]);

test('saas_platform archetype without an explicit logo size uses compact overlay height', function () {
    $site = new Site(['logo_size' => LogoSize::Standard]);
    $site->setRelation('businessProfile', new BusinessProfile([
        'profile_data' => ['archetype' => 'saas_platform'],
    ]));

    expect(HeaderPresentation::overlayHeaderHeight($site))->toBe('5.75rem')
        ->and(HeaderPresentation::logoSizeKey($site))->toBe('compact')
        ->and(HeaderPresentation::overlayLogoSizeKey($site))->toBe('compact')
        ->and(HeaderPresentation::headerHeightClasses($site)['unscrolled'])->toContain('md:h-[5.75rem]');
});

test('overlayLogoSizeKey inherits logo_size when overlay_logo_size is null', function () {
    $site = new Site(['logo_size' => LogoSize::Large, 'overlay_logo_size' => null]);

    expect(HeaderPresentation::overlayLogoSizeKey($site))->toBe('large')
        ->and(HeaderPresentation::logoSizeKey($site))->toBe('large');
});

test('overlayLogoSizeKey is independent of header height which follows logo_size', function () {
    $site = new Site(['logo_size' => LogoSize::Standard, 'overlay_logo_size' => LogoSize::Large]);

    expect(HeaderPresentation::overlayLogoSizeKey($site))->toBe('large')
        ->and(HeaderPresentation::logoSizeKey($site))->toBe('standard')
        ->and(HeaderPresentation::overlayHeaderHeight($site))->toBe('8.75rem')
        ->and(HeaderPresentation::headerHeightClasses($site)['unscrolled'])->toContain('md:h-[8.75rem]')
        ->and(HeaderPresentation::headerHeightClasses($site)['unscrolled'])->not->toContain('md:h-[10.9375rem]');
});

test('overlayLogoSizeKey compact does not shrink the header when logo_size is standard', function () {
    $site = new Site(['logo_size' => LogoSize::Standard, 'overlay_logo_size' => LogoSize::Compact]);

    expect(HeaderPresentation::overlayLogoSizeKey($site))->toBe('compact')
        ->and(HeaderPresentation::logoSizeKey($site))->toBe('standard')
        ->and(HeaderPresentation::overlayHeaderHeight($site))->toBe('8.75rem');
});

test('overlayLogoSizeKey applies the saas heuristic to a standard overlay override', function () {
    $site = new Site(['logo_size' => LogoSize::Large, 'overlay_logo_size' => LogoSize::Standard]);
    $site->setRelation('businessProfile', new BusinessProfile([
        'profile_data' => ['archetype' => 'saas_platform'],
    ]));

    expect(HeaderPresentation::logoSizeKey($site))->toBe('large')
        ->and(HeaderPresentation::overlayLogoSizeKey($site))->toBe('compact')
        ->and(HeaderPresentation::overlayHeaderHeight($site))->toBe('10.9375rem');
});

test('overlayCapable reads the same heroImages key the hero paints from', function () {
    expect(hp([], 'home', [['type' => 'hero', '__page_type' => 'about']], ['home' => ['url' => 'u']]))->toBeFalse();
    expect(hp([], 'home', [['type' => 'hero', '__page_type' => 'about']], ['about' => ['url' => 'u']]))->toBeTrue();
});

test('overlayCapable honours a section background_image as an image source', function () {
    expect(hp([], 'home', [['type' => 'hero', 'background_image' => 'https://cdn.example/bg.jpg']], []))->toBeTrue();
    expect(hp([], 'home', [['type' => 'hero']], []))->toBeFalse();
});

test('overlayCapable memoises scene resolve per request keyed on page id', function () {
    $scenes = Mockery::mock(HeroSceneService::class);
    $scenes->shouldReceive('resolve')->once()->andReturn([
        'is_legacy' => false,
        'slides' => [['asset_url' => 'https://x/s.jpg']],
    ]);

    $site = new Site(['home_hero_scene' => ['slides' => [['x' => 1]]]]);
    $site->id = 7;
    $page = new GeneratedPage(['page_type' => 'home', 'kind' => PageKind::Core]);
    $page->id = 11;
    $sections = [['type' => 'hero', 'title' => 'x']];

    $first = new HeaderPresentation($scenes);
    $second = new HeaderPresentation($scenes);

    expect($first->overlayCapable($site, $page, $sections, []))->toBeTrue()
        ->and($second->overlayCapable($site, $page, $sections, []))->toBeTrue();
});

test('floating logo sizes the unscrolled row and the overlay clearance; the scrolled row follows the main logo', function () {
    $site = new Site(['logo_size' => LogoSize::Large, 'overlay_logo_size' => LogoSize::Compact]);
    $compact = new Site(['logo_size' => LogoSize::Compact]);
    $large = new Site(['logo_size' => LogoSize::Large]);

    $floating = HeaderPresentation::headerHeightClasses($site, true);

    expect($floating['unscrolled'])->toBe(HeaderPresentation::headerHeightClasses($compact)['unscrolled'])
        ->and($floating['scrolled'])->toBe(HeaderPresentation::headerHeightClasses($large)['scrolled'])
        ->and(HeaderPresentation::overlayHeaderHeight($site, true))->toBe(HeaderPresentation::overlayHeaderHeight($compact))
        ->and(HeaderPresentation::headerHeightClasses($site))->toBe(HeaderPresentation::headerHeightClasses($large))
        ->and(HeaderPresentation::overlayHeaderHeight($site))->toBe(HeaderPresentation::overlayHeaderHeight($large));
});

test('floating logo with no overlay size inherits the main row', function () {
    $site = new Site(['logo_size' => LogoSize::Large]);

    expect(HeaderPresentation::headerHeightClasses($site, true))->toBe(HeaderPresentation::headerHeightClasses($site))
        ->and(HeaderPresentation::overlayHeaderHeight($site, true))->toBe(HeaderPresentation::overlayHeaderHeight($site));
});

test('header_shrink off keeps the main unscrolled row when scrolled; padding widens the overlay clearance', function () {
    $site = new Site(['logo_size' => LogoSize::Standard]);
    $off = new Site(['logo_size' => LogoSize::Standard, 'header_shrink' => 'off']);

    expect(HeaderPresentation::headerHeightClasses($off)['scrolled'])->toBe(HeaderPresentation::headerHeightClasses($site)['unscrolled'])
        ->and(HeaderPresentation::headerHeightClasses($off)['unscrolled'])->toBe(HeaderPresentation::headerHeightClasses($site)['unscrolled'])
        ->and(HeaderPresentation::headerPaddingPx(new Site(['header_padding' => 40])))->toBe(24)
        ->and(HeaderPresentation::headerPaddingPx(new Site(['header_padding' => null])))->toBe(0)
        ->and(HeaderPresentation::overlayHeaderHeight(new Site(['logo_size' => LogoSize::Standard, 'header_padding' => 6])))->toBe('calc('.HeaderPresentation::overlayHeaderHeight($site).' + 12px)');
});

test('header_fit tight swaps in the logo-plus-1rem matrix for rows and the overlay clearance', function () {
    $tight = new Site(['logo_size' => LogoSize::Large, 'header_fit' => 'tight']);
    expect(HeaderPresentation::headerHeightClasses($tight))->toBe(['unscrolled' => 'h-[6.46875rem] md:h-[9.75rem]', 'scrolled' => 'h-[5.9375rem] md:h-[8.875rem]'])
        ->and(HeaderPresentation::overlayHeaderHeight($tight))->toBe('9.75rem')
        ->and(HeaderPresentation::headerHeightClasses(new Site(['logo_size' => LogoSize::Large])))->toBe(['unscrolled' => 'h-[9.375rem] md:h-[10.9375rem]', 'scrolled' => 'h-[8.4375rem] md:h-[9.84375rem]']);
});

test('overlay header height mobile follows the same matrix row', function (LogoSize $size, string $expected) {
    expect(HeaderPresentation::overlayHeaderHeightMobile(new Site(['logo_size' => $size])))->toBe($expected);
})->with([
    [LogoSize::Standard, '7.5rem'],
    [LogoSize::Large, '9.375rem'],
    [LogoSize::Compact, '5rem'],
]);

test('overlay header heights share the matrix row with headerHeightClasses on both breakpoints', function () {
    $site = new Site(['logo_size' => LogoSize::Standard]);
    $row = HeaderPresentation::heightMatrix($site)[HeaderPresentation::logoSizeKey($site)]['unscrolled'];
    expect(HeaderPresentation::overlayHeaderHeightMobile($site))->toBe(str_replace(['h-[', ']'], '', $row['mobile']))
        ->and(HeaderPresentation::overlayHeaderHeight($site))->toBe(str_replace(['md:h-[', ']'], '', $row['md']));
});

test('overlay header height mobile wraps padding the same way as md', function () {
    $plain = new Site(['logo_size' => LogoSize::Standard]);
    $padded = new Site(['logo_size' => LogoSize::Standard, 'header_padding' => 6]);

    expect(HeaderPresentation::overlayHeaderHeightMobile($padded))->toBe('calc('.HeaderPresentation::overlayHeaderHeightMobile($plain).' + 12px)')
        ->and(HeaderPresentation::overlayHeaderHeight($padded))->toBe('calc('.HeaderPresentation::overlayHeaderHeight($plain).' + 12px)');
});

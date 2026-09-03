<?php

use App\Enums\Shop\ShopSnapshotStatus;
use App\Models\Shop\Product;
use App\Models\Shop\ShopSnapshot;
use App\Models\Shop\ShopSnapshotCurrent;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $hero
 */
function shopHeroIndexHtml(string $host, array $hero = [], array $siteOverrides = []): string
{
    $site = Site::factory()->create(array_merge([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
    ], $siteOverrides));

    Product::factory()->for($site)->create(['slug' => 'rose', 'name' => 'Red Rose']);

    $json = [
        'meta' => [
            'site_id' => $site->id,
            'product_count' => 1,
            'headline' => 'Cut this morning',
        ],
        'categories' => [
            'bouquets' => [
                'id' => 1,
                'slug' => 'bouquets',
                'name' => 'Bouquets',
                'product_slugs' => ['rose'],
            ],
        ],
        'products' => [
            'rose' => [
                'id' => 1,
                'slug' => 'rose',
                'status' => 'published',
                'primary_category_slug' => 'bouquets',
                'price_cents' => 4500,
                'price_display' => '£45.00',
                'in_stock_any' => true,
                'variant_in_stock' => [1 => true],
                'image_urls' => null,
                'product_card' => ['slug' => 'rose', 'name' => 'Red Rose', 'price_display' => '£45.00'],
                'product_detail' => ['slug' => 'rose', 'name' => 'Red Rose', 'description' => 'Lovely'],
                'variants' => [['id' => 1, 'sku' => 'RR-1', 'label' => 'Std', 'price_cents' => 4500, 'image_urls' => null]],
                'is_ai_seeded' => false,
                'is_ai_reviewed' => false,
            ],
        ],
        'featured_slugs' => ['rose'],
        'hero_image_url' => '/shop-hero.jpg',
    ];

    foreach (['text_zone', 'hero_height', 'bg_position_y', 'hero_alt', 'hero_width', 'hero_enabled', 'hero_accent_word', 'hero_headline', 'hero_text_style'] as $key) {
        if (array_key_exists($key, $hero)) {
            $json[$key] = $hero[$key];
        }
    }

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => $json,
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);

    $html = test()->get('http://'.$host.'/shop')->assertOk()->getContent();
    preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $html, $main);
    expect($main)->not->toBeEmpty();

    return $main[1];
}

/**
 * @param  array<string, mixed>  $categoryHero
 * @param  array<string, mixed>  $snapshotExtras
 */
function shopHeroCategoryHtml(string $host, array $categoryHero = [], array $snapshotExtras = []): string
{
    $site = Site::factory()->create([
        'custom_domain' => $host,
        'custom_domain_status' => 'active',
        'business_name' => 'Bloom & Stem',
    ]);

    Product::factory()->published()->for($site)->create(['slug' => 'conserve', 'name' => 'Strawberry Conserve']);

    $category = array_merge([
        'id' => 1,
        'slug' => 'preserves',
        'name' => 'Preserves',
        'product_slugs' => ['conserve'],
        'hero_image_url' => '/cat-hero.jpg',
    ], $categoryHero);

    $snap = ShopSnapshot::create([
        'site_id' => $site->id,
        'version' => 1,
        'status' => ShopSnapshotStatus::Success,
        'json' => [
            'meta' => ['site_id' => $site->id, 'product_count' => 1],
            'categories' => [
                'preserves' => $category,
            ],
            'products' => [
                'conserve' => [
                    'id' => 1,
                    'slug' => 'conserve',
                    'status' => 'published',
                    'primary_category_slug' => 'preserves',
                    'price_cents' => 595,
                    'price_display' => '£5.95',
                    'in_stock_any' => true,
                    'variant_in_stock' => [1 => true],
                    'image_urls' => null,
                    'product_card' => ['slug' => 'conserve', 'name' => 'Strawberry Conserve', 'price_display' => '£5.95'],
                    'product_detail' => ['slug' => 'conserve', 'name' => 'Strawberry Conserve', 'description' => 'Jam'],
                    'variants' => [['id' => 1, 'sku' => 'CON', 'label' => 'Jar', 'price_cents' => 595, 'image_urls' => null]],
                    'is_ai_seeded' => false,
                    'is_ai_reviewed' => false,
                ],
            ],
            'featured_slugs' => [],
            ...$snapshotExtras,
        ],
        'built_at' => now(),
    ]);
    ShopSnapshotCurrent::create([
        'site_id' => $site->id,
        'snapshot_id' => $snap->id,
        'updated_at' => now(),
    ]);

    $html = test()->get('http://'.$host.'/collections/preserves')->assertOk()->getContent();
    preg_match('/<main\b[^>]*>(.*?)<\/main>/is', $html, $main);
    expect($main)->not->toBeEmpty();

    return $main[1];
}

function shopHeroGutterClass(): string
{
    return 'px-6 sm:px-10 md:px-14';
}

function shopHeroInnerScrimStops(): string
{
    return 'from-black/70 via-black/40 to-transparent';
}

function shopHeroLeftScrimGradient(): string
{
    return 'linear-gradient(to right, rgb(0 0 0 / 0.7), rgb(0 0 0 / 0.4), transparent)';
}

function shopHeroRightScrimGradient(): string
{
    return 'linear-gradient(to left, rgb(0 0 0 / 0.7), rgb(0 0 0 / 0.4), transparent)';
}

function shopHeroCenterScrimGradient(): string
{
    return 'linear-gradient(to right, transparent, rgb(0 0 0 / 0.7) 35%, rgb(0 0 0 / 0.4) 50%, rgb(0 0 0 / 0.7) 65%, transparent)';
}

test('a default snapshot hero pads the text column and gradients from the left', function () {
    $main = shopHeroIndexHtml('hero-default.example');

    expect($main)->toContain(shopHeroGutterClass())
        ->and($main)->toContain('relative z-10 w-full max-w-full '.shopHeroGutterClass())
        ->and($main)->toContain(shopHeroLeftScrimGradient())
        ->and($main)->toContain('bg-gradient-to-r '.shopHeroInnerScrimStops())
        ->and($main)->not->toContain('linear-gradient(to left,')
        ->and($main)->toContain('text-left')
        ->and($main)->toMatch('/<h1\b[^>]*>\s*Shop\s*<\/h1>/')
        ->and($main)->not->toContain('Bloom &amp; Stem')
        ->and($main)->not->toContain('Cut this morning');
});

test('shop and category heroes use the inner-hero neutral scrim, not the band-overlay wash', function (string $surface) {
    $main = $surface === 'index'
        ? shopHeroIndexHtml('hero-scrim-'.$surface.'.example')
        : shopHeroCategoryHtml('hero-scrim-'.$surface.'.example');

    expect($main)->toContain(shopHeroLeftScrimGradient())
        ->and($main)->toContain('bg-gradient-to-r '.shopHeroInnerScrimStops())
        ->and($main)->not->toContain('--color-band-overlay')
        ->and($main)->not->toContain('color-mix(');
})->with(['index', 'category']);

test('right text_zone heroes mirror the overlay and keep the gutter on shop and category', function (string $surface) {
    $main = $surface === 'index'
        ? shopHeroIndexHtml('hero-right-'.$surface.'.example', ['text_zone' => 'middle-right'])
        : shopHeroCategoryHtml('hero-right-'.$surface.'.example', ['text_zone' => 'middle-right']);

    expect($main)->toContain('relative z-10 w-full max-w-full '.shopHeroGutterClass())
        ->and($main)->toContain(shopHeroRightScrimGradient())
        ->and($main)->toContain('bg-gradient-to-l '.shopHeroInnerScrimStops())
        ->and($main)->toContain('text-right ml-auto')
        ->and($main)->not->toContain(shopHeroLeftScrimGradient());
})->with(['index', 'category']);

test('center text_zone heroes use a symmetric scrim and keep the gutter on shop and category', function (string $surface) {
    $main = $surface === 'index'
        ? shopHeroIndexHtml('hero-center-'.$surface.'.example', ['text_zone' => 'middle-center'])
        : shopHeroCategoryHtml('hero-center-'.$surface.'.example', ['text_zone' => 'middle-center']);

    expect($main)->toContain('relative z-10 w-full max-w-full '.shopHeroGutterClass())
        ->and($main)->toContain('text-center mx-auto')
        ->and($main)->toContain(shopHeroCenterScrimGradient())
        ->and($main)->not->toContain('linear-gradient(to left,');
})->with(['index', 'category']);

test('every text_zone keeps the padded text column on the shop index', function (string $zone) {
    $col = explode('-', $zone)[1];
    $main = shopHeroIndexHtml('hero-zone-'.str_replace('-', '', $zone).'.example', ['text_zone' => $zone]);

    expect($main)->toContain('relative z-10 w-full max-w-full '.shopHeroGutterClass());

    if ($col === 'right') {
        expect($main)->toContain('text-right ml-auto')
            ->and($main)->toContain(shopHeroRightScrimGradient());
    } elseif ($col === 'center') {
        expect($main)->toContain('text-center mx-auto')
            ->and($main)->toContain(shopHeroCenterScrimGradient());
    } else {
        expect($main)->toContain('text-left')
            ->and($main)->toContain(shopHeroLeftScrimGradient())
            ->and($main)->toContain('bg-gradient-to-r '.shopHeroInnerScrimStops());
    }
})->with([
    'top-left', 'top-center', 'top-right',
    'middle-left', 'middle-center', 'middle-right',
    'bottom-left', 'bottom-center', 'bottom-right',
]);

test('a default category hero pads the text column and gradients from the left', function () {
    $main = shopHeroCategoryHtml('hero-cat-default.example');

    expect($main)->toContain('relative z-10 w-full max-w-full '.shopHeroGutterClass())
        ->and($main)->toContain(shopHeroLeftScrimGradient())
        ->and($main)->toContain('Preserves')
        ->and($main)->not->toContain('w-8 h-px')
        ->and($main)->not->toContain('>Category</span>');
});

test('a default snapshot with no hero_width key renders a boxed padded hero', function () {
    $main = shopHeroIndexHtml('hero-boxed-default.example');

    expect($main)->toContain('px-4 sm:px-6 lg:px-8 py-6 max-w-full')
        ->and($main)->toContain('relative z-10 w-full max-w-full '.shopHeroGutterClass())
        ->and($main)->not->toContain('margin-left: calc(50% - 50vw)');
});

test('boxed hero_width stays inside the padded wrapper on shop and category', function (string $surface) {
    $main = $surface === 'index'
        ? shopHeroIndexHtml('hero-boxed-'.$surface.'.example', ['hero_width' => 'boxed'])
        : shopHeroCategoryHtml('hero-boxed-'.$surface.'.example', ['hero_width' => 'boxed']);

    expect($main)->toContain('px-4 sm:px-6 lg:px-8 py-6 max-w-full')
        ->and($main)->not->toContain('margin-left: calc(50% - 50vw)');

    if ($surface === 'category') {
        expect($main)->toContain('<nav aria-label="Breadcrumb"')
            ->and(strpos($main, '<section'))->toBeLessThan(strpos($main, '<nav aria-label="Breadcrumb"'));
    } else {
        expect($main)->not->toContain('<nav aria-label="Breadcrumb"');
    }
})->with(['index', 'category']);

test('hero_enabled false falls back to the plain h1 on the shop index', function () {
    $main = shopHeroIndexHtml('hero-off-index.example', ['hero_enabled' => false]);

    expect($main)->toContain('<h1 class="text-3xl md:text-4xl font-extrabold mt-4">Shop</h1>')
        ->and($main)->not->toContain('/shop-hero.jpg')
        ->and($main)->not->toContain('linear-gradient(')
        ->and($main)->not->toContain('Bloom &amp; Stem');
});

test('the plain no-hero h1 uses hero_headline when set', function () {
    $main = shopHeroIndexHtml('hero-off-headline-index.example', [
        'hero_enabled' => false,
        'hero_headline' => 'Bouquets & Botanicals',
    ]);

    expect($main)->toContain('<h1 class="text-3xl md:text-4xl font-extrabold mt-4">Bouquets &amp; Botanicals</h1>')
        ->and($main)->not->toContain('linear-gradient(');
});

test('hero_enabled false falls back to the plain h1 on the category page', function () {
    $main = shopHeroCategoryHtml('hero-off-category.example', ['hero_enabled' => false]);

    expect($main)->toContain('Preserves')
        ->and($main)->toMatch('/<h1\b[^>]*>\s*Preserves\s*<\/h1>/')
        ->and($main)->not->toContain('/cat-hero.jpg')
        ->and($main)->not->toContain('linear-gradient(');
});

test('a missing hero_enabled key still renders the hero on shop and category', function (string $surface) {
    $main = $surface === 'index'
        ? shopHeroIndexHtml('hero-enabled-default-'.$surface.'.example')
        : shopHeroCategoryHtml('hero-enabled-default-'.$surface.'.example');

    expect($main)->toContain('linear-gradient(')
        ->and($main)->toContain('relative z-10 w-full max-w-full '.shopHeroGutterClass());
})->with(['index', 'category']);

test('full hero_width breaks out to the viewport and sits flush under the header on both surfaces', function (string $surface) {
    $main = $surface === 'index'
        ? shopHeroIndexHtml('hero-full-'.$surface.'.example', ['hero_width' => 'full'])
        : shopHeroCategoryHtml('hero-full-'.$surface.'.example', ['hero_width' => 'full']);

    // Full-bleed text re-enters the container with the INNER-page hero
    // gutter so the title starts at the same x as About/Contact titles —
    // not the boxed image-card gutter.
    expect($main)->toContain('px-4 sm:px-6 lg:px-8 py-6 max-w-full')
        ->and($main)->toContain('margin-left: calc(50% - 50vw); margin-right: calc(50% - 50vw);')
        ->and($main)->toContain('relative z-10 w-full max-w-full site-shell-container px-4 sm:px-6 lg:px-8')
        ->and($main)->not->toContain('relative z-10 w-full max-w-full '.shopHeroGutterClass())
        // Full-width sits flush under the header on both surfaces; boxed
        // (covered elsewhere) keeps its top margin.
        ->and($main)->toContain('margin-top: -1.5rem;');

    if ($surface === 'index') {
        expect($main)->not->toContain('<nav aria-label="Breadcrumb"');
    } else {
        expect($main)->toContain('<nav aria-label="Breadcrumb"');
    }
})->with(['index', 'category']);

function shopHeroInnerTitleClass(): string
{
    return 'text-[clamp(1.875rem,3vw,3rem)] font-extrabold text-white drop-shadow-[0_2px_8px_rgba(0,0,0,0.7)] mb-3 leading-tight [text-wrap:balance]';
}

function shopHeroInnerSubtitleClass(): string
{
    return 'text-base md:text-lg text-white/80 drop-shadow-[0_2px_8px_rgba(0,0,0,0.7)] max-w-xl leading-relaxed';
}

function shopHeroInnerEyebrowClass(): string
{
    return 'flex text-sm font-semibold tracking-widest uppercase mb-4 items-center gap-2 text-white drop-shadow-[0_2px_8px_rgba(0,0,0,0.7)]';
}

test('storefront hero type matches the inner-hero clamp, weight, drop-shadow and subtitle', function () {
    $main = shopHeroIndexHtml('hero-type-index.example');

    expect($main)->toContain(shopHeroInnerTitleClass())
        ->and($main)->toMatch('/<h1\b[^>]*>\s*Shop\s*<\/h1>/')
        ->and($main)->not->toContain(shopHeroInnerEyebrowClass())
        ->and($main)->not->toContain('Bloom &amp; Stem')
        ->and($main)->not->toContain('Cut this morning')
        ->and($main)->not->toContain('text-4xl md:text-5xl lg:text-6xl')
        ->and($main)->not->toContain('var(--color-text-on-band)');
});

test('category hero type matches the inner-hero clamp and drop-shadow on the photo', function () {
    $main = shopHeroCategoryHtml('hero-type-category.example', [
        'description' => 'Seasonal jars from the pantry.',
    ]);

    expect($main)->toContain(shopHeroInnerTitleClass())
        ->and($main)->toContain(shopHeroInnerSubtitleClass())
        ->and($main)->not->toContain(shopHeroInnerEyebrowClass())
        ->and($main)->toContain('Preserves')
        ->and($main)->toContain('Seasonal jars from the pantry.')
        ->and($main)->not->toContain('<h1 class="text-3xl md:text-4xl font-extrabold mt-4">Preserves</h1>')
        ->and($main)->not->toContain('var(--color-text-on-band)');
});

test('storefront hero wraps the accent word when hero_accent_word is set', function () {
    $main = shopHeroIndexHtml('hero-accent-on.example', [
        'hero_headline' => 'Bouquets & Botanicals',
        'hero_accent_word' => 'Botanicals',
    ]);

    expect($main)->toContain('<span class="accent-word" style="color: var(--color-accent);">Botanicals</span>')
        ->and($main)->toContain('Bouquets &amp; ');
});

test('storefront hero does not wrap an accent when hero_accent_word is empty or omitted', function (?string $word) {
    $hero = ['hero_headline' => 'Bouquets & Botanicals'];
    if ($word !== null) {
        $hero['hero_accent_word'] = $word;
    }
    $main = shopHeroIndexHtml('hero-accent-off-'.md5((string) $word).'.example', $hero);

    expect($main)->not->toContain('accent-word')
        ->and($main)->toContain('Bouquets &amp; Botanicals');
})->with([null, '', '   ']);

test('storefront hero italicises the accent word when the site accent style is italic', function () {
    $main = shopHeroIndexHtml(
        'hero-accent-italic.example',
        ['hero_headline' => 'Bouquets & Botanicals', 'hero_accent_word' => 'Botanicals'],
        ['accent_style' => 'italic'],
    );

    expect($main)->toContain('<span class="accent-word" style="color: var(--color-accent); font-style: italic;">Botanicals</span>');
});

test('category hero wraps the accent word when hero_accent_word is set', function () {
    $main = shopHeroCategoryHtml('hero-accent-category.example', ['hero_accent_word' => 'Preserves']);

    expect($main)->toContain('<span class="accent-word" style="color: var(--color-accent);">Preserves</span>');
});

test('shop and category heroes omit the ruled eyebrow', function (string $surface) {
    $main = $surface === 'index'
        ? shopHeroIndexHtml('hero-no-eyebrow-'.$surface.'.example')
        : shopHeroCategoryHtml('hero-no-eyebrow-'.$surface.'.example');

    expect($main)->not->toContain('w-8 h-px')
        ->and($main)->not->toContain(shopHeroInnerEyebrowClass());
})->with(['index', 'category']);

test('storefront hero h1 uses hero_headline when it is set', function () {
    $main = shopHeroIndexHtml('hero-headline-set.example', [
        'hero_headline' => 'Cakes & Patisserie',
    ]);

    expect($main)->toContain('Cakes &amp; Patisserie')
        ->and($main)->not->toContain('Bloom &amp; Stem')
        ->and($main)->not->toContain('Cut this morning');
});

test('storefront hero h1 falls back to Shop when hero_headline is missing or empty', function (?string $headline) {
    $hero = $headline === null ? [] : ['hero_headline' => $headline];
    $main = shopHeroIndexHtml('hero-headline-fallback-'.md5((string) $headline).'.example', $hero);

    expect($main)->toMatch('/<h1\b[^>]*>\s*Shop\s*<\/h1>/')
        ->and($main)->not->toContain('Bloom &amp; Stem');
})->with([null, '', '   ']);

test('storefront hero wraps the accent word inside hero_headline', function () {
    $main = shopHeroIndexHtml('hero-headline-accent.example', [
        'hero_headline' => 'Cakes & Patisserie',
        'hero_accent_word' => 'Patisserie',
    ]);

    expect($main)->toContain('<span class="accent-word" style="color: var(--color-accent);">Patisserie</span>')
        ->and($main)->toContain('Cakes &amp; ');
});

test('storefront hero does not render the dead meta.headline subtitle', function () {
    $main = shopHeroIndexHtml('hero-dead-subtitle.example', [
        'hero_headline' => 'Bouquets & Botanicals',
    ]);

    expect($main)->not->toContain('Cut this morning')
        ->and($main)->not->toContain(shopHeroInnerSubtitleClass());
});

function shopHeroCopySurfaceStyle(): string
{
    return 'background-color: color-mix(in srgb, var(--brand-primary) 78%, transparent); border-radius: var(--radius-card); padding: 1.5rem 2rem; max-width: 44rem;';
}

test('storefront boxed hero_text_style wraps the headline in a brand copy panel', function () {
    $main = shopHeroIndexHtml('hero-text-boxed.example', [
        'hero_text_style' => 'boxed',
        'hero_headline' => 'Cakes & Patisserie',
        'hero_accent_word' => 'Patisserie',
    ]);

    expect($main)->toContain(shopHeroCopySurfaceStyle())
        ->and($main)->toContain('Cakes &amp; ')
        ->and($main)->toContain('<span class="accent-word" style="color: var(--color-accent);">Patisserie</span>');
});

test('storefront plain or omitted hero_text_style renders no copy panel', function (?string $style) {
    $hero = ['hero_headline' => 'Cakes & Patisserie'];
    if ($style !== null) {
        $hero['hero_text_style'] = $style;
    }
    $main = shopHeroIndexHtml('hero-text-plain-'.md5((string) $style).'.example', $hero);

    expect($main)->not->toContain('color-mix(')
        ->and($main)->not->toContain(shopHeroCopySurfaceStyle())
        ->and($main)->toContain('<div class="max-w-3xl text-left">')
        ->and($main)->toContain('Cakes &amp; Patisserie');
})->with([null, 'plain', '']);

test('boxed storefront copy panel respects text_zone horizontal placement', function (string $zone, string $horizontalClass) {
    $main = shopHeroIndexHtml('hero-text-boxed-'.$zone.'.example', [
        'hero_text_style' => 'boxed',
        'text_zone' => $zone,
        'hero_headline' => 'Cakes & Patisserie',
    ]);

    expect($main)->toContain('class="max-w-3xl '.$horizontalClass.'"')
        ->and($main)->toContain(shopHeroCopySurfaceStyle())
        ->and($main)->toContain('Cakes &amp; Patisserie');
})->with([
    ['middle-left', 'text-left'],
    ['middle-center', 'text-center mx-auto'],
    ['middle-right', 'text-right ml-auto'],
]);

test('boxed hero_text_style does not wrap the no-hero fallback h1', function () {
    $main = shopHeroIndexHtml('hero-text-boxed-off.example', [
        'hero_enabled' => false,
        'hero_text_style' => 'boxed',
        'hero_headline' => 'Cakes & Patisserie',
    ]);

    expect($main)->toContain('<h1 class="text-3xl md:text-4xl font-extrabold mt-4">Cakes &amp; Patisserie</h1>')
        ->and($main)->not->toContain('color-mix(')
        ->and($main)->not->toContain(shopHeroCopySurfaceStyle());
});

test('category hero_mode none hides the band even when a per-category image is set', function () {
    $main = shopHeroCategoryHtml('hero-mode-none.example', [
        'hero_mode' => 'none',
        'hero_image_url' => '/cat-hero.jpg',
        'hero_enabled' => true,
    ]);

    expect($main)->toMatch('/<h1\b[^>]*>\s*Preserves\s*<\/h1>/')
        ->and($main)->not->toContain('/cat-hero.jpg')
        ->and($main)->not->toContain('linear-gradient(')
        ->and(strpos($main, '<nav aria-label="Breadcrumb"'))->toBeLessThan(strpos($main, '<h1'));
});

test('category hero_mode custom renders the per-category image', function () {
    $main = shopHeroCategoryHtml('hero-mode-custom.example', [
        'hero_mode' => 'custom',
        'hero_image_url' => '/cat-hero.jpg',
        'hero_height' => 'small',
    ]);

    expect($main)->toContain('/cat-hero.jpg')
        ->and($main)->toContain('linear-gradient(')
        ->and($main)->toContain('py-14 md:py-16')
        ->and($main)->not->toContain('<h1 class="text-3xl md:text-4xl font-extrabold mt-4">Preserves</h1>');
});

test('category hero_mode shared renders the shared_category_hero image and layout', function () {
    $main = shopHeroCategoryHtml('hero-mode-shared.example', [
        'hero_mode' => 'shared',
        'hero_image_url' => '/cat-hero.jpg',
        'hero_height' => 'small',
        'hero_width' => 'boxed',
        'text_zone' => 'middle-left',
        'bg_position_y' => 20,
    ], [
        'shared_category_hero' => [
            'image_url' => '/shared-cat-hero.jpg',
            'hero_alt' => 'Shared pantry',
            'height' => 'large',
            'width' => 'full',
            'text_zone' => 'middle-right',
            'bg_position_y' => 70,
            'text_style' => 'plain',
        ],
    ]);

    expect($main)->toContain('/shared-cat-hero.jpg')
        ->and($main)->not->toContain('/cat-hero.jpg')
        ->and($main)->toContain('py-28 md:py-40 lg:py-48')
        ->and($main)->toContain('margin-left: calc(50% - 50vw); margin-right: calc(50% - 50vw);')
        ->and($main)->toContain(shopHeroRightScrimGradient())
        ->and($main)->toContain('center 70%')
        ->and($main)->toContain('Preserves');
});

test('legacy snapshots without hero_mode still render a custom hero when enabled with an image', function () {
    $main = shopHeroCategoryHtml('hero-mode-legacy-on.example', [
        'hero_enabled' => true,
        'hero_image_url' => '/cat-hero.jpg',
    ]);

    expect($main)->toContain('/cat-hero.jpg')
        ->and($main)->toContain('linear-gradient(')
        ->and($main)->not->toContain('<h1 class="text-3xl md:text-4xl font-extrabold mt-4">Preserves</h1>');
});

test('legacy snapshots without hero_mode still skip the hero when disabled', function () {
    $main = shopHeroCategoryHtml('hero-mode-legacy-off.example', [
        'hero_enabled' => false,
        'hero_image_url' => '/cat-hero.jpg',
    ]);

    expect($main)->toMatch('/<h1\b[^>]*>\s*Preserves\s*<\/h1>/')
        ->and($main)->not->toContain('/cat-hero.jpg')
        ->and($main)->not->toContain('linear-gradient(');
});

test('shared mode with no shared hero configured falls back to the no-hero branch', function (?array $shared) {
    $extras = [];
    if ($shared !== null) {
        $extras['shared_category_hero'] = $shared;
    }

    $main = shopHeroCategoryHtml(
        'hero-mode-shared-empty-'.md5(json_encode($shared)).'.example',
        ['hero_mode' => 'shared', 'hero_image_url' => '/cat-hero.jpg'],
        $extras,
    );

    expect($main)->toMatch('/<h1\b[^>]*>\s*Preserves\s*<\/h1>/')
        ->and($main)->not->toContain('/cat-hero.jpg')
        ->and($main)->not->toContain('/shared-cat-hero.jpg')
        ->and($main)->not->toContain('linear-gradient(')
        ->and(strpos($main, '<nav aria-label="Breadcrumb"'))->toBeLessThan(strpos($main, '<h1'));
})->with([
    null,
    [[]],
    [['image_url' => null]],
    [['image_url' => '']],
]);

test('category boxed hero_text_style wraps the copy column and keeps the accent word', function () {
    $main = shopHeroCategoryHtml('hero-cat-text-boxed.example', [
        'hero_mode' => 'custom',
        'hero_text_style' => 'boxed',
        'hero_accent_word' => 'Preserves',
    ]);

    expect($main)->toContain(shopHeroCopySurfaceStyle())
        ->and($main)->toContain('<span class="accent-word" style="color: var(--color-accent);">Preserves</span>');
});

test('shared mode inherits boxed text_style from the shared block when the category style is unset', function () {
    $main = shopHeroCategoryHtml('hero-cat-shared-boxed.example', [
        'hero_mode' => 'shared',
    ], [
        'shared_category_hero' => [
            'image_url' => '/shared-cat-hero.jpg',
            'text_style' => 'boxed',
        ],
    ]);

    expect($main)->toContain(shopHeroCopySurfaceStyle())
        ->and($main)->toContain('/shared-cat-hero.jpg');
});

test('category hero_text_style boxed wins over a shared plain style', function () {
    $main = shopHeroCategoryHtml('hero-cat-style-override.example', [
        'hero_mode' => 'shared',
        'hero_text_style' => 'boxed',
    ], [
        'shared_category_hero' => [
            'image_url' => '/shared-cat-hero.jpg',
            'text_style' => 'plain',
        ],
    ]);

    expect($main)->toContain(shopHeroCopySurfaceStyle());
});

test('category accent word wraps in shared mode', function () {
    $main = shopHeroCategoryHtml('hero-cat-shared-accent.example', [
        'hero_mode' => 'shared',
        'hero_accent_word' => 'Preserves',
    ], [
        'shared_category_hero' => [
            'image_url' => '/shared-cat-hero.jpg',
        ],
    ]);

    expect($main)->toContain('<span class="accent-word" style="color: var(--color-accent);">Preserves</span>')
        ->and($main)->toContain('/shared-cat-hero.jpg');
});

test('shared mode with enabled false falls back to the no-hero branch', function () {
    $main = shopHeroCategoryHtml('hero-mode-shared-disabled.example', [
        'hero_mode' => 'shared',
    ], [
        'shared_category_hero' => [
            'image_url' => '/shared-cat-hero.jpg',
            'enabled' => false,
        ],
    ]);

    expect($main)->toMatch('/<h1\b[^>]*>\s*Preserves\s*<\/h1>/')
        ->and($main)->not->toContain('/shared-cat-hero.jpg')
        ->and($main)->not->toContain('linear-gradient(')
        ->and(strpos($main, '<nav aria-label="Breadcrumb"'))->toBeLessThan(strpos($main, '<h1'));
});

test('shared mode with the enabled key absent still renders the hero', function () {
    $main = shopHeroCategoryHtml('hero-mode-shared-enabled-absent.example', [
        'hero_mode' => 'shared',
    ], [
        'shared_category_hero' => [
            'image_url' => '/shared-cat-hero.jpg',
        ],
    ]);

    expect($main)->toContain('/shared-cat-hero.jpg')
        ->and($main)->toContain('linear-gradient(');
});

test('shared mode places the breadcrumb under the hero', function () {
    $main = shopHeroCategoryHtml('hero-cat-shared-crumb.example', [
        'hero_mode' => 'shared',
    ], [
        'shared_category_hero' => [
            'image_url' => '/shared-cat-hero.jpg',
        ],
    ]);

    expect($main)->toContain('<nav aria-label="Breadcrumb"')
        ->and($main)->toContain('/shared-cat-hero.jpg')
        ->and(strpos($main, '<section'))->toBeLessThan(strpos($main, '<nav aria-label="Breadcrumb"'));
});


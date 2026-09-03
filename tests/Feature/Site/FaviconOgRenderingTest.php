<?php

use App\Models\Site;
use Illuminate\Support\Facades\Storage;

it('emits favicon link + OG meta tags when URLs are populated', function () {
    $site = new Site([
        'business_name' => 'Acme Plumbing',
        'brand_favicon_url' => 'https://cdn.example/sites/1/brand/favicon-abcd1234.png',
        'brand_og_url' => 'https://cdn.example/sites/1/brand/og-abcd1234.png',
    ]);

    expect($site->faviconUrl())->toBe('https://cdn.example/sites/1/brand/favicon-abcd1234.png')
        ->and($site->ogImageUrl())->toBe('https://cdn.example/sites/1/brand/og-abcd1234.png');
});

it('returns null when favicon/og columns are empty', function () {
    $site = new Site([
        'brand_favicon_url' => '',
        'brand_og_url' => null,
    ]);

    expect($site->faviconUrl())->toBeNull()
        ->and($site->ogImageUrl())->toBeNull();
});

it('renders favicon + OG image tags in the site layout when URLs exist', function () {
    $site = Site::factory()->create([
        'business_name' => 'Render Test Ltd',
        'brand_favicon_url' => 'https://cdn.example/fav.png',
        'brand_og_url' => 'https://cdn.example/og.png',
    ]);

    $html = renderSiteHead($site);

    // og:title is derived from seoMeta (or nav_label + business_name fallback)
    // and is always emitted regardless of image URL. The page here has
    // nav_label="Home" so the computed title includes the pipe separator.
    expect($html)->toContain('<link rel="icon" href="https://cdn.example/fav.png">')
        ->and($html)->toContain('<meta property="og:image" content="https://cdn.example/og.png">')
        ->and($html)->toContain('<meta property="og:image:width" content="1200">')
        ->and($html)->toContain('<meta property="og:image:height" content="630">')
        ->and($html)->toContain('<meta property="og:title" content="Home | Render Test Ltd">')
        ->and($html)->toContain('<meta property="og:type" content="website">')
        ->and($html)->toContain('<meta property="og:site_name" content="Render Test Ltd">')
        ->and($html)->toContain('<meta name="twitter:card" content="summary_large_image">')
        ->and($html)->toContain('<meta name="twitter:image" content="https://cdn.example/og.png">');
});

it('uses the site card as og:image and falls back to the page hero (Mark, 3 Sept: logo card on standard pages)', function () {
    $site = Site::factory()->create([
        'business_name' => 'Hero Og Ltd',
        'brand_og_url' => 'https://cdn.example/card.png',
    ]);

    $heroHtml = renderSiteHead($site, [
        'heroImages' => [
            'home' => ['url' => 'https://cdn.example/heroes/home.jpg', 'watermark_url' => null, 'placement' => 'center', 'width' => 1800, 'height' => 900],
        ],
    ]);

    expect($heroHtml)->toContain('<meta property="og:image" content="https://cdn.example/card.png">')
        ->and($heroHtml)->toContain('<meta name="twitter:image" content="https://cdn.example/card.png">')
        ->and($heroHtml)->toContain('<meta property="og:image:width" content="1200">')
        ->and($heroHtml)->toContain('<meta property="og:image:height" content="630">')
        ->and($heroHtml)->not->toContain('heroes/home.jpg')
        ->and($heroHtml)->toMatch('/<meta property="og:url" content="https?:\/\/[^"]+">/');

    $fallbackHtml = renderSiteHead($site, ['heroImages' => []]);

    expect($fallbackHtml)->toContain('<meta property="og:image" content="https://cdn.example/card.png">')
        ->and($fallbackHtml)->toContain('<meta property="og:image:width" content="1200">')
        ->and($fallbackHtml)->toContain('<meta property="og:image:height" content="630">');
});

it('keeps og:title and og:description in sync with page seo meta', function () {
    $site = Site::factory()->create([
        'business_name' => 'Seo Sync Ltd',
        'brand_og_url' => 'https://cdn.example/card.png',
    ]);

    $html = renderSiteHead($site, [
        'seoMeta' => [
            'meta_title' => 'Bespoke Kitchens in Leeds',
            'meta_description' => 'Hand-built kitchens for Leeds homes.',
        ],
    ]);

    expect($html)->toContain('<meta property="og:title" content="Bespoke Kitchens in Leeds">')
        ->and($html)->toContain('<meta property="og:description" content="Hand-built kitchens for Leeds homes.">')
        ->and($html)->toContain('<meta name="twitter:title" content="Bespoke Kitchens in Leeds">')
        ->and($html)->toContain('<meta name="twitter:description" content="Hand-built kitchens for Leeds homes.">')
        ->and($html)->toMatch('/<meta property="og:url" content="https?:\/\/[^"]+">/');
});

it('emits the square OG card after the landscape card so platforms can crop', function () {
    $site = Site::factory()->create([
        'business_name' => 'Square Card Ltd',
        'brand_og_url' => 'https://cdn.example/og.png',
        'brand_og_square_url' => 'https://cdn.example/og-square.png',
    ]);

    $html = renderSiteHead($site);

    expect($html)->toContain('<meta property="og:image" content="https://cdn.example/og.png">')
        ->and($html)->toContain('<meta property="og:image" content="https://cdn.example/og-square.png">')
        ->and($html)->toContain('<meta property="og:image:width" content="1200">')
        ->and($html)->toContain('<meta property="og:image:height" content="630">')
        ->and($html)->toContain('<meta property="og:image:height" content="1200">')
        ->and(strpos($html, 'content="https://cdn.example/og.png">'))->toBeLessThan(strpos($html, 'content="https://cdn.example/og-square.png">'))
        ->and(substr_count($html, '<meta name="twitter:image"'))->toBe(1)
        ->and($html)->toContain('<meta name="twitter:image" content="https://cdn.example/og.png">');
});

it('emits the square OG card even when a page hero exists, because the card is the primary image', function () {
    $site = Site::factory()->create([
        'business_name' => 'Hero Square Ltd',
        'brand_og_url' => 'https://cdn.example/card.png',
        'brand_og_square_url' => 'https://cdn.example/og-square.png',
    ]);

    $html = renderSiteHead($site, [
        'heroImages' => [
            'home' => ['url' => 'https://cdn.example/heroes/home.jpg', 'watermark_url' => null, 'placement' => 'center', 'width' => 1800, 'height' => 900],
        ],
    ]);

    expect($html)->toContain('<meta property="og:image" content="https://cdn.example/card.png">')
        ->and($html)->toContain('og-square.png');
});

it('emits stored custom OG dimensions instead of the generated card size', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('sites/1/brand/og-custom.png', 'custom', 'public');

    $site = Site::factory()->create([
        'business_name' => 'Custom Dims Ltd',
        'brand_og_url' => 'https://cdn.example/generated.png',
        'brand_og_custom_path' => 'sites/1/brand/og-custom.png',
        'brand_og_custom_meta' => ['width' => 900, 'height' => 900],
    ]);

    $html = renderSiteHead($site);

    expect($html)->toContain('<meta property="og:image:width" content="900">')
        ->and($html)->toContain('<meta property="og:image:height" content="900">')
        ->and($html)->not->toContain('<meta property="og:image:width" content="1200">')
        ->and($html)->not->toContain('<meta property="og:image:height" content="630">');
});

it('omits favicon + og:image tags when URLs are absent but keeps og:title for SEO', function () {
    $site = Site::factory()->create([
        'business_name' => 'No Brand Ltd',
        'brand_favicon_url' => null,
        'brand_og_url' => null,
    ]);

    $html = renderSiteHead($site);

    // favicon + og:image depend on URL columns; og:title is always
    // emitted as an SEO baseline, using seoMeta or a nav_label/business_name fallback.
    expect($html)->not->toContain('rel="icon"')
        ->and($html)->not->toContain('og:image')
        ->and($html)->toContain('<meta property="og:title" content="Home | No Brand Ltd">');
});

/**
 * Render just the <head> fragment of site/page.blade.php. We provide the
 * minimum scaffolding the template needs (a fake $page + $composition +
 * $renderTokens + $theme) so the favicon / OG conditionals can be asserted
 * in isolation.
 */
/**
 * @param  array<string, mixed>  $extra
 */
function renderSiteHead(Site $site, array $extra = []): string
{
    $page = new \App\Models\GeneratedPage([
        'nav_label' => 'Home',
        'page_type' => 'home',
    ]);

    $html = view('site.page', array_merge([
        'site' => $site,
        'page' => $page,
        'composition' => [],
        'renderTokens' => [],
        'theme' => [],
        'profile' => [],
        'sections' => [],
        'resolvedSections' => [],
        'nav' => [],
        'navItems' => [],
        'homeHref' => '#',
        'editor' => fn () => '',
        'cssClasses' => [],
        'sectionEditable' => false,
        'heroImages' => [],
    ], $extra))->render();

    // Slice out just the head to keep assertions tight.
    $start = strpos($html, '<head>');
    $end = strpos($html, '</head>');
    if ($start === false || $end === false) {
        return $html;
    }

    return substr($html, $start, $end - $start + strlen('</head>'));
}

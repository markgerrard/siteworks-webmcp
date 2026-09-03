<?php

use App\Models\BusinessProfile;
use App\Models\Site;
use App\Services\Site\BrandImageService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
});

it('generates and uploads a favicon PNG with the design-brief palette', function () {
    $site = Site::factory()->create([
        'business_name' => 'Acme Plumbing',
        'design_brief' => [
            'display_font' => 'inter',
            'body_font' => 'manrope',
            'palette' => [
                'primary' => '#0f172a',
                'accent' => '#ef4444',
                'surface' => '#ffffff',
                'surface_alt' => '#e2e8f0',
                'text' => '#0f172a',
                'text_muted' => '#475569',
            ],
        ],
    ]);

    $url = app(BrandImageService::class)->generateFavicon($site);

    expect($url)->toBeString()
        ->and($url)->toContain('sites/'.$site->id.'/brand/favicon-')
        ->and($url)->toEndWith('.png');

    // File uploaded to the fake S3 disk.
    $uploaded = collect(Storage::disk('s3')->allFiles('sites/'.$site->id.'/brand'))
        ->filter(fn (string $path) => str_starts_with(basename($path), 'favicon-'));

    expect($uploaded)->toHaveCount(1);

    // Confirm the bytes are a real PNG (magic header).
    $bytes = Storage::disk('s3')->get($uploaded->first());
    expect(substr($bytes, 0, 8))->toBe("\x89PNG\r\n\x1a\n");
});

it('generates and uploads a 1200x630 OG PNG with palette + strapline', function () {
    $site = Site::factory()->create([
        'business_name' => 'Acme Plumbing',
        'business_type' => 'plumbing',
        'design_brief' => [
            'display_font' => 'fraunces',
            'body_font' => 'inter',
            'palette' => [
                'primary' => '#1e40af',
                'accent' => '#f59e0b',
                'surface' => '#ffffff',
                'surface_alt' => '#111827',
                'text' => '#f9fafb',
                'text_muted' => '#cbd5e1',
            ],
        ],
    ]);
    BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['strapline' => 'Local plumbers you can trust.'],
    ]);

    $url = app(BrandImageService::class)->generateOgImage($site);

    expect($url)->toBeString()
        ->and($url)->toContain('sites/'.$site->id.'/brand/og-');

    $files = Storage::disk('s3')->allFiles('sites/'.$site->id.'/brand');
    $og = collect($files)->first(fn (string $path) => str_starts_with(basename($path), 'og-'));
    expect($og)->not->toBeNull();

    $bytes = Storage::disk('s3')->get($og);
    expect(substr($bytes, 0, 8))->toBe("\x89PNG\r\n\x1a\n");
});

it('regenerateBoth updates both site columns with the new URLs', function () {
    $site = Site::factory()->create([
        'business_name' => 'Acme Plumbing',
        'design_brief' => [
            'display_font' => 'inter',
            'body_font' => 'inter',
            'palette' => [
                'primary' => '#1f2937',
                'accent' => '#f59e0b',
                'surface' => '#ffffff',
                'surface_alt' => '#111827',
                'text' => '#f9fafb',
                'text_muted' => '#d1d5db',
            ],
        ],
    ]);

    app(BrandImageService::class)->regenerateBoth($site);
    $site->refresh();

    expect($site->brand_favicon_url)->toBeString()->toContain('favicon-')
        ->and($site->brand_og_url)->toBeString()->toContain('og-');
});

it('falls back to the default palette when design_brief is missing', function () {
    $site = Site::factory()->create([
        'business_name' => 'NoBrief Ltd',
        'design_brief' => null,
    ]);

    $url = app(BrandImageService::class)->generateFavicon($site);

    // Still succeeds — fallback palette lets the renderer continue.
    expect($url)->toBeString();
});

it('uses business_type initial when business_name is empty', function () {
    // Direct initials resolver access via reflection — keeps the unit
    // test focused on the initials rule without having to inspect the
    // PNG's text rendering.
    $service = app(BrandImageService::class);
    $method = new ReflectionMethod($service, 'resolveInitials');
    $method->setAccessible(true);

    $site = new Site;
    $site->business_name = '';
    $site->business_type = 'plumbing';

    expect($method->invoke($service, $site))->toBe('P');
});

it('returns two initials for multi-word business names', function () {
    $service = app(BrandImageService::class);
    $method = new ReflectionMethod($service, 'resolveInitials');
    $method->setAccessible(true);

    $site = new Site;
    $site->business_name = 'Acme Plumbing Limited';

    expect($method->invoke($service, $site))->toBe('AP');
});

it('returns two letters from a single-word business name', function () {
    $service = app(BrandImageService::class);
    $method = new ReflectionMethod($service, 'resolveInitials');
    $method->setAccessible(true);

    $site = new Site;
    $site->business_name = 'Acme';

    expect($method->invoke($service, $site))->toBe('AC');
});

/*
 * Effective-palette resolution: the favicon/OG must reflect what the live
 * site actually looks like — brief palette WITH the published composition's
 * theme overrides applied (via ThemeResolver). Reading only
 * design_brief.palette left favicons stuck on the original brief colours
 * after an agent recoloured the site in the design panel
 * (brief primary blue vs a published red override).
 */

function siteWithBriefAndPublishedOverride(?array $themeOverrides): Site
{
    // Fully valid brief — DesignBrief::isValid() requires mood/scale/density/
    // corner enums; an invalid brief is (correctly) ignored by ThemeResolver.
    $site = Site::factory()->create([
        'business_name' => 'Harrisons Electrical',
        'design_brief' => [
            'mood' => 'bold-modern',
            'display_font' => 'space-grotesk',
            'body_font' => 'manrope',
            'heading_scale' => 'balanced',
            'spacing_density' => 'balanced',
            'corner_style' => 'soft',
            'palette' => [
                'primary' => '#005bb5',
                'accent' => '#d97706',
                'tertiary' => '#059669',
                'border' => '#e2e8f0',
                'surface' => '#fafafa',
                'surface_alt' => '#f0f4f8',
                'text' => '#1a202c',
                'text_muted' => '#4a5568',
            ],
        ],
    ]);

    if ($themeOverrides !== null) {
        $version = \App\Models\Site\SiteVersion::create([
            'site_id' => $site->id,
            'version' => 1,
            'composition' => [
                'nav' => ['items' => []],
                'footer' => ['columns' => [], 'show_credit' => true],
                'theme' => $themeOverrides,
                'homepage_page_id' => null,
            ],
            'page_revisions' => [],
            'published_at' => now(),
        ]);
        \App\Models\Site\SiteVersionCurrent::create([
            'site_id' => $site->id,
            'version_id' => $version->id,
            'updated_at' => now(),
        ]);
    }

    return $site;
}

it('effectivePalette applies published composition overrides over the brief palette', function () {
    $site = siteWithBriefAndPublishedOverride([
        'key' => 'trades-bold',
        'primary_override' => '#e00000',
        'accent_override' => '#e60000',
    ]);

    $palette = app(BrandImageService::class)->effectivePalette($site);

    expect($palette['primary'])->toBe('#e00000')
        ->and($palette['accent'])->toBe('#e60000');
});

it('effectivePalette falls back to the brief palette without a published version', function () {
    $site = siteWithBriefAndPublishedOverride(null);

    $palette = app(BrandImageService::class)->effectivePalette($site);

    expect($palette['primary'])->toBe('#005bb5');
});

it('regenerating after a published recolour produces a different favicon', function () {
    $site = siteWithBriefAndPublishedOverride(null);
    $svc = app(BrandImageService::class);

    $before = $svc->generateFavicon($site);

    // Agent recolours the site and publishes.
    $version = \App\Models\Site\SiteVersion::create([
        'site_id' => $site->id,
        'version' => 1,
        'composition' => [
            'nav' => ['items' => []],
            'footer' => ['columns' => [], 'show_credit' => true],
            'theme' => ['key' => 'trades-bold', 'primary_override' => '#e00000'],
            'homepage_page_id' => null,
        ],
        'page_revisions' => [],
        'published_at' => now(),
    ]);
    \App\Models\Site\SiteVersionCurrent::create([
        'site_id' => $site->id, 'version_id' => $version->id, 'updated_at' => now(),
    ]);

    $after = $svc->generateFavicon($site->fresh());

    // Content-hash filenames — different colours must produce different bytes.
    expect($after)->not->toBeNull()->and($after)->not->toBe($before);
});

it('regenerateBoth invalidates the public page cache when the urls change', function () {
    $site = siteWithBriefAndPublishedOverride(null);

    $cache = Mockery::mock(\App\Services\Site\PublicPageCache::class);
    $cache->shouldReceive('invalidate')->once()->with(Mockery::on(fn ($s) => $s->id === $site->id));
    app()->instance(\App\Services\Site\PublicPageCache::class, $cache);

    app(BrandImageService::class)->regenerateBoth($site);

    expect($site->fresh()->brand_favicon_url)->not->toBeNull();
});

function darkThemeOgSite(array $overrides = []): Site
{
    return Site::factory()->create(array_merge([
        'business_name' => 'Camino Cafe',
        'business_type' => 'cafe',
        'design_brief' => [
            'display_font' => 'inter',
            'body_font' => 'inter',
            'palette' => [
                'primary' => '#0b0b0c',
                'accent' => '#c4a574',
                'surface' => '#111111',
                'surface_alt' => '#050505',
                'text' => '#1a1a1a',
                'text_muted' => '#3f3f46',
            ],
        ],
    ], $overrides));
}

function tinyLogoPng(): string
{
    $image = new Imagick;
    $image->newImage(200, 80, 'blue');
    $image->setImageFormat('png');

    return $image->getImageBlob();
}

function pngSize(string $bytes): array
{
    $image = new Imagick;
    $image->readImageBlob($bytes);

    return [$image->getImageWidth(), $image->getImageHeight()];
}

function pngClaimingDimensions(int $width, int $height): string
{
    $image = new Imagick;
    $image->newImage(32, 32, 'white');
    $image->setImageFormat('png');
    $bytes = $image->getImageBlob();

    $ihdr = pack('N', $width).pack('N', $height).substr($bytes, 24, 5);
    $crc = pack('N', crc32('IHDR'.$ihdr) & 0xFFFFFFFF);

    return substr($bytes, 0, 16).$ihdr.$crc.substr($bytes, 33);
}

function ogInkRightEdge(string $bytes): int
{
    $image = new Imagick;
    $image->readImageBlob($bytes);
    $image->trimImage(0);
    $page = $image->getImagePage();
    $geometry = $image->getImageGeometry();

    return (int) $page['x'] + (int) $geometry['width'];
}

function ogStoredPng(Site $site, bool $square = false): string
{
    $prefix = $square ? 'og-square-' : 'og-';
    $path = collect(Storage::disk('s3')->allFiles('sites/'.$site->id.'/brand'))
        ->first(fn (string $file) => str_starts_with(basename($file), $prefix)
            && ($square || ! str_starts_with(basename($file), 'og-square-')));

    expect($path)->not->toBeNull();

    return Storage::disk('s3')->get($path);
}

function longOgNameSite(array $overrides = []): Site
{
    $site = darkThemeOgSite(array_merge([
        'business_name' => 'Oliver Engineering (Borders) Limited',
        'business_type' => '',
    ], $overrides));

    $strapline = 'SiteWorks builds and runs websites and digital marketing for construction and trades businesses across the UK today now!!!';
    expect(strlen($strapline))->toBe(122);

    BusinessProfile::factory()->for($site)->create([
        'profile_data' => ['strapline' => $strapline],
    ]);

    return $site;
}

it('falls back to a light card when the theme primary fails 4.5:1 against the text colour', function () {
    $site = darkThemeOgSite();
    $service = app(BrandImageService::class);
    $card = $service->ogCardPalette($service->effectivePalette($site));
    $contrast = app(\App\Services\Site\ThemeResolver::class)->contrastRatio($card['background'], $card['text']);

    expect($card['background'])->toBe('#f8fafc')
        ->and($contrast)->toBeGreaterThanOrEqual(4.5);

    $url = $service->generateOgImage($site);
    expect($url)->toBeString();

    $path = collect(Storage::disk('s3')->allFiles('sites/'.$site->id.'/brand'))
        ->first(fn (string $file) => str_starts_with(basename($file), 'og-') && ! str_starts_with(basename($file), 'og-square-'));
    $image = new Imagick;
    $image->readImageBlob(Storage::disk('s3')->get($path));
    $pixel = $image->getImagePixelColor(24, 24)->getColor();

    expect($pixel['r'])->toBeGreaterThan(230)
        ->and($pixel['g'])->toBeGreaterThan(230)
        ->and($pixel['b'])->toBeGreaterThan(230);
});

it('uses the brand primary as the card surface when it meets 4.5:1 against the text colour', function () {
    $service = app(BrandImageService::class);
    $card = $service->ogCardPalette([
        'primary' => '#1d4ed8',
        'accent' => '#f59e0b',
        'surface' => '#ffffff',
        'surface_alt' => '#eff6ff',
        'text' => '#ffffff',
        'text_muted' => '#dbeafe',
    ]);
    $contrast = app(\App\Services\Site\ThemeResolver::class)->contrastRatio($card['background'], $card['text']);

    expect($card['background'])->toBe('#1d4ed8')
        ->and($contrast)->toBeGreaterThanOrEqual(4.5);
});

it('embeds the real logo when brand_image_path is present and falls back to a monogram when absent', function () {
    $service = app(BrandImageService::class);

    $withoutLogo = darkThemeOgSite();
    $withoutSvg = $service->composeOgSvg($withoutLogo);
    expect($withoutSvg)->toContain('>CC<')
        ->and($withoutSvg)->not->toContain('<image');

    $withLogo = darkThemeOgSite();
    $path = 'sites/'.$withLogo->id.'/brand/logo.png';
    Storage::disk('s3')->put($path, tinyLogoPng(), 'public');
    $withLogo->update(['brand_image_path' => $path]);

    $withSvg = $service->composeOgSvg($withLogo->fresh());
    expect($withSvg)->not->toContain('>CC<');

    $url = $service->generateOgImage($withLogo->fresh());
    expect($url)->toBeString();

    $pngPath = collect(Storage::disk('s3')->allFiles('sites/'.$withLogo->id.'/brand'))
        ->first(fn (string $file) => str_starts_with(basename($file), 'og-') && ! str_starts_with(basename($file), 'og-square-'));
    $bytes = Storage::disk('s3')->get($pngPath);
    expect(substr($bytes, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(strlen($bytes))->toBeGreaterThan(strlen(tinyLogoPng()));
});

it('skips unsafe or undecodable logos while still producing the share card', function () {
    $animated = new Imagick;
    foreach (['red', 'blue'] as $colour) {
        $frame = new Imagick;
        $frame->newImage(20, 20, $colour);
        $frame->setImageFormat('gif');
        $animated->addImage($frame);
    }
    $animated->setImageFormat('gif');

    $unsafeLogos = [
        'oversized.png' => tinyLogoPng().str_repeat('x', (4 * 1024 * 1024) + 1),
        'hostile.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><image href="https://evil.example/logo.png"/></svg>',
        'animated.gif' => $animated->getImagesBlob(),
        'corrupt.png' => 'not-an-image',
    ];

    foreach ($unsafeLogos as $filename => $bytes) {
        $site = darkThemeOgSite();
        $path = 'sites/'.$site->id.'/brand/'.$filename;
        Storage::disk('s3')->put($path, $bytes, 'public');
        $site->update(['brand_image_path' => $path]);

        $service = app(BrandImageService::class);

        expect($service->composeOgSvg($site->fresh()))->toContain('>CC<')
            ->and($service->generateOgImage($site->fresh()))->toContain('/brand/og-');
    }
});

it('embeds a selected logo concept in preference to brand_image_path', function () {
    $site = darkThemeOgSite();
    $brandPath = 'sites/'.$site->id.'/brand/bg.png';
    $logoPath = 'sites/'.$site->id.'/logos/mark.png';
    Storage::disk('s3')->put($brandPath, tinyLogoPng(), 'public');
    $logo = new Imagick;
    $logo->newImage(160, 80, 'red');
    $logo->setImageFormat('png');
    Storage::disk('s3')->put($logoPath, $logo->getImageBlob(), 'public');
    $site->update(['brand_image_path' => $brandPath]);
    \App\Models\LogoConcept::factory()->for($site)->selected()->create(['path' => $logoPath]);

    $svg = app(BrandImageService::class)->composeOgSvg($site->fresh());

    expect($svg)->not->toContain('>CC<');
});

it('uploads a 1200x1200 square OG variant next to the landscape card', function () {
    $site = darkThemeOgSite();
    $service = app(BrandImageService::class);

    $landscape = $service->generateOgImage($site);
    $square = $service->generateOgSquareImage($site);

    expect($landscape)->toContain('/brand/og-')
        ->and($square)->toContain('/brand/og-square-');

    $files = collect(Storage::disk('s3')->allFiles('sites/'.$site->id.'/brand'));
    $landscapePath = $files->first(fn (string $path) => str_starts_with(basename($path), 'og-') && ! str_starts_with(basename($path), 'og-square-'));
    $squarePath = $files->first(fn (string $path) => str_starts_with(basename($path), 'og-square-'));

    expect(pngSize(Storage::disk('s3')->get($landscapePath)))->toBe([1200, 630])
        ->and(pngSize(Storage::disk('s3')->get($squarePath)))->toBe([1200, 1200]);
});

it('regenerateBoth persists the square OG url and busts the page cache', function () {
    $site = darkThemeOgSite();

    app(BrandImageService::class)->regenerateBoth($site);
    $site->refresh();

    expect($site->brand_og_url)->toContain('/brand/og-')
        ->and($site->brand_og_square_url)->toContain('/brand/og-square-');
});

it('does not update generated image urls when object storage rejects the writes', function () {
    $fake = Storage::fake('s3');
    $disk = Mockery::mock($fake)->makePartial();
    $disk->shouldReceive('put')->times(3)->andReturn(false);
    Storage::set('s3', $disk);

    $site = darkThemeOgSite([
        'brand_favicon_url' => 'https://cdn.example/old-favicon.png',
        'brand_og_url' => 'https://cdn.example/old-og.png',
        'brand_og_square_url' => 'https://cdn.example/old-square.png',
    ]);

    app(BrandImageService::class)->regenerateBoth($site);

    expect($site->fresh()->brand_favicon_url)->toBe('https://cdn.example/old-favicon.png')
        ->and($site->fresh()->brand_og_url)->toBe('https://cdn.example/old-og.png')
        ->and($site->fresh()->brand_og_square_url)->toBe('https://cdn.example/old-square.png');
});

it('ogImageUrl prefers a custom upload over the generated card', function () {
    Storage::fake('s3');
    $site = new Site([
        'brand_og_url' => 'https://cdn.example/generated.png',
        'brand_og_custom_path' => 'sites/9/brand/og-custom.png',
    ]);
    Storage::disk('s3')->put('sites/9/brand/og-custom.png', 'custom', 'public');

    expect($site->ogImageUrl())->toBe(Storage::disk('s3')->url('sites/9/brand/og-custom.png'));
});

it('ogImageCardDimensions returns stored custom upload size instead of 1200x630', function () {
    $custom = new Site([
        'brand_og_url' => 'https://cdn.example/generated.png',
        'brand_og_custom_path' => 'sites/9/brand/og-custom.png',
        'brand_og_custom_meta' => ['width' => 900, 'height' => 900],
    ]);
    $generated = new Site([
        'brand_og_url' => 'https://cdn.example/generated.png',
        'brand_og_custom_path' => null,
    ]);

    expect($custom->ogImageCardDimensions())->toBe(['width' => 900, 'height' => 900])
        ->and($generated->ogImageCardDimensions())->toBe(['width' => 1200, 'height' => 630]);
});

it('validates custom share images under the same Imagick resource limits as logos', function () {
    $service = app(BrandImageService::class);
    $image = new Imagick;
    $image->newImage(900, 900, 'white');
    $image->setImageFormat('png');

    $ok = $service->validatedCustomShareImage($image->getImageBlob());

    expect($ok)->toMatchArray([
        'extension' => 'png',
        'width' => 900,
        'height' => 900,
    ]);

    expect(fn () => $service->validatedCustomShareImage(pngClaimingDimensions(10000, 10000)))
        ->toThrow(RuntimeException::class);
});

it('fits Oliver Engineering (Borders) Limited and a 122-char strapline inside the OG safe box', function () {
    $site = longOgNameSite();
    $service = app(BrandImageService::class);

    expect($service->generateOgImage($site))->toBeString()
        ->and($service->generateOgSquareImage($site))->toBeString();

    $landscape = ogStoredPng($site);
    $square = ogStoredPng($site, true);

    expect(pngSize($landscape))->toBe([1200, 630])
        ->and(pngSize($square))->toBe([1200, 1200])
        ->and(ogInkRightEdge($landscape))->toBeLessThanOrEqual(1120)
        ->and(ogInkRightEdge($square))->toBeLessThanOrEqual(1120);
});

it('renders a two-character name and an empty strapline inside the OG safe box', function () {
    $site = darkThemeOgSite([
        'business_name' => 'Oz',
        'business_type' => '',
    ]);
    $service = app(BrandImageService::class);

    $svg = $service->composeOgSvg($site);
    expect($svg)->toContain('Oz')
        ->and($svg)->not->toMatch('/<text[^>]*font-size="32"[^>]*>[^<]+<\/text>/');

    expect($service->generateOgImage($site))->toBeString()
        ->and($service->generateOgSquareImage($site))->toBeString();

    expect(ogInkRightEdge(ogStoredPng($site)))->toBeLessThanOrEqual(1120)
        ->and(ogInkRightEdge(ogStoredPng($site, true)))->toBeLessThanOrEqual(1120);
});

it('regenerates the long-name OG card to the same sha twice', function () {
    $site = longOgNameSite();
    $service = app(BrandImageService::class);

    $firstLandscape = $service->generateOgImage($site);
    $firstSquare = $service->generateOgSquareImage($site);
    $landscapeBytes = ogStoredPng($site);
    $squareBytes = ogStoredPng($site, true);

    $secondLandscape = $service->generateOgImage($site);
    $secondSquare = $service->generateOgSquareImage($site);

    expect($secondLandscape)->toBe($firstLandscape)
        ->and($secondSquare)->toBe($firstSquare)
        ->and(sha1(ogStoredPng($site)))->toBe(sha1($landscapeBytes))
        ->and(sha1(ogStoredPng($site, true)))->toBe(sha1($squareBytes));
});

<?php

use App\Models\Site;
use App\Services\Site\PublicPageCache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
});

function ogCommandSite(array $overrides = []): Site
{
    return Site::factory()->create(array_merge([
        'business_name' => 'Camino Cafe',
        'business_type' => 'cafe',
        'design_brief' => [
            'display_font' => 'inter',
            'body_font' => 'inter',
            'palette' => [
                'primary' => '#111827',
                'accent' => '#f59e0b',
                'surface' => '#ffffff',
                'surface_alt' => '#0f172a',
                'text' => '#111827',
                'text_muted' => '#4b5563',
            ],
        ],
    ], $overrides));
}

it('generates landscape and square OG images and writes S3 paths', function () {
    $site = ogCommandSite();

    $this->artisan('site:og-image', ['site' => (string) $site->id])
        ->assertSuccessful();

    $site->refresh();

    expect($site->brand_og_url)->toBeString()->toContain('sites/'.$site->id.'/brand/og-')
        ->and($site->brand_og_square_url)->toBeString()->toContain('sites/'.$site->id.'/brand/og-square-');

    $files = collect(Storage::disk('s3')->allFiles('sites/'.$site->id.'/brand'));
    $landscape = $files->first(fn (string $path) => str_starts_with(basename($path), 'og-') && ! str_starts_with(basename($path), 'og-square-'));
    $square = $files->first(fn (string $path) => str_starts_with(basename($path), 'og-square-'));

    expect($landscape)->not->toBeNull()
        ->and($square)->not->toBeNull();

    $landscapeBytes = Storage::disk('s3')->get($landscape);
    $squareBytes = Storage::disk('s3')->get($square);

    $landscapeImage = new Imagick;
    $landscapeImage->readImageBlob($landscapeBytes);
    $squareImage = new Imagick;
    $squareImage->readImageBlob($squareBytes);

    expect($landscapeImage->getImageWidth())->toBe(1200)
        ->and($landscapeImage->getImageHeight())->toBe(630)
        ->and($squareImage->getImageWidth())->toBe(1200)
        ->and($squareImage->getImageHeight())->toBe(1200);
});

it('is idempotent without --force and regenerates when --force is passed', function () {
    $site = ogCommandSite();

    $this->artisan('site:og-image', ['site' => (string) $site->id])->assertSuccessful();
    $first = $site->fresh()->brand_og_url;

    $this->artisan('site:og-image', ['site' => (string) $site->id])
        ->expectsOutputToContain('skipped')
        ->assertSuccessful();

    expect($site->fresh()->brand_og_url)->toBe($first);

    $this->artisan('site:og-image', ['site' => (string) $site->id, '--force' => true])
        ->assertSuccessful();

    expect($site->fresh()->brand_og_url)->toBe($first);
});

it('busts the public page cache after regeneration', function () {
    $site = ogCommandSite();

    $cache = Mockery::mock(PublicPageCache::class);
    $cache->shouldReceive('invalidate')->once()->with(Mockery::on(fn ($s) => $s->id === $site->id));
    app()->instance(PublicPageCache::class, $cache);

    $this->artisan('site:og-image', ['site' => (string) $site->id])->assertSuccessful();
});

it('site:og-image --all generates missing cards and --force regenerates existing ones', function () {
    $missing = ogCommandSite(['business_name' => 'Missing Card Ltd']);
    $existing = ogCommandSite([
        'business_name' => 'Has Card Ltd',
        'brand_og_url' => 'https://cdn.example/og.png',
        'brand_og_square_url' => 'https://cdn.example/og-square.png',
    ]);

    $this->artisan('site:og-image', ['--all' => true])->assertSuccessful();

    expect($missing->fresh()->brand_og_url)->toBeString()->toContain('/brand/og-')
        ->and($existing->fresh()->brand_og_url)->toBe('https://cdn.example/og.png');

    $this->artisan('site:og-image', ['--all' => true, '--force' => true])->assertSuccessful();

    expect($existing->fresh()->brand_og_url)->toBeString()->toContain('/brand/og-');
});

it('generates when the square variant is missing even without force', function () {
    $site = ogCommandSite([
        'brand_og_url' => 'https://cdn.example/existing-landscape.png',
        'brand_og_square_url' => null,
    ]);

    $this->artisan('site:og-image', ['site' => (string) $site->id])
        ->expectsOutputToContain('generated')
        ->assertSuccessful();

    expect($site->fresh()->brand_og_square_url)->toBeString()->toContain('/brand/og-square-');
});

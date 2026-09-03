<?php

use App\Models\Site;
use App\Support\MediaStorage;
use App\Support\Textures\TextureImage;
use App\Support\Textures\TextureLayer;
use App\Support\Textures\TextureResolver;
use Illuminate\Support\Facades\Storage;

function imageTextureSite(array $attrs = []): Site
{
    $site = new Site;
    $site->id = $attrs['id'] ?? 11;
    $site->forceFill(array_merge([
        'business_name' => 'Acme',
        'business_type' => 'Clockmaker',
        'texture_key' => null,
        'texture_opacity' => null,
        'texture_image_path' => null,
    ], $attrs));
    $site->setRelation('businessProfile', null);

    return $site;
}

beforeEach(function () {
    Storage::fake('media-disk');
    config()->set('filesystems.media', 'media-disk');
    config()->set('filesystems.default', 'local');
});

test('publicUrl builds from the media disk and ignores filesystems.default', function () {
    $site = imageTextureSite();
    $path = 'sites/11/library/bg.webp';
    MediaStorage::disk()->put($path, 'texture-bytes');

    Storage::fake('local');

    $url = TextureImage::publicUrl($site, $path);

    expect(MediaStorage::diskName())->toBe('media-disk')
        ->and(MediaStorage::disk()->exists($path))->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and($url)->toBe(MediaStorage::disk()->url($path));
});

test('publicUrl rejects paths outside the site media space and missing files', function () {
    $site = imageTextureSite();

    expect(TextureImage::publicUrl($site, 'sites/99/library/bg.webp'))->toBeNull()
        ->and(TextureImage::publicUrl($site, 'sites/11/../99/bg.webp'))->toBeNull()
        ->and(TextureImage::publicUrl($site, 'sites/11/library/missing.webp'))->toBeNull()
        ->and(TextureImage::publicUrl($site, 'https://evil.example/x.webp'))->toBeNull();
});

test('site-level texture_key image uses the media URL when the path exists', function () {
    $path = 'sites/11/library/bg.webp';
    MediaStorage::disk()->put($path, 'texture-bytes');
    $site = imageTextureSite([
        'texture_key' => 'image',
        'texture_image_path' => $path,
    ]);

    $resolved = TextureResolver::resolve($site);

    expect($resolved->key)->toBe('image')
        ->and($resolved->mode)->toBe('image')
        ->and($resolved->cssImage())->toContain(MediaStorage::disk()->url($path));
});

test('texture_key image with a missing path falls back to the resolved SVG texture', function () {
    $site = imageTextureSite([
        'texture_key' => 'image',
        'texture_image_path' => 'sites/11/library/missing.webp',
        'business_type' => 'Landscaping',
    ]);

    $resolved = TextureResolver::resolve($site);

    expect($resolved->key)->toBe('topography')
        ->and($resolved->mode)->toBe('svg');
});

test('section texture image with a broken path falls back to the site SVG', function () {
    $site = imageTextureSite(['texture_key' => 'dots']);
    $layer = TextureLayer::resolve(
        TextureResolver::resolve($site),
        ['texture' => 'image', 'texture_image_path' => 'sites/11/library/missing.webp'],
        defaultOn: true,
        site: $site,
    );

    expect($layer->key)->toBe('dots')
        ->and($layer->mode)->toBe('svg');
});

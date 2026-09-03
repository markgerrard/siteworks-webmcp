<?php

use App\Models\Site;
use App\Support\Textures\TextureLayer;
use App\Support\Textures\TextureLibrary;
use App\Support\Textures\TextureResolver;

function layerSite(array $attrs = []): Site
{
    $site = new Site;
    $site->id = $attrs['id'] ?? 4;
    $site->forceFill(array_merge([
        'business_name' => 'Acme',
        'business_type' => 'Clockmaker',
        'texture_key' => 'plus',
        'texture_opacity' => null,
        'texture_image_path' => null,
    ], $attrs));
    $site->setRelation('businessProfile', null);

    return $site;
}

test('default-on sections use the site texture when no section knob is set', function () {
    $site = layerSite(['texture_key' => 'waves']);
    $resolved = TextureResolver::resolve($site);
    $layer = TextureLayer::resolve($resolved, null, defaultOn: true, site: $site);

    expect($layer)->not->toBeNull()
        ->and($layer->key)->toBe('waves');
});

test('default-off sections emit no layer without a texture knob', function () {
    $site = layerSite(['texture_key' => 'waves']);
    $resolved = TextureResolver::resolve($site);

    expect(TextureLayer::resolve($resolved, null, defaultOn: false, site: $site))->toBeNull()
        ->and(TextureLayer::resolve($resolved, ['texture_opacity' => '0.2'], defaultOn: false, site: $site))->toBeNull()
        ->and(TextureLayer::markup(null))->toBe('');
});

test('a per-section texture knob beats the site-level resolution', function () {
    $site = layerSite(['texture_key' => 'plus']);
    $resolved = TextureResolver::resolve($site);
    $layer = TextureLayer::resolve($resolved, ['texture' => 'grid', 'texture_opacity' => '0.2', 'texture_size' => 'lg'], defaultOn: true, site: $site);

    expect($layer->key)->toBe('grid')
        ->and($layer->opacity)->toBe(0.2)
        ->and($layer->size)->toBe((int) round(32 * 1.5));
});

test('section texture none suppresses the layer even on default-on sections', function () {
    $site = layerSite(['texture_key' => 'dots']);
    $resolved = TextureResolver::resolve($site);

    expect(TextureLayer::resolve($resolved, ['texture' => 'none'], defaultOn: true, site: $site))->toBeNull();
});

test('size steps scale the tile natural size', function () {
    $site = layerSite(['texture_key' => 'plus']);
    $resolved = TextureResolver::resolve($site);

    expect(TextureLayer::resolve($resolved, ['texture_size' => 'sm'], defaultOn: true, site: $site)->size)->toBe((int) round(60 * 0.75))
        ->and(TextureLayer::resolve($resolved, ['texture_size' => 'md'], defaultOn: true, site: $site)->size)->toBe(60)
        ->and(TextureLayer::resolve($resolved, ['texture_size' => 'lg'], defaultOn: true, site: $site)->size)->toBe((int) round(60 * 1.5));
});

test('markup is empty without a layer and matches the current hero-pattern div by default', function () {
    $site = layerSite(['texture_key' => 'plus']);
    $layer = TextureLayer::resolve(TextureResolver::resolve($site), null, defaultOn: true, site: $site);

    expect(TextureLayer::markup($layer))->toBe('<div class="absolute inset-0 hero-pattern"></div>')
        ->and(TextureLayer::markup($layer, softFilter: true))->toBe('<div class="absolute inset-0 hero-pattern" style="filter: invert(1);"></div>')
        ->and(TextureLibrary::PLUS_PATH)->toBeString();
});

test('section overrides add inline CSS vars on the layer', function () {
    $site = layerSite(['texture_key' => 'plus']);
    $layer = TextureLayer::resolve(
        TextureResolver::resolve($site),
        ['texture' => 'dots'],
        defaultOn: true,
        site: $site,
    );
    $html = TextureLayer::markup($layer);

    expect($html)->toContain('style="')
        ->toContain('--site-texture-image:')
        ->toContain('--site-texture-opacity: 0.06')
        ->toContain('--site-texture-size: 24px')
        ->toContain('class="absolute inset-0 hero-pattern"');
});

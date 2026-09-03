<?php

use App\Models\BusinessProfile;
use App\Models\Site;
use App\Support\Textures\TextureLibrary;
use App\Support\Textures\TextureResolver;

function textureSite(array $attrs = [], ?array $profileData = null): Site
{
    $site = new Site;
    $site->id = $attrs['id'] ?? 42;
    $site->forceFill(array_merge([
        'business_name' => 'Acme Trades',
        'business_type' => 'Widget Co',
        'texture_key' => null,
        'texture_opacity' => null,
        'texture_image_path' => null,
    ], $attrs));

    if ($profileData !== null) {
        $profile = new BusinessProfile;
        $profile->forceFill(['profile_data' => $profileData]);
        $site->setRelation('businessProfile', $profile);
    } else {
        $site->setRelation('businessProfile', null);
    }

    return $site;
}

test('an explicit texture_key wins over context and the seeded fallback', function () {
    $site = textureSite([
        'texture_key' => 'sprig',
        'business_type' => 'Landscaper',
    ], ['summary' => 'Garden design and outdoor landscaping']);

    $resolved = TextureResolver::resolve($site);

    expect($resolved->key)->toBe('sprig')
        ->and($resolved->isNone())->toBeFalse();
});

test('explicit none is respected and emits no drawable texture', function () {
    $resolved = TextureResolver::resolve(textureSite(['texture_key' => 'none']));

    expect($resolved->key)->toBe('none')
        ->and($resolved->isNone())->toBeTrue()
        ->and($resolved->cssImage())->toBeNull();
});

test('context mapping table matches first-hit keywords', function (string $haystack, string $expected) {
    $site = textureSite([
        'id' => 7,
        'business_type' => $haystack,
    ], ['summary' => $haystack]);

    expect(TextureResolver::resolve($site)->key)->toBe($expected);
})->with([
    'landscaping' => ['landscaping services', 'topography'],
    'garden' => ['garden maintenance', 'topography'],
    'grounds' => ['grounds care', 'topography'],
    'outdoor' => ['outdoor living', 'topography'],
    'florist' => ['florist studio', 'sprig'],
    'flower' => ['flower shop', 'sprig'],
    'plant' => ['plant nursery', 'sprig'],
    'nursery' => ['nursery grower', 'sprig'],
    'bakery' => ['bakery', 'dots'],
    'cake' => ['cake studio', 'dots'],
    'cafe' => ['neighbourhood cafe', 'dots'],
    'coffee' => ['coffee roaster', 'dots'],
    'food' => ['food hall', 'dots'],
    'deli' => ['deli counter', 'dots'],
    'builder' => ['builder', 'diagonal-hatch'],
    'groundwork' => ['groundworks crew', 'diagonal-hatch'],
    'civil' => ['civil engineering contractor', 'diagonal-hatch'],
    'construction' => ['construction firm', 'diagonal-hatch'],
    'paving' => ['paving specialists', 'diagonal-hatch'],
    'excavat' => ['excavation crew', 'diagonal-hatch'],
    'joiner' => ['joinery workshop', 'herringbone'],
    'carpent' => ['carpentry', 'herringbone'],
    'mason' => ['stonemason', 'herringbone'],
    'furniture' => ['furniture maker', 'herringbone'],
    'craft' => ['craft workshop', 'herringbone'],
    'engineer' => ['engineering practice', 'grid'],
    'survey' => ['land surveyors', 'grid'],
    'electrical' => ['electrical contractors', 'grid'],
    'technical' => ['technical services', 'grid'],
    'wellness' => ['wellness studio', 'waves'],
    'beauty' => ['beauty rooms', 'waves'],
    'salon' => ['hair salon', 'waves'],
    'spa' => ['day spa', 'waves'],
    'yoga' => ['yoga studio', 'waves'],
    'finance' => ['finance boutique', 'noise'],
    'legal' => ['legal chambers', 'noise'],
    'account' => ['accountancy firm', 'noise'],
    'property' => ['property house', 'noise'],
    'acquisition' => ['acquisition vehicle', 'noise'],
    'consult' => ['consulting group', 'noise'],
]);

test('Eden landscaping resolves to topography', function () {
    $site = textureSite([
        'id' => 51,
        'business_name' => 'Eden Landscape Design',
        'business_type' => 'Landscaper',
    ], [
        'archetype' => 'premium_specialist',
        'summary' => 'Garden design and landscaping for Hampshire homes.',
    ]);

    expect(TextureResolver::resolve($site)->key)->toBe('topography');
});

test('Hunt property acquisitions resolves to noise', function () {
    $site = textureSite([
        'id' => 12,
        'business_name' => 'Hunt Property Acquisitions',
        'business_type' => 'Property',
    ], [
        'summary' => 'Off-market property acquisitions across the South East.',
    ]);

    expect(TextureResolver::resolve($site)->key)->toBe('noise');
});

test('Camino bakery resolves to dots', function () {
    $site = textureSite([
        'id' => 8,
        'business_name' => 'Camino Bakery',
        'business_type' => 'Bakery',
    ], [
        'summary' => 'Sourdough bakery and cafe.',
    ]);

    expect(TextureResolver::resolve($site)->key)->toBe('dots');
});

test('unmatched sites pick a deterministic seeded motif from the fallback pool', function () {
    $site = textureSite(['id' => 100, 'business_type' => 'Clockmaker'], [
        'summary' => 'Bespoke timepieces.',
    ]);

    $first = TextureResolver::resolve($site);
    $second = TextureResolver::resolve($site);

    expect($first->key)->toBe($second->key)
        ->and($first->key)->toBeIn(TextureLibrary::SEEDED_KEYS)
        ->and($first->opacity)->toBe(TextureLibrary::get($first->key)['default_opacity']);
});

test('seeded fallback spreads across the pool as site ids vary', function () {
    $keys = [];
    for ($id = 1; $id <= 40; $id++) {
        $keys[] = TextureResolver::resolve(textureSite([
            'id' => $id,
            'business_type' => 'Clockmaker',
        ], ['summary' => 'Bespoke timepieces.']))->key;
    }

    $unique = array_values(array_unique($keys));

    expect($unique)->toHaveCount(count(TextureLibrary::SEEDED_KEYS))
        ->and($unique)->each->toBeIn(TextureLibrary::SEEDED_KEYS);
});

test('resolver is a pure function of stored site data', function () {
    $site = textureSite(['id' => 3, 'texture_key' => 'waves', 'texture_opacity' => 0.07]);

    expect(TextureResolver::resolve($site)->key)->toBe('waves')
        ->and(TextureResolver::resolve($site)->opacity)->toBe(0.07)
        ->and(TextureResolver::resolve($site)->key)->toBe(TextureResolver::resolve($site)->key);
});

test('unknown or retired texture keys fall back to plus', function () {
    $resolved = TextureResolver::resolve(textureSite(['texture_key' => 'swirl']));

    expect($resolved->key)->toBe('plus')
        ->and($resolved->opacity)->toBe(0.05);
});

test('malformed opacity uses the library default', function () {
    expect(TextureResolver::resolve(textureSite(['texture_key' => 'dots', 'texture_opacity' => 'nope']))->opacity)
        ->toBe(0.06)
        ->and(TextureResolver::resolve(textureSite(['texture_key' => 'dots', 'texture_opacity' => 9]))->opacity)
        ->toBe(0.06)
        ->and(TextureResolver::resolve(textureSite(['texture_key' => 'dots', 'texture_opacity' => 0]))->opacity)
        ->toBe(0.06);
});

test('explicit opacity in range is kept', function () {
    expect(TextureResolver::resolve(textureSite(['texture_key' => 'plus', 'texture_opacity' => 0.08]))->opacity)
        ->toBe(0.08);
});

test('auto defaults off resolve a null key to plus', function () {
    config()->set('site-textures.auto', false);

    $matched = textureSite(['business_type' => 'Landscaper'], [
        'summary' => 'Garden landscaping.',
    ]);

    expect(TextureResolver::resolve($matched)->key)->toBe('plus');
});

test('auto defaults off still honour an explicit key', function () {
    config()->set('site-textures.auto', false);

    expect(TextureResolver::resolve(textureSite(['texture_key' => 'waves']))->key)->toBe('waves');
});

test('auto defaults on is the config default', function () {
    expect(config('site-textures.auto'))->toBeTrue();
});

test('texture fields persist on the site row', function () {
    $site = Site::factory()->create([
        'texture_key' => 'dots',
        'texture_opacity' => 0.06,
        'texture_image_path' => 'sites/1/textures/bg.webp',
    ]);

    $site->refresh();

    expect($site->texture_key)->toBe('dots')
        ->and((float) $site->texture_opacity)->toBe(0.06)
        ->and($site->texture_image_path)->toBe('sites/1/textures/bg.webp')
        ->and(TextureResolver::resolve($site)->key)->toBe('dots');
});

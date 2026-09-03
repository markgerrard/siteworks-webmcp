<?php

use App\Models\Site;
use App\Services\Site\Editor\SectionCatalog;
use App\Services\Site\SectionSchema;

it('allows promo_tiles on home and about and rejects it on service', function () {
    $catalog = app(SectionCatalog::class);

    expect($catalog->allowedOn('promo_tiles', 'home'))->toBeTrue()
        ->and($catalog->allowedOn('promo_tiles', 'about'))->toBeTrue()
        ->and($catalog->allowedOn('promo_tiles', 'extensions'))->toBeFalse()
        ->and($catalog->isSingleton('promo_tiles'))->toBeFalse()
        ->and($catalog->maxPerPage('promo_tiles'))->toBe(2);
});

it('supplies the documented default payload for promo_tiles', function () {
    $catalog = app(SectionCatalog::class);
    $schema = app(SectionSchema::class);
    $site = Site::factory()->create();

    $payload = $catalog->defaultPayload('promo_tiles', $site);

    expect($payload)->toMatchArray([
        'type' => 'promo_tiles',
        'variant' => null,
        'eyebrow' => '',
        'title' => '',
        'tiles' => [],
    ]);

    expect($catalog->initialFields('promo_tiles'))->toEqual([
        'eyebrow', 'title',
    ]);

    foreach (['eyebrow', 'title'] as $path) {
        expect($schema->validateField('promo_tiles', $path, $payload[$path]))->toBe([]);
    }

    expect($schema->validateField('promo_tiles', 'tiles.0.heading', 'Same-day delivery'))->toBe([])
        ->and($schema->validateField('promo_tiles', 'tiles.0.cta_url', '/shop'))->toBe([])
        ->and($schema->validateField('promo_tiles', 'tiles.0.cta_url', 'javascript:alert(1)'))->not->toBe([]);
});

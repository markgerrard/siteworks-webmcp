<?php

use App\Models\Site;
use App\Services\Site\Editor\SectionCatalog;
use App\Services\Site\SectionSchema;

it('allows category_rail on home and rejects it on service and about', function () {
    $catalog = app(SectionCatalog::class);

    expect($catalog->allowedOn('category_rail', 'home'))->toBeTrue()
        ->and($catalog->allowedOn('category_rail', 'extensions'))->toBeFalse()
        ->and($catalog->allowedOn('category_rail', 'about'))->toBeFalse()
        ->and($catalog->isSingleton('category_rail'))->toBeTrue()
        ->and($catalog->maxPerPage('category_rail'))->toBe(1);
});

it('supplies the documented default payload for category_rail', function () {
    $catalog = app(SectionCatalog::class);
    $schema = app(SectionSchema::class);
    $site = Site::factory()->create();

    $payload = $catalog->defaultPayload('category_rail', $site);

    expect($payload)->toMatchArray([
        'type' => 'category_rail',
        'variant' => null,
        'title' => 'Shop by occasion',
        'subtitle' => '',
        'slugs' => [],
        'limit' => 8,
    ]);

    expect($catalog->initialFields('category_rail'))->toEqual([
        'title', 'subtitle', 'slugs', 'limit',
    ]);

    foreach (['title', 'subtitle', 'slugs', 'limit'] as $path) {
        expect($schema->validateField('category_rail', $path, $payload[$path]))->toBe([]);
    }
});

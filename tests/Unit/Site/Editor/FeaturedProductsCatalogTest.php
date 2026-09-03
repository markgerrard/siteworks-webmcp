<?php

use App\Models\Site;
use App\Services\Site\Editor\SectionCatalog;
use App\Services\Site\SectionSchema;

it('allows featured_products on home and rejects it on service and about', function () {
    $catalog = app(SectionCatalog::class);

    expect($catalog->allowedOn('featured_products', 'home'))->toBeTrue()
        ->and($catalog->allowedOn('featured_products', 'extensions'))->toBeFalse()
        ->and($catalog->allowedOn('featured_products', 'about'))->toBeFalse()
        ->and($catalog->isSingleton('featured_products'))->toBeTrue()
        ->and($catalog->maxPerPage('featured_products'))->toBe(1);
});

it('supplies the documented default payload for featured_products', function () {
    $catalog = app(SectionCatalog::class);
    $schema = app(SectionSchema::class);
    $site = Site::factory()->create();

    $payload = $catalog->defaultPayload('featured_products', $site);

    expect($payload)->toMatchArray([
        'type' => 'featured_products',
        'variant' => null,
        'title' => 'Featured products',
        'subtitle' => '',
        'source' => 'featured',
        'count' => 4,
        'cta_label' => 'Browse the shop',
        'cta_url' => '/shop',
    ]);

    expect($catalog->initialFields('featured_products'))->toEqual([
        'eyebrow', 'title', 'subtitle', 'source', 'count', 'limit', 'layout', 'cta_label', 'cta_url',
    ]);

    foreach (['title', 'subtitle', 'source', 'count', 'cta_label', 'cta_url'] as $path) {
        expect($schema->validateField('featured_products', $path, $payload[$path]))->toBe([]);
    }
});

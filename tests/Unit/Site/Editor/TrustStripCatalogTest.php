<?php

use App\Models\Site;
use App\Services\Site\Editor\SectionCatalog;
use App\Services\Site\Editor\SectionDescriber;
use App\Services\Site\SectionSchema;

it('offers trust strips on every section page with editable defaults', function () {
    $catalog = app(SectionCatalog::class);
    $site = Site::factory()->create();

    expect($catalog->allowedOn('trust_strip', 'home'))->toBeTrue()
        ->and($catalog->allowedOn('trust_strip', 'about'))->toBeTrue()
        ->and($catalog->defaultPayload('trust_strip', $site))->toMatchArray([
            'type' => 'trust_strip',
            'sources' => 'both',
            'layout' => 'strip',
            'heading' => 'What customers say',
            'reviews_label' => 'reviews',
            'min_reviews' => 3,
        ])
        ->and($catalog->initialFields('trust_strip'))->toBe([
            'sources', 'layout', 'heading', 'reviews_label', 'min_reviews',
            'external.label', 'external.url', 'external.rating', 'external.count',
        ]);
});

it('validates and describes every trust strip knob', function () {
    $schema = app(SectionSchema::class);
    $section = [
        'type' => 'trust_strip',
        'sources' => 'both',
        'layout' => 'carousel',
        'heading' => 'What people say',
        'reviews_label' => 'ratings',
        'min_reviews' => 4,
        'external' => ['label' => 'Independent score', 'url' => 'https://example.test', 'rating' => 4.8, 'count' => 19],
    ];

    foreach (array_keys(config('site_sections.trust_strip.fields')) as $path) {
        expect($schema->validateField('trust_strip', $path, data_get($section, $path)))->toBe([]);
    }

    $fields = collect(app(SectionDescriber::class)->describe($section, 'home', 0, true)['fields'])->keyBy('path');

    expect($fields->keys()->all())->toBe(array_keys(config('site_sections.trust_strip.fields')))
        ->and($fields['sources']['constraints']['options'])->toBe(['site', 'product', 'both'])
        ->and($fields['external.rating']['constraints'])->toMatchArray(['min' => 0, 'max' => 5, 'precision' => 1])
        ->and($schema->validateField('trust_strip', 'heading', str_repeat('x', 61)))->not->toBe([])
        ->and($schema->validateField('trust_strip', 'external.rating', 4.75))->not->toBe([]);
});

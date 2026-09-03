<?php

use App\Services\Site\SectionSchema;

beforeEach(function () {
    $this->schema = new SectionSchema([
        'hero' => ['fields' => [
            'title' => ['type' => 'plain', 'max' => 120],
            'background_image' => ['type' => 'image'],
        ]],
        'services' => ['fields' => [
            'title' => ['type' => 'plain'],
            'items.*.title' => ['type' => 'plain'],
            'items.*.body' => ['type' => 'rich'],
        ]],
    ]);
});

test('isKnownSectionType', function () {
    expect($this->schema->isKnownSectionType('hero'))->toBeTrue();
    expect($this->schema->isKnownSectionType('zzz'))->toBeFalse();
});

test('resolveField returns schema for direct field', function () {
    expect($this->schema->resolveField('hero', 'title'))->toMatchArray(['type' => 'plain', 'max' => 120]);
});

test('resolveField matches wildcard pattern for repeating items', function () {
    expect($this->schema->resolveField('services', 'items.0.title'))->toMatchArray(['type' => 'plain']);
    expect($this->schema->resolveField('services', 'items.5.body'))->toMatchArray(['type' => 'rich']);
});

test('resolveField returns null for unknown field', function () {
    expect($this->schema->resolveField('hero', 'nonexistent'))->toBeNull();
});

test('eachEditableField yields all concrete editable paths from section data', function () {
    $sectionData = [
        'title' => 'Our Services',
        'items' => [
            ['title' => 'Plumbing', 'body' => 'fast'],
            ['title' => 'Heating', 'body' => 'reliable'],
        ],
    ];

    $paths = [];
    foreach ($this->schema->eachEditableField('services', $sectionData) as [$path, $type, $value]) {
        $paths[$path] = ['type' => $type, 'value' => $value];
    }

    expect($paths)->toHaveKeys(['title', 'items.0.title', 'items.0.body', 'items.1.title', 'items.1.body']);
    expect($paths['items.0.title']['type'])->toBe('plain');
    expect($paths['items.0.title']['value'])->toBe('Plumbing');
});

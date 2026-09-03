<?php

use App\Services\Shop\CustomerInputDefinition;
use Illuminate\Validation\ValidationException;
use Tests\Support\LinePersonalisationFixtures;

test('null and empty lists normalise to an empty list', function (mixed $raw) {
    expect(CustomerInputDefinition::normalize($raw))->toBe([]);
})->with([
    'null' => [null],
    'empty array' => [[]],
]);

test('the three named fixtures normalise without error', function (array $fixture) {
    $normalized = CustomerInputDefinition::normalize($fixture);

    expect($normalized)->toHaveCount(count($fixture))
        ->and(array_column($normalized, 'slug'))->toBe(array_column($fixture, 'slug'));
})->with([
    'bakery' => [LinePersonalisationFixtures::bakery()],
    'florist' => [LinePersonalisationFixtures::florist()],
    'generic' => [LinePersonalisationFixtures::generic()],
]);

test('rejects more than three inputs', function () {
    $defs = array_fill(0, 4, [
        'slug' => 'note',
        'label' => 'Note',
        'kind' => 'text',
    ]);
    $defs[1]['slug'] = 'note-2';
    $defs[2]['slug'] = 'note-3';
    $defs[3]['slug'] = 'note-4';

    expect(fn () => CustomerInputDefinition::normalize($defs))
        ->toThrow(ValidationException::class);
});

test('rejects a non-list object', function () {
    expect(fn () => CustomerInputDefinition::normalize(['slug' => 'note']))
        ->toThrow(ValidationException::class);
});

test('rejects an unknown kind', function () {
    expect(fn () => CustomerInputDefinition::normalize([
        ['slug' => 'note', 'label' => 'Note', 'kind' => 'file'],
    ]))->toThrow(ValidationException::class);
});

test('rejects a missing label', function () {
    expect(fn () => CustomerInputDefinition::normalize([
        ['slug' => 'note', 'kind' => 'text'],
    ]))->toThrow(ValidationException::class);
});

test('rejects a missing or invalid slug', function (mixed $slug) {
    expect(fn () => CustomerInputDefinition::normalize([
        ['slug' => $slug, 'label' => 'Note', 'kind' => 'text'],
    ]))->toThrow(ValidationException::class);
})->with([
    'empty' => '',
    'spaces' => 'card message',
    'uppercase' => 'Note',
    'underscore' => 'card_message',
]);

test('rejects duplicate slugs', function () {
    expect(fn () => CustomerInputDefinition::normalize([
        ['slug' => 'note', 'label' => 'Note', 'kind' => 'text'],
        ['slug' => 'note', 'label' => 'Other', 'kind' => 'textarea'],
    ]))->toThrow(ValidationException::class);
});

test('rejects text max_chars above 500', function () {
    expect(fn () => CustomerInputDefinition::normalize([
        ['slug' => 'note', 'label' => 'Note', 'kind' => 'text', 'max_chars' => 501],
    ]))->toThrow(ValidationException::class);
});

test('rejects an unknown pattern name', function () {
    expect(fn () => CustomerInputDefinition::normalize([
        ['slug' => 'note', 'label' => 'Note', 'kind' => 'text', 'pattern' => 'no-spaces'],
    ]))->toThrow(ValidationException::class);
});

test('accepts named pattern presets', function (string $pattern) {
    $normalized = CustomerInputDefinition::normalize([
        ['slug' => 'note', 'label' => 'Note', 'kind' => 'text', 'pattern' => $pattern],
    ]);

    expect($normalized[0]['pattern'])->toBe($pattern);
})->with(['no-emoji', 'letters-digits-spaces']);

test('choice requires between 1 and 12 string options', function (array $options) {
    expect(fn () => CustomerInputDefinition::normalize([
        ['slug' => 'colour', 'label' => 'Colour', 'kind' => 'choice', 'options' => $options],
    ]))->toThrow(ValidationException::class);
})->with([
    'empty' => [[]],
    'too many' => [array_map(fn (int $i) => 'opt-'.$i, range(1, 13))],
    'non-string' => [[1, 2]],
]);

test('image max_files must be between 1 and 3', function (int $maxFiles) {
    expect(fn () => CustomerInputDefinition::normalize([
        ['slug' => 'photo', 'label' => 'Photo', 'kind' => 'image', 'max_files' => $maxFiles],
    ]))->toThrow(ValidationException::class);
})->with([
    'zero' => 0,
    'four' => 4,
]);

test('rejects help longer than 120 characters', function () {
    expect(fn () => CustomerInputDefinition::normalize([
        ['slug' => 'note', 'label' => 'Note', 'kind' => 'text', 'help' => str_repeat('x', 121)],
    ]))->toThrow(ValidationException::class);
});

test('strips kind-inapplicable keys and fills defaults', function () {
    $normalized = CustomerInputDefinition::normalize([
        [
            'slug' => 'note',
            'label' => 'Note',
            'kind' => 'text',
            'required' => true,
            'options' => ['ignored'],
            'max_files' => 3,
        ],
    ]);

    expect($normalized[0])->toMatchArray([
        'slug' => 'note',
        'label' => 'Note',
        'kind' => 'text',
        'required' => true,
        'max_chars' => 500,
        'pattern' => null,
        'help' => '',
    ])->and($normalized[0])->not->toHaveKey('options')
        ->and($normalized[0])->not->toHaveKey('max_files');
});

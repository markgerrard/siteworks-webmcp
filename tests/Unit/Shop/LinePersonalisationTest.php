<?php

use App\Services\Shop\LinePersonalisation;
use App\Services\Shop\CustomerInputDefinition;
use Illuminate\Validation\ValidationException;
use Tests\Support\LinePersonalisationFixtures;

test('null and empty submitted values freeze to null with an empty hash', function () {
    expect(LinePersonalisation::freeze([], []))->toBeNull()
        ->and(LinePersonalisation::hash(null))->toBe('')
        ->and(LinePersonalisation::hash([]))->toBe('');
});

test('the same frozen payload always hashes the same', function () {
    $frozen = LinePersonalisation::freeze(LinePersonalisationFixtures::generic(), [
        'engraving' => 'Ada Lovelace',
        'colour' => 'Gold',
    ]);

    expect($frozen)->not->toBeNull()
        ->and(LinePersonalisation::hash($frozen))->toBe(LinePersonalisation::hash($frozen))
        ->and(strlen((string) LinePersonalisation::hash($frozen)))->toBe(40);
});

test('key order does not change the hash', function () {
    $defs = LinePersonalisationFixtures::generic();
    $a = LinePersonalisation::freeze($defs, ['engraving' => 'Ada', 'colour' => 'Gold']);
    $b = LinePersonalisation::freeze($defs, ['colour' => 'Gold', 'engraving' => 'Ada']);

    expect(LinePersonalisation::hash($a))->toBe(LinePersonalisation::hash($b));
});

test('required text is refused when blank', function () {
    expect(fn () => LinePersonalisation::freeze(LinePersonalisationFixtures::bakery(), [
        'message' => '   ',
    ]))->toThrow(ValidationException::class);
});

test('unknown slugs are refused', function () {
    expect(fn () => LinePersonalisation::freeze(LinePersonalisationFixtures::bakery(), [
        'message' => 'Happy birthday',
        'not-on-product' => 'nope',
    ]))->toThrow(ValidationException::class);
});

test('choice values must match an option', function () {
    expect(fn () => LinePersonalisation::freeze(LinePersonalisationFixtures::generic(), [
        'engraving' => 'Ada',
        'colour' => 'Purple',
    ]))->toThrow(ValidationException::class);
});

test('no-emoji pattern rejects pictographs', function () {
    expect(fn () => LinePersonalisation::freeze(LinePersonalisationFixtures::bakery(), [
        'message' => 'Happy birthday 🎂',
    ]))->toThrow(ValidationException::class);
});

test('letters-digits-spaces pattern rejects punctuation', function () {
    expect(fn () => LinePersonalisation::freeze(LinePersonalisationFixtures::generic(), [
        'engraving' => 'Ada!',
        'colour' => 'Gold',
    ]))->toThrow(ValidationException::class);
});

test('text over max_chars is refused', function () {
    expect(fn () => LinePersonalisation::freeze(LinePersonalisationFixtures::bakery(), [
        'message' => str_repeat('a', 81),
    ]))->toThrow(ValidationException::class);
});

test('frozen copy keeps label and kind so later product edits cannot rewrite history', function () {
    $frozen = LinePersonalisation::freeze(LinePersonalisationFixtures::bakery(), [
        'message' => 'Happy birthday',
    ]);

    expect($frozen['message'])->toMatchArray([
        'label' => 'Message on the cake',
        'kind' => 'text',
        'value' => 'Happy birthday',
    ]);
});

test('frozen copy retains the complete normalized input definition', function () {
    $definitions = [
        [
            'slug' => 'note',
            'label' => 'Note',
            'kind' => 'text',
            'required' => true,
            'max_chars' => 40,
            'pattern' => 'letters-digits-spaces',
            'help' => 'Shown on the item',
        ],
        [
            'slug' => 'colour',
            'label' => 'Colour',
            'kind' => 'choice',
            'required' => false,
            'options' => ['Silver', 'Gold'],
            'help' => '',
        ],
        [
            'slug' => 'photos',
            'label' => 'Photos',
            'kind' => 'image',
            'required' => false,
            'max_files' => 2,
            'help' => '',
        ],
    ];

    $frozen = LinePersonalisation::freeze($definitions, [
        'note' => 'Ada',
        'colour' => 'Gold',
    ]);

    expect($frozen['note'])->toMatchArray([
        'required' => true,
        'max_chars' => 40,
        'pattern' => 'letters-digits-spaces',
        'help' => 'Shown on the item',
    ])->and($frozen['colour'])->toMatchArray([
        'required' => false,
        'options' => ['Silver', 'Gold'],
    ])->and(LinePersonalisation::definitionsFromFrozen($frozen))
        ->toBe(CustomerInputDefinition::normalize($definitions));
});

test('optional image may be omitted while its frozen definition is retained', function () {
    $frozen = LinePersonalisation::freeze(LinePersonalisationFixtures::bakery(), [
        'message' => 'Happy birthday',
    ]);

    expect($frozen)->toHaveKey('message')
        ->and($frozen['photo'])->toMatchArray([
            'kind' => 'image',
            'required' => false,
            'max_files' => 1,
            'value' => null,
        ]);
});

test('image values must be a list of file records', function () {
    $defs = LinePersonalisationFixtures::bakery();

    expect(fn () => LinePersonalisation::freeze($defs, [
        'message' => 'Happy birthday',
        'photo' => 'not-a-file',
    ]))->toThrow(ValidationException::class);
});

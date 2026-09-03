<?php

use App\Support\Shop\ProductFacts;
use Illuminate\Validation\ValidationException;

test('null groups normalise to an empty list', function () {
    expect(ProductFacts::groups(null))->toBe([])
        ->and(ProductFacts::validateGroups(null))->toBe([]);
});

test('a group requires kebab slug unique label kind and optional schema', function () {
    $groups = ProductFacts::validateGroups([
        [
            'slug' => 'specs',
            'label' => 'Specifications',
            'kind' => 'pairs',
            'show_on_card' => true,
            'schema' => 'size',
        ],
        [
            'slug' => 'notes',
            'label' => 'Notes',
            'kind' => 'text',
            'show_on_card' => false,
            'schema' => null,
        ],
    ]);

    expect($groups)->toHaveCount(2)
        ->and($groups[0]['show_on_card'])->toBeTrue()
        ->and($groups[1]['schema'])->toBeNull();
});

test('rejects more than 24 groups', function () {
    $rows = [];
    for ($i = 1; $i <= 25; $i++) {
        $rows[] = [
            'slug' => 'group-'.$i,
            'label' => 'Group '.$i,
            'kind' => 'text',
            'show_on_card' => false,
            'schema' => null,
        ];
    }

    expect(fn () => ProductFacts::validateGroups($rows))->toThrow(ValidationException::class);
});

test('rejects a label longer than 40 characters', function () {
    expect(fn () => ProductFacts::validateGroups([[
        'slug' => 'specs',
        'label' => str_repeat('a', 41),
        'kind' => 'text',
        'show_on_card' => false,
        'schema' => null,
    ]]))->toThrow(ValidationException::class);
});

test('rejects a non-kebab slug and duplicate slugs', function () {
    expect(fn () => ProductFacts::validateGroups([[
        'slug' => 'Not_Kebab',
        'label' => 'Specs',
        'kind' => 'text',
        'show_on_card' => false,
        'schema' => null,
    ]]))->toThrow(ValidationException::class);

    expect(fn () => ProductFacts::validateGroups([
        ['slug' => 'specs', 'label' => 'One', 'kind' => 'text', 'show_on_card' => false, 'schema' => null],
        ['slug' => 'specs', 'label' => 'Two', 'kind' => 'text', 'show_on_card' => false, 'schema' => null],
    ]))->toThrow(ValidationException::class);
});

test('rejects an invalid kind or schema', function () {
    expect(fn () => ProductFacts::validateGroups([[
        'slug' => 'specs',
        'label' => 'Specs',
        'kind' => 'table',
        'show_on_card' => false,
        'schema' => null,
    ]]))->toThrow(ValidationException::class);

    expect(fn () => ProductFacts::validateGroups([[
        'slug' => 'specs',
        'label' => 'Specs',
        'kind' => 'pairs',
        'show_on_card' => false,
        'schema' => 'flavour',
    ]]))->toThrow(ValidationException::class);
});

test('facts accept pairs and text within limits', function () {
    $groups = [
        ['slug' => 'specs', 'kind' => 'pairs'],
        ['slug' => 'notes', 'kind' => 'text'],
    ];

    $facts = ProductFacts::validateFacts([
        'specs' => ['pairs' => [['label' => 'Width', 'value' => '12 cm']]],
        'notes' => ['text' => 'Handle with care.'],
    ], $groups);

    expect($facts['specs']['pairs'][0]['value'])->toBe('12 cm')
        ->and($facts['notes']['text'])->toBe('Handle with care.');
});

test('rejects unknown fact slugs and lists the valid slugs', function () {
    $groups = [
        ['slug' => 'specs', 'kind' => 'pairs'],
        ['slug' => 'notes', 'kind' => 'text'],
    ];

    try {
        ProductFacts::validateFacts([
            'mystery' => ['text' => 'nope'],
        ], $groups);
        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        $message = collect($exception->errors())->flatten()->first();
        expect($message)->toContain('mystery')
            ->and($message)->toContain('specs')
            ->and($message)->toContain('notes');
    }
});

test('rejects more than 40 pairs and oversized pair fields and text', function () {
    $groups = [
        ['slug' => 'specs', 'kind' => 'pairs'],
        ['slug' => 'notes', 'kind' => 'text'],
    ];

    $pairs = [];
    for ($i = 0; $i < 41; $i++) {
        $pairs[] = ['label' => 'L'.$i, 'value' => 'V'.$i];
    }

    expect(fn () => ProductFacts::validateFacts([
        'specs' => ['pairs' => $pairs],
    ], $groups))->toThrow(ValidationException::class);

    expect(fn () => ProductFacts::validateFacts([
        'specs' => ['pairs' => [['label' => str_repeat('a', 61), 'value' => 'ok']]],
    ], $groups))->toThrow(ValidationException::class);

    expect(fn () => ProductFacts::validateFacts([
        'specs' => ['pairs' => [['label' => 'ok', 'value' => str_repeat('a', 201)]]],
    ], $groups))->toThrow(ValidationException::class);

    expect(fn () => ProductFacts::validateFacts([
        'notes' => ['text' => str_repeat('a', 4001)],
    ], $groups))->toThrow(ValidationException::class);
});

test('kind mismatch is rejected', function () {
    $groups = [['slug' => 'notes', 'kind' => 'text']];

    expect(fn () => ProductFacts::validateFacts([
        'notes' => ['pairs' => [['label' => 'A', 'value' => 'B']]],
    ], $groups))->toThrow(ValidationException::class);
});

test('kind conversion turns pairs into joined lines and text into one pair', function () {
    $pairs = ['pairs' => [
        ['label' => 'Serves', 'value' => '12'],
        ['label' => 'Gluten free', 'value' => ''],
        ['label' => '', 'value' => ''],
    ]];
    $text = ProductFacts::convertValueToKind($pairs, 'text');
    expect($text)->toBe(['text' => "Serves 12\nGluten free"]);

    $one = ProductFacts::convertValueToKind(['text' => "Line one\nLine two"], 'pairs');
    expect($one)->toBe(['pairs' => [['label' => '', 'value' => "Line one\nLine two"]]]);
});

test('empty values are skipped for visible tabs', function () {
    $groups = ProductFacts::validateGroups([
        ['slug' => 'specs', 'label' => 'Specs', 'kind' => 'pairs', 'show_on_card' => false, 'schema' => null],
        ['slug' => 'notes', 'label' => 'Notes', 'kind' => 'text', 'show_on_card' => false, 'schema' => null],
        ['slug' => 'extra', 'label' => 'Extra', 'kind' => 'text', 'show_on_card' => false, 'schema' => null],
    ]);

    $tabs = ProductFacts::visibleTabs($groups, [
        'specs' => ['pairs' => [['label' => 'Width', 'value' => '12']]],
        'notes' => ['text' => '   '],
        'extra' => ['text' => 'Kept'],
        'orphan' => ['text' => 'ignored at render'],
    ]);

    expect(collect($tabs)->pluck('slug')->all())->toBe(['specs', 'extra']);
});

test('visible tabs convert stored pairs when a preset changes the group kind to text', function () {
    $groups = ProductFacts::validateGroups([
        ['slug' => 'ingredients', 'label' => 'Ingredients', 'kind' => 'text', 'show_on_card' => false, 'schema' => null],
    ]);

    $tabs = ProductFacts::visibleTabs($groups, [
        'ingredients' => ['pairs' => [
            ['label' => 'Flour', 'value' => '500 g'],
            ['label' => 'Salt', 'value' => '1 tsp'],
        ]],
    ]);

    expect($tabs)->toHaveCount(1)
        ->and($tabs[0]['value'])->toBe(['text' => "Flour 500 g\nSalt 1 tsp"]);
});

test('visible tabs convert stored text when a group is re-added with the pairs kind', function () {
    $groups = ProductFacts::validateGroups([
        ['slug' => 'care', 'label' => 'Care', 'kind' => 'pairs', 'show_on_card' => false, 'schema' => null],
    ]);

    $tabs = ProductFacts::visibleTabs($groups, [
        'care' => ['text' => 'Keep refrigerated'],
    ]);

    expect($tabs)->toHaveCount(1)
        ->and($tabs[0]['value'])->toBe([
            'pairs' => [['label' => '', 'value' => 'Keep refrigerated']],
        ]);
});

test('card line uses the first pair of each flagged group and truncates to 60', function () {
    $groups = ProductFacts::validateGroups([
        ['slug' => 'a', 'label' => 'A', 'kind' => 'pairs', 'show_on_card' => true, 'schema' => null],
        ['slug' => 'b', 'label' => 'B', 'kind' => 'pairs', 'show_on_card' => true, 'schema' => null],
        ['slug' => 'c', 'label' => 'C', 'kind' => 'pairs', 'show_on_card' => false, 'schema' => null],
        ['slug' => 'd', 'label' => 'D', 'kind' => 'text', 'show_on_card' => false, 'schema' => null],
    ]);

    $line = ProductFacts::cardLine($groups, [
        'a' => ['pairs' => [['label' => 'Serves', 'value' => '12'], ['label' => 'Skip', 'value' => 'me']]],
        'b' => ['pairs' => [['label' => 'Gluten free', 'value' => '']]],
        'c' => ['pairs' => [['label' => 'Hidden', 'value' => 'yes']]],
        'd' => ['text' => 'not a pair'],
    ]);

    expect($line)->toBe('Serves 12 · Gluten free');

    $long = ProductFacts::cardLine($groups, [
        'a' => ['pairs' => [['label' => str_repeat('W', 40), 'value' => str_repeat('V', 40)]]],
        'b' => ['pairs' => [['label' => 'More', 'value' => 'text']]],
    ]);
    expect(mb_strlen($long))->toBe(60);
});

test('card line uses the first line of a flagged text group and truncates to 60', function () {
    $groups = ProductFacts::validateGroups([
        ['slug' => 'care', 'label' => 'Care', 'kind' => 'text', 'show_on_card' => true, 'schema' => null],
        ['slug' => 'notes', 'label' => 'Notes', 'kind' => 'text', 'show_on_card' => false, 'schema' => null],
    ]);

    $line = ProductFacts::cardLine($groups, [
        'care' => ['text' => "Keep cool and dry.\nSecond line is ignored."],
        'notes' => ['text' => 'hidden'],
    ]);
    expect($line)->toBe('Keep cool and dry.');

    $long = ProductFacts::cardLine($groups, [
        'care' => ['text' => str_repeat('W', 80)],
    ]);
    expect($long)->toBe(str_repeat('W', 60))
        ->and(mb_strlen($long))->toBe(60);
});

test('card line is null when no group is flagged', function () {
    $groups = ProductFacts::validateGroups([
        ['slug' => 'a', 'label' => 'A', 'kind' => 'pairs', 'show_on_card' => false, 'schema' => null],
    ]);

    expect(ProductFacts::cardLine($groups, [
        'a' => ['pairs' => [['label' => 'Serves', 'value' => '12']]],
    ]))->toBeNull();
});

test('presets are data-only and bakery florist match the brief', function () {
    $presets = ProductFacts::presets();

    expect(array_keys($presets))->toBe([
        'bakery',
        'florist',
        'furniture',
        'apparel',
        'cosmetics',
        'generic-specifications',
    ])
        ->and($presets['bakery']['label'])->toBe('Bakery')
        ->and($presets['generic-specifications']['label'])->toBe('Generic — Specifications');

    $bakery = collect(ProductFacts::presetGroups('bakery'));
    expect($bakery->pluck('label')->all())->toBe(['Allergens', 'Ingredients', 'Nutrition', 'Serves'])
        ->and($bakery->firstWhere('label', 'Allergens')['kind'])->toBe('text')
        ->and($bakery->firstWhere('label', 'Ingredients')['kind'])->toBe('text')
        ->and($bakery->firstWhere('label', 'Nutrition'))->toMatchArray(['kind' => 'pairs', 'schema' => 'nutrition'])
        ->and($bakery->firstWhere('label', 'Serves'))->toMatchArray(['kind' => 'pairs', 'show_on_card' => true]);

    $florist = collect(ProductFacts::presetGroups('florist'));
    expect($florist->pluck('label')->all())->toBe(["What's included", 'Care', 'Delivery notes']);
});

test('unique slug increments when taken', function () {
    expect(ProductFacts::uniqueSlug('Notes', []))->toBe('notes')
        ->and(ProductFacts::uniqueSlug('Notes', ['notes']))->toBe('notes-2');
});

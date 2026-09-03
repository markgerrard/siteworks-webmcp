<?php

use App\Support\Shop\ProductTagAssignment;
use App\Support\Shop\ProductTagVocabulary;

function assignmentVocab(): array
{
    return ProductTagVocabulary::parse([
        ['slug' => 'same-day', 'label' => 'Same day', 'show_as_badge' => true, 'tone' => 'accent'],
        ['slug' => 'seasonal', 'label' => 'Seasonal', 'show_as_badge' => true, 'tone' => 'warning'],
        ['slug' => 'gift', 'label' => 'Gift', 'show_as_badge' => false, 'tone' => 'neutral'],
    ]);
}

it('parses up to five known slugs in given order unique', function () {
    $slugs = ProductTagAssignment::parse(['seasonal', 'same-day', 'seasonal'], assignmentVocab());

    expect($slugs)->toBe(['seasonal', 'same-day']);
});

it('rejects more than five slugs', function () {
    $vocab = ProductTagVocabulary::parse([
        ['slug' => 'a', 'label' => 'A', 'show_as_badge' => false, 'tone' => 'neutral'],
        ['slug' => 'b', 'label' => 'B', 'show_as_badge' => false, 'tone' => 'neutral'],
        ['slug' => 'c', 'label' => 'C', 'show_as_badge' => false, 'tone' => 'neutral'],
        ['slug' => 'd', 'label' => 'D', 'show_as_badge' => false, 'tone' => 'neutral'],
        ['slug' => 'e', 'label' => 'E', 'show_as_badge' => false, 'tone' => 'neutral'],
        ['slug' => 'f', 'label' => 'F', 'show_as_badge' => false, 'tone' => 'neutral'],
    ]);

    expect(fn () => ProductTagAssignment::parse(['a', 'b', 'c', 'd', 'e', 'f'], $vocab))
        ->toThrow(InvalidArgumentException::class, 'at most 5');
});

it('rejects unknown slugs and lists the valid ones', function () {
    try {
        ProductTagAssignment::parse(['nope', 'seasonal'], assignmentVocab());
        expect(false)->toBeTrue();
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('nope')
            ->and($e->getMessage())->toContain('same-day')
            ->and($e->getMessage())->toContain('seasonal')
            ->and($e->getMessage())->toContain('gift');
    }
});

it('normalise ignores unknown slugs at render', function () {
    expect(ProductTagAssignment::normalize(['nope', 'gift', 'nope'], assignmentVocab()))
        ->toBe(['gift']);
});

<?php

use App\Support\Shop\ProductTagVocabulary;

it('accepts an empty vocabulary', function () {
    expect(ProductTagVocabulary::parse([]))->toBe([])
        ->and(ProductTagVocabulary::normalize(null))->toBe([]);
});

it('parses ordered vocabulary entries', function () {
    $tags = ProductTagVocabulary::parse([
        ['slug' => 'same-day', 'label' => 'Same day', 'show_as_badge' => true, 'tone' => 'accent'],
        ['slug' => 'seasonal', 'label' => 'Seasonal', 'show_as_badge' => false, 'tone' => 'neutral'],
    ]);

    expect($tags)->toHaveCount(2)
        ->and($tags[0])->toMatchArray([
            'slug' => 'same-day',
            'label' => 'Same day',
            'show_as_badge' => true,
            'tone' => 'accent',
        ])
        ->and($tags[1]['show_as_badge'])->toBeFalse();
});

it('rejects more than 40 tags', function () {
    $raw = [];
    for ($i = 1; $i <= 41; $i++) {
        $raw[] = ['slug' => 'tag-'.$i, 'label' => 'Tag '.$i, 'show_as_badge' => false, 'tone' => 'neutral'];
    }

    expect(fn () => ProductTagVocabulary::parse($raw))
        ->toThrow(InvalidArgumentException::class, 'at most 40');
});

it('rejects duplicate slugs', function () {
    expect(fn () => ProductTagVocabulary::parse([
        ['slug' => 'same-day', 'label' => 'A', 'show_as_badge' => true, 'tone' => 'accent'],
        ['slug' => 'same-day', 'label' => 'B', 'show_as_badge' => false, 'tone' => 'neutral'],
    ]))->toThrow(InvalidArgumentException::class, 'unique');
});

it('rejects non-kebab slugs', function (string $slug) {
    expect(fn () => ProductTagVocabulary::parse([
        ['slug' => $slug, 'label' => 'Label', 'show_as_badge' => true, 'tone' => 'accent'],
    ]))->toThrow(InvalidArgumentException::class, 'kebab');
})->with(['SameDay', 'same_day', 'same day', '-leading', 'trailing-', 'emojis-🎉']);

it('rejects unknown tones', function () {
    expect(fn () => ProductTagVocabulary::parse([
        ['slug' => 'same-day', 'label' => 'Same day', 'show_as_badge' => true, 'tone' => 'danger'],
    ]))->toThrow(InvalidArgumentException::class, 'tone');
});

it('rejects a blank label', function () {
    expect(fn () => ProductTagVocabulary::parse([
        ['slug' => 'same-day', 'label' => '  ', 'show_as_badge' => true, 'tone' => 'accent'],
    ]))->toThrow(InvalidArgumentException::class, 'label');
});

it('normalise drops invalid entries instead of throwing', function () {
    $tags = ProductTagVocabulary::normalize([
        ['slug' => 'ok', 'label' => 'Ok', 'show_as_badge' => true, 'tone' => 'success'],
        ['slug' => 'Nope', 'label' => 'Bad', 'show_as_badge' => true, 'tone' => 'accent'],
        ['slug' => 'ok', 'label' => 'Duplicate', 'show_as_badge' => false, 'tone' => 'neutral'],
    ]);

    expect($tags)->toHaveCount(1)
        ->and($tags[0]['slug'])->toBe('ok');
});

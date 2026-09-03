<?php

use App\Support\Shop\AccentWordChips;

test('accent word chips trim punctuation, drop empties, and dedupe case-insensitively', function () {
    expect(AccentWordChips::for("Cakes, & Bakes -- (Fresh)  Cakes!  "))->toBe(['Cakes', 'Bakes', 'Fresh']);
});

test('accent word chips return an empty list for blank text', function () {
    expect(AccentWordChips::for(''))->toBe([])
        ->and(AccentWordChips::for('   '))->toBe([]);
});

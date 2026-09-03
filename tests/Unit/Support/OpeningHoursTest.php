<?php

test('rows normalises both stored shapes and drops junk', function () {
    expect(\App\Support\OpeningHours::rows(['Mon' => '9-5', 'Tue' => ' 9-5 ', '' => 'x', 'Wed' => '']))->toBe([['day' => 'Mon', 'hours' => '9-5'], ['day' => 'Tue', 'hours' => '9-5']])
        ->and(\App\Support\OpeningHours::rows([['day' => 'Sat', 'hours' => '10-2'], ['day' => 'Sun', 'time' => 'Closed'], 'junk', ['day' => 'X']]))->toBe([['day' => 'Sat', 'hours' => '10-2'], ['day' => 'Sun', 'hours' => 'Closed']])
        ->and(\App\Support\OpeningHours::rows(null))->toBe([]);
});

test('fromLines parses the editor textarea', function () {
    expect(\App\Support\OpeningHours::fromLines("Mon–Fri: 8:00–17:00\nSat: 9-1\n\nbad line\n"))->toBe(['Mon–Fri' => '8:00–17:00', 'Sat' => '9-1']);
});

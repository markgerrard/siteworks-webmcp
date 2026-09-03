<?php

use App\Services\Site\ServicePageCapCalculator;

beforeEach(fn () => $this->calc = new ServicePageCapCalculator);

test('empty list returns empty buckets', function () {
    $result = $this->calc->split([]);

    expect($result['to_build'])->toBe([]);
    expect($result['deferred'])->toBe([]);
});

test('5 services all build (cap is n when n <= 8)', function () {
    $services = ['A', 'B', 'C', 'D', 'E'];
    $result = $this->calc->split($services);

    expect($result['to_build'])->toHaveCount(5);
    expect($result['deferred'])->toBe([]);
});

test('8 services all build at the boundary', function () {
    $services = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    $result = $this->calc->split($services);

    expect($result['to_build'])->toHaveCount(8);
    expect($result['deferred'])->toBe([]);
});

test('10 services build top 8 + defer 2', function () {
    $services = array_map(
        fn ($i) => ['name' => "Service {$i}", 'confidence' => 1.0 - ($i * 0.05)],
        range(1, 10),
    );
    $result = $this->calc->split($services);

    expect($result['to_build'])->toHaveCount(8);
    expect($result['deferred'])->toHaveCount(2);
    expect($result['deferred'][0]['name'])->toBe('Service 9');
    expect($result['deferred'][1]['name'])->toBe('Service 10');
});

test('15 services build top 10 + defer 5 at the higher-cap boundary', function () {
    $services = array_map(
        fn ($i) => ['name' => "Service {$i}", 'confidence' => 1.0 - ($i * 0.05)],
        range(1, 15),
    );
    $result = $this->calc->split($services);

    expect($result['to_build'])->toHaveCount(10);
    expect($result['deferred'])->toHaveCount(5);
});

test('20 services build top 10 + defer 10', function () {
    $services = array_map(
        fn ($i) => ['name' => "Service {$i}", 'confidence' => 1.0 - ($i * 0.02)],
        range(1, 20),
    );
    $result = $this->calc->split($services);

    expect($result['to_build'])->toHaveCount(10);
    expect($result['deferred'])->toHaveCount(10);
});

test('missing confidence defaults to 0.5 and preserves list order (stable sort)', function () {
    $services = ['First', 'Second', 'Third', 'Fourth', 'Fifth', 'Sixth', 'Seventh', 'Eighth', 'Ninth', 'Tenth'];
    $result = $this->calc->split($services);

    expect($result['to_build'])->toHaveCount(8);
    expect($result['deferred'])->toHaveCount(2);
    // List-position fall-through: first 8 build, last 2 defer
    expect($result['to_build'][0]['name'])->toBe('First');
    expect($result['to_build'][7]['name'])->toBe('Eighth');
    expect($result['deferred'][0]['name'])->toBe('Ninth');
    expect($result['deferred'][1]['name'])->toBe('Tenth');
});

test('confidence ordering promotes high-confidence items into the build bucket', function () {
    $services = [
        ['name' => 'Low', 'confidence' => 0.1],
        ['name' => 'High', 'confidence' => 0.9],
        ['name' => 'Mid', 'confidence' => 0.5],
    ];
    $result = $this->calc->split($services);

    expect($result['to_build'])->toHaveCount(3);
    expect($result['to_build'][0]['name'])->toBe('High');
    expect($result['to_build'][1]['name'])->toBe('Mid');
    expect($result['to_build'][2]['name'])->toBe('Low');
});

test('empty / invalid service entries are dropped before counting', function () {
    $services = ['Valid', '', '   ', ['name' => ''], ['no_name' => 'x'], 'Also Valid'];
    $result = $this->calc->split($services);

    expect($result['to_build'])->toHaveCount(2);
    expect($result['to_build'][0]['name'])->toBe('Valid');
    expect($result['to_build'][1]['name'])->toBe('Also Valid');
});

test('out-of-range confidence values are clamped to [0.0, 1.0]', function () {
    $services = [
        ['name' => 'A', 'confidence' => 5.0],
        ['name' => 'B', 'confidence' => -0.5],
    ];
    $result = $this->calc->split($services);

    expect($result['to_build'][0])->toBe(['name' => 'A', 'confidence' => 1.0]);
    expect($result['to_build'][1])->toBe(['name' => 'B', 'confidence' => 0.0]);
});

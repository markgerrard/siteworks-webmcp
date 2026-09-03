<?php

use App\Support\Shop\AutoTagConfig;

it('defaults every auto-tag rule to disabled', function () {
    $defaults = AutoTagConfig::defaults();

    expect(array_keys($defaults))->toBe(['best-seller', 'new', 'low-stock', 'made-to-order']);

    foreach ($defaults as $rule) {
        expect($rule['enabled'])->toBeFalse()
            ->and($rule['show_as_badge'])->toBeTrue()
            ->and($rule['label'])->not->toBe('')
            ->and($rule['label'])->not->toMatch('/bakery|florist|cake|flower/i');
    }
});

it('normalise fills missing rules from defaults without enabling them', function () {
    $config = AutoTagConfig::normalize([
        'new' => ['enabled' => true, 'label' => 'Just in', 'params' => ['days' => 7]],
    ]);

    expect($config['new']['enabled'])->toBeTrue()
        ->and($config['new']['label'])->toBe('Just in')
        ->and($config['new']['params']['days'])->toBe(7)
        ->and($config['best-seller']['enabled'])->toBeFalse()
        ->and($config['low-stock']['enabled'])->toBeFalse()
        ->and($config['made-to-order']['enabled'])->toBeFalse();
});

it('rejects an unknown auto-tag rule key', function () {
    expect(fn () => AutoTagConfig::parse(['bestseller' => ['enabled' => true]]))
        ->toThrow(InvalidArgumentException::class, 'unknown');
});

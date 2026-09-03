<?php

use App\Services\Shop\Fulfilment\FulfilmentConfig;
use App\Models\Site;

test('null or empty fulfilment is off', function () {
    expect(FulfilmentConfig::from(null))->toBeNull()
        ->and(FulfilmentConfig::from([]))->toBeNull();

    $site = Site::factory()->create(['fulfilment' => null]);
    expect($site->fulfilment)->toBeNull()
        ->and(FulfilmentConfig::fromSite($site))->toBeNull();
});

test('a site with no enabled methods is inactive', function () {
    $config = FulfilmentConfig::from([
        'delivery' => ['enabled' => false, 'zones' => []],
        'collect' => ['enabled' => false],
        'shipping' => ['enabled' => false],
    ]);

    expect($config)->not->toBeNull()
        ->and($config->isActive())->toBeFalse()
        ->and($config->enabledMethods())->toBe([]);
});

test('validate uppercases prefixes and rejects duplicates across zones', function () {
    $ok = FulfilmentConfig::validate([
        'delivery' => [
            'enabled' => true,
            'label' => 'Local delivery',
            'zones' => [
                ['name' => 'Inner', 'prefixes' => ['sw1a', 'SW1'], 'fee_cents' => 400, 'lead_time' => 'next day'],
                ['name' => 'Outer', 'prefixes' => ['SW'], 'fee_cents' => 600],
            ],
        ],
        'collect' => ['enabled' => true, 'label' => 'Click & collect', 'address' => '12 High Street'],
        'shipping' => ['enabled' => false],
        'widget' => ['prompt' => 'Check delivery to your postcode', 'remember_days' => 30],
    ]);

    expect($ok['ok'])->toBeTrue()
        ->and($ok['value']['delivery']['zones'][0]['prefixes'])->toBe(['SW1A', 'SW1']);

    $dup = FulfilmentConfig::validate([
        'delivery' => [
            'enabled' => true,
            'zones' => [
                ['name' => 'A', 'prefixes' => ['SW1'], 'fee_cents' => 0],
                ['name' => 'B', 'prefixes' => ['SW1'], 'fee_cents' => 0],
            ],
        ],
    ]);

    expect($dup['ok'])->toBeFalse()
        ->and($dup['errors']['delivery.zones.1.prefixes'] ?? null)->not->toBeNull();
});

test('validate rejects empty prefixes, overlong prefixes, negative fees and overlong lead times', function () {
    $result = FulfilmentConfig::validate([
        'delivery' => [
            'enabled' => true,
            'zones' => [
                [
                    'name' => 'Bad',
                    'prefixes' => ['', 'THISISTOOLONG', 'SW*'],
                    'fee_cents' => -1,
                    'lead_time' => str_repeat('x', 41),
                ],
            ],
        ],
    ]);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toHaveKey('delivery.zones.0.prefixes')
        ->and($result['errors'])->toHaveKey('delivery.zones.0.fee_cents')
        ->and($result['errors'])->toHaveKey('delivery.zones.0.lead_time');
});

test('validate rejects a zone name longer than 80 characters and accepts 80', function () {
    $ok = FulfilmentConfig::validate([
        'delivery' => [
            'enabled' => true,
            'zones' => [
                ['name' => str_repeat('A', 80), 'prefixes' => ['SW1'], 'fee_cents' => 0],
            ],
        ],
    ]);
    expect($ok['ok'])->toBeTrue();

    $tooLong = FulfilmentConfig::validate([
        'delivery' => [
            'enabled' => true,
            'zones' => [
                ['name' => str_repeat('A', 81), 'prefixes' => ['SW1'], 'fee_cents' => 0],
            ],
        ],
    ]);
    expect($tooLong['ok'])->toBeFalse()
        ->and($tooLong['errors'])->toHaveKey('delivery.zones.0.name');
});

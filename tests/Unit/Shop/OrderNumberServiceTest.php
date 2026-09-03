<?php

use App\Models\Shop\OrdersNumbering;
use App\Models\Site;
use App\Services\Shop\OrderNumberService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('generates site-prefixed zero-padded sequence', function () {
    $site = Site::factory()->create(['slug' => 'florist-bloom']);
    $svc = app(OrderNumberService::class);

    expect($svc->next($site->id))->toBe('FLORISTB-000001');
    expect($svc->next($site->id))->toBe('FLORISTB-000002');
});

test('per-site sequences do not collide', function () {
    $a = Site::factory()->create(['slug' => 'aaa']);
    $b = Site::factory()->create(['slug' => 'bbb']);
    $svc = app(OrderNumberService::class);

    expect($svc->next($a->id))->toBe('AAA-000001');
    expect($svc->next($b->id))->toBe('BBB-000001');
    expect($svc->next($a->id))->toBe('AAA-000002');
});

test('degenerate slug falls back to site id prefix', function () {
    $site = Site::factory()->create(['slug' => '!!']);
    $svc = app(OrderNumberService::class);
    expect($svc->next($site->id))->toBe("SITE{$site->id}-000001");
});

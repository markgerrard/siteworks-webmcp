<?php

use App\Services\Shop\CloudflarePurger;
use Illuminate\Support\Facades\Http;

test('purgeShop posts the correct tag when enabled', function () {
    config(['services.cloudflare.enabled' => true]);
    config(['services.cloudflare.zone_id' => 'zone123']);
    config(['services.cloudflare.token' => 'tok']);

    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true], 200)]);

    app(CloudflarePurger::class)->purgeShop(42);

    Http::assertSent(function ($req) {
        return str_contains($req->url(), 'zones/zone123/purge_cache')
            && $req['tags'] === ['shop:42']
            && $req->hasHeader('Authorization', 'Bearer tok');
    });
});

test('purgeShop no-ops when disabled', function () {
    config(['services.cloudflare.enabled' => false]);
    Http::fake();

    app(CloudflarePurger::class)->purgeShop(42);

    Http::assertNothingSent();
});

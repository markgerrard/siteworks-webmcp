<?php

use App\Http\Middleware\ResolvePreviewHost;
use App\Models\Site;

it('resolves a site by preview_domain as a full host without a Cloudflare suffix map', function () {
    $site = Site::factory()->create([
        'preview_domain' => 'localhost',
        'custom_domain' => null,
        'custom_domain_status' => null,
    ]);

    $middleware = app(ResolvePreviewHost::class);
    $method = new ReflectionMethod($middleware, 'resolveSite');
    $method->setAccessible(true);

    expect($method->invoke($middleware, 'localhost')?->id)->toBe($site->id);

    $controller = new ReflectionMethod(\App\Http\Controllers\PreviewController::class, 'resolveSiteByHost');
    $controller->setAccessible(true);

    expect($controller->invoke(app(\App\Http\Controllers\PreviewController::class), 'localhost')?->id)->toBe($site->id);
});

it('falls back to an active custom_domain when preview_domain does not match', function () {
    $site = Site::factory()->create([
        'preview_domain' => 'other-preview',
        'custom_domain' => 'bakery.example',
        'custom_domain_status' => 'active',
    ]);

    $middleware = app(ResolvePreviewHost::class);
    $method = new ReflectionMethod($middleware, 'resolveSite');
    $method->setAccessible(true);

    expect($method->invoke($middleware, 'bakery.example')?->id)->toBe($site->id);
});

it('does not treat an inactive custom_domain as a storefront host', function () {
    Site::factory()->create([
        'preview_domain' => 'other-preview',
        'custom_domain' => 'pending.example',
        'custom_domain_status' => 'pending',
    ]);

    $middleware = app(ResolvePreviewHost::class);
    $method = new ReflectionMethod($middleware, 'resolveSite');
    $method->setAccessible(true);

    expect($method->invoke($middleware, 'pending.example'))->toBeNull();
});

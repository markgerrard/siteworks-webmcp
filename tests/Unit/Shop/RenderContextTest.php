<?php

use App\Services\Shop\RenderContext;
use Illuminate\Http\Request;

test('preview subdomain request shows drafts', function () {
    $request = Request::create('https://preview.example.com/shop', 'GET');
    $request->headers->set('host', 'preview.example.com');

    $ctx = RenderContext::fromRequest($request, isPreviewHost: true);
    expect($ctx->includeDrafts)->toBeTrue();
});

test('signed preview query param with valid signature shows drafts', function () {
    $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'signed.test', now()->addMinutes(10), ['preview' => '1']
    );
    // Minimal: check that a request with a valid signature is picked up
    $request = Request::create($url, 'GET');

    $ctx = RenderContext::fromRequest($request, isPreviewHost: false);
    // if ?preview and signed, drafts visible
    if ($request->hasValidSignature() && $request->query('preview')) {
        expect($ctx->includeDrafts)->toBeTrue();
    } else {
        expect($ctx->includeDrafts)->toBeFalse();
    }
})->skip('signed route setup requires named route — assert logic in integration');

test('authenticated admin user sees drafts', function () {
    $request = Request::create('/shop', 'GET');
    $user = \App\Models\User::factory()->admin()->make();
    $request->setUserResolver(fn () => $user);

    $ctx = RenderContext::fromRequest($request, isPreviewHost: false);
    expect($ctx->includeDrafts)->toBeTrue();
});

test('public anonymous request hides drafts', function () {
    $request = Request::create('/shop', 'GET');
    $ctx = RenderContext::fromRequest($request, isPreviewHost: false);
    expect($ctx->includeDrafts)->toBeFalse();
});

test('filterSnapshot omits draft products for public context', function () {
    $ctx = new RenderContext(includeDrafts: false);
    $json = [
        'categories' => ['x' => ['slug' => 'x', 'product_slugs' => ['a', 'b']]],
        'products' => [
            'a' => ['slug' => 'a', 'status' => 'published'],
            'b' => ['slug' => 'b', 'status' => 'draft'],
        ],
        'featured_slugs' => ['a', 'b'],
    ];

    $filtered = $ctx->filterSnapshot($json);

    expect($filtered['products'])->toHaveKey('a');
    expect($filtered['products'])->not->toHaveKey('b');
    expect($filtered['categories']['x']['product_slugs'])->toBe(['a']);
    expect($filtered['featured_slugs'])->toBe(['a']);
});

test('filterSnapshot includes drafts when includeDrafts true', function () {
    $ctx = new RenderContext(includeDrafts: true);
    $json = [
        'categories' => ['x' => ['slug' => 'x', 'product_slugs' => ['a', 'b']]],
        'products' => [
            'a' => ['slug' => 'a', 'status' => 'published'],
            'b' => ['slug' => 'b', 'status' => 'draft'],
        ],
        'featured_slugs' => ['a', 'b'],
    ];

    $filtered = $ctx->filterSnapshot($json);
    expect($filtered['products'])->toHaveKeys(['a', 'b']);
    expect($filtered['categories']['x']['product_slugs'])->toBe(['a', 'b']);
    expect($filtered['featured_slugs'])->toBe(['a', 'b']);
});

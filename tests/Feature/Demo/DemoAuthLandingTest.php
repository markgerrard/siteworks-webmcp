<?php

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Support\AuthLanding;

it('rewrites the request port from APP_URL so signed demo URLs verify', function () {
    config()->set('demo.enabled', true);
    config()->set('app.url', 'http://app.localhost:8090');

    $request = \Illuminate\Http\Request::create('http://app.localhost/sites/64/pages/1');
    $middleware = new \App\Http\Middleware\DemoPublicRequestUrl;
    $middleware->handle($request, fn ($req) => response('ok'));

    expect($request->getPort())->toBe(8090)
        ->and($request->getHttpHost())->toBe('app.localhost:8090')
        ->and($request->getHost())->toBe('app.localhost');
});

it('uses the request origin as the editor parent origin when demo mode is on', function () {
    config()->set('demo.enabled', true);
    config()->set('app.url', 'http://app.localhost:8090');
    config()->set('domains.customer_domain', 'app.localhost');

    $request = \Illuminate\Http\Request::create('http://app.localhost/sites/64/pages/1/editor');

    expect(\App\Support\EditorParentOrigin::fromRequest($request))->toBe('http://app.localhost:8090')
        ->and(\App\Support\EditorParentOrigin::resolve('http://app.localhost:8090'))->toBe('http://app.localhost:8090');
});

it('uses APP_URL as the customer origin when demo mode is on', function () {
    config()->set('demo.enabled', true);
    config()->set('app.url', 'http://app.localhost:8090');

    $tenant = Client::factory()->create();
    $user = User::factory()->create([
        'client_id' => $tenant->id,
        'role' => null,
    ]);
    $site = Site::factory()->create(['client_id' => $tenant->id]);

    expect(AuthLanding::for($user))->toBe('http://app.localhost:8090/sites/'.$site->id)
        ->and(AuthLanding::for(null))->toBe('http://app.localhost:8090/');
});

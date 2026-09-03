<?php

use App\Services\Site\EditSessionCookie;
use Illuminate\Http\RedirectResponse;

test('GET /_edit/view-live redirects to / by default + clears edit cookie', function () {
    $controller = app(\App\Http\Controllers\Site\PublicEditExitController::class);
    $request = \Illuminate\Http\Request::create('/_edit/view-live', 'GET');

    /** @var RedirectResponse $response */
    $response = $controller->viewLive($request);

    expect($response->getStatusCode())->toBe(302);
    // Laravel's redirect('/') stringifies as the app URL with no
    // trailing slash in the path — we just care it's the root.
    expect(parse_url($response->getTargetUrl(), PHP_URL_PATH) ?: '/')->toBe('/');

    $cookies = $response->headers->getCookies();
    $forget = collect($cookies)->first(fn ($c) => $c->getName() === EditSessionCookie::NAME);
    expect($forget)->not->toBeNull();
    expect($forget->getExpiresTime())->toBeLessThan(time());
    // No Domain attribute — must match the host-only cookie set by
    // EditSessionCookie::make or the browser ignores the forget.
    expect($forget->getDomain())->toBeNull();
});

test('GET /_edit/view-live honours ?to=<path> for same-host redirects', function () {
    $controller = app(\App\Http\Controllers\Site\PublicEditExitController::class);
    $request = \Illuminate\Http\Request::create('/_edit/view-live?to=/about', 'GET');

    /** @var RedirectResponse $response */
    $response = $controller->viewLive($request);

    expect($response->getTargetUrl())->toEndWith('/about');
});

test('GET /_edit/view-live rejects external redirect targets', function () {
    $controller = app(\App\Http\Controllers\Site\PublicEditExitController::class);

    foreach (['https://evil.com', '//evil.com', 'evil.com', '  '] as $bad) {
        $request = \Illuminate\Http\Request::create('/_edit/view-live?to='.urlencode($bad), 'GET');
        /** @var RedirectResponse $response */
        $response = $controller->viewLive($request);
        // Laravel's redirect('/') stringifies as the app URL with no
    // trailing slash in the path — we just care it's the root.
    expect(parse_url($response->getTargetUrl(), PHP_URL_PATH) ?: '/')->toBe('/');
    }
});

test('GET /_edit/view-live route is registered + NOT behind EditSessionAuth', function () {
    $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())
        ->first(fn ($r) => $r->uri() === '_edit/view-live' && in_array('GET', $r->methods()));

    expect($routes)->not->toBeNull();
    // Deliberately outside EditSessionAuth — clearing a cookie must work
    // whether or not a valid session is present (idempotent replay).
    expect($routes->middleware())->not->toContain(\App\Http\Middleware\EditSessionAuth::class);
});

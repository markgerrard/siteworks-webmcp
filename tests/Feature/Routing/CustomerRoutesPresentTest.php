<?php

use Illuminate\Support\Facades\Route;

it('registers customer-surface route names', function () {
    expect(Route::has('home'))->toBeTrue();
    expect(Route::has('client.account'))->toBeTrue();
});

it('binds home to the customer_domain', function () {
    $route = Route::getRoutes()->getByName('home');

    expect($route->getDomain())->toBe(config('domains.customer_domain'));
});

it('binds client.account to the customer_domain', function () {
    $route = Route::getRoutes()->getByName('client.account');

    expect($route->getDomain())->toBe(config('domains.customer_domain'));
});

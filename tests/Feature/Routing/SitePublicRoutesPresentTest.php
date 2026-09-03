<?php

use Illuminate\Support\Facades\Route;

it('registers public preview + edit route names', function () {
    $expected = [
        'preview.show',
        'preview.page',
        'site.public-edit.field-update',
        'site.public-edit.publish',
        'site.public-edit.publish-summary',
        'site.public-edit.discard-all',
        'site.public-edit.media-upload',
        'site.public-edit.exit',
        'site.public-edit.view-live',
    ];

    foreach ($expected as $name) {
        expect(Route::has($name))->toBeTrue("missing route: {$name}");
    }
});

it('keeps __site_v2 routes registered', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => $r->uri())
        ->filter(fn ($uri) => str_starts_with($uri, '__site_v2'));

    expect($routes)->not->toBeEmpty();
});

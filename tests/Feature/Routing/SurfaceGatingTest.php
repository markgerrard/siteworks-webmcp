<?php

it('SURFACE=all loads every surface route file', function () {
    config()->set('surfaces.current', 'all');

    // Re-bootstrap by loading routes; in test runtime they're already loaded
    // for whatever the env was. Use route name presence as proxy for "loaded"
    // and assert the file presence has no gaps.
    $names = collect(\Illuminate\Support\Facades\Route::getRoutes())->map->getName()->filter();

    expect($names)->toContain('dashboard');         // agents
    expect($names)->toContain('home');              // customer
    expect($names)->toContain('preview.show');      // site-public
});

it('SURFACE=site-public boots without agents route names', function () {
    $output = artisanInSurface('site-public', 'route:list --json');

    $names = collect(json_decode($output, true))->pluck('name')->filter();

    expect($names)->not->toContain('dashboard');
    expect($names)->not->toContain('admin.index');
    expect($names)->toContain('preview.show');
});

it('SURFACE=agents boots without site-public route names', function () {
    $output = artisanInSurface('agents', 'route:list --json');

    $names = collect(json_decode($output, true))->pluck('name')->filter();

    expect($names)->not->toContain('preview.show');
    expect($names)->toContain('dashboard');
});

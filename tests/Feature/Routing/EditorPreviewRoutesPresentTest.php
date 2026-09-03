<?php

use Illuminate\Support\Facades\Route;

it('registers editor-preview surface route names', function () {
    expect(Route::has('editor-preview.show'))->toBeTrue();
});

it('binds editor-preview route to editor_preview_domain', function () {
    $route = Route::getRoutes()->getByName('editor-preview.show');

    expect($route->getDomain())->toBe(config('domains.editor_preview_domain'));
});

it('does not register any /_edit/ POST routes on the editor-preview origin', function () {
    $editorPreviewRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => $r->getDomain() === config('domains.editor_preview_domain'));

    foreach ($editorPreviewRoutes as $r) {
        expect(in_array('POST', $r->methods(), true))->toBeFalse(
            "editor-preview origin must not expose POST routes; found: {$r->uri()}"
        );
    }
});

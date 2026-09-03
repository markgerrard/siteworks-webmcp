<?php

use App\Http\Controllers\Site\EditorPreviewController;
use Illuminate\Support\Facades\Route;

// Editor-preview surface — sandbox iframe target.
//
// READS ONLY. No admin write endpoints register here. Editor write actions
// flow iframe → postMessage → admin origin; never iframe → admin direct.
//
// AUTHORIZATION
// The iframe loads cross-origin from the agents surface, so it has no
// auth cookies — there's no "current user" inside the iframe context.
// Authorization is proven by Laravel's `signed` middleware: the agents-
// side EditorShellController mints a temporarySignedRoute URL with a
// short expiry; only that signed URL can load the preview. The signature
// is unforgeable proof that an authorized agent minted the URL. Tampered
// or expired signatures get 403 from the framework before the controller
// runs.
//
// Strict CSP applied via App\Http\Middleware\EditorPreviewCsp.
$preview = Route::middleware([\App\Http\Middleware\EditorPreviewCsp::class, 'signed']);
if (! config('demo.enabled')) {
    $preview = $preview->domain(config('domains.editor_preview_domain'));
}
$preview->group(function () {
    Route::get('/sites/{site}/pages/{page}', EditorPreviewController::class)
        ->name('editor-preview.show');
});

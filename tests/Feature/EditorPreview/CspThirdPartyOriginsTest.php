<?php

use App\Http\Middleware\EditorPreviewCsp;
use Illuminate\Http\Request;

it('drops bunny fonts and the tailwind play cdn from editor-preview CSP', function () {
    config()->set('domains.agent_domain', 'agents.test');
    config()->set('domains.customer_domain', 'app.test');
    config()->set('editor_preview.csp_mode', 'enforce');

    $response = (new EditorPreviewCsp)->handle(
        Request::create('/editor-preview', 'GET'),
        fn () => response('ok'),
    );

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->not->toContain('fonts.bunny.net')
        ->and($csp)->not->toContain('cdn.tailwindcss.com')
        ->and($csp)->toContain('https://cdn.jsdelivr.net')
        // Leaflet on contact pages still loads from unpkg.
        ->and($csp)->toContain('https://unpkg.com')
        ->and($csp)->toMatch("/font-src 'self' data:(;|$)/");
});

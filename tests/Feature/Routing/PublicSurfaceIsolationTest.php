<?php

it('does not register any agent-domain or admin route names under SURFACE=site-public', function () {
    $output = artisanInSurface('site-public', 'route:list --json');
    $names = collect(json_decode($output, true))->pluck('name')->filter()->all();

    $forbidden = [
        // Agent-domain core
        'dashboard', 'sites.index', 'sites.show', 'sites.create', 'sites.store',
        'sites.start', 'sites.assignClient',
        'clients.index', 'clients.create', 'clients.store', 'clients.edit', 'clients.update',
        // Admin sub-routes
        'admin.index',
        // Site admin (WYSIWYG editor on agent host)
        'site.admin.preview', 'site.admin.field-update', 'site.admin.publish',
        'site.admin.publish-summary', 'site.admin.discard-all', 'site.admin.media-upload',
        'site.admin.open-live-editor',
        'site.version.preview', 'site.version.rollback',
        // Auth / SSO
        'agent.login', 'agent.sso.redirect', 'agent.sso.callback',
        // Settings (agent-domain only)
        'profile.edit',
        // Customer surface
        'home', 'client.account',
    ];

    foreach ($forbidden as $name) {
        expect($names)
            ->not->toContain($name)
            ->and(in_array($name, $names, true))
            ->toBeFalse("site-public surface MUST NOT register: {$name}");
    }
});

it('still registers public-surface route names under SURFACE=site-public', function () {
    $output = artisanInSurface('site-public', 'route:list --json');
    $names = collect(json_decode($output, true))->pluck('name')->filter()->all();

    $required = [
        'preview.show', 'preview.page',
        'site.public-edit.field-update', 'site.public-edit.publish',
        'site.public-edit.publish-summary', 'site.public-edit.discard-all',
        'site.public-edit.media-upload', 'site.public-edit.exit',
        'site.public-edit.view-live',
    ];

    foreach ($required as $name) {
        expect(in_array($name, $names, true))
            ->toBeTrue("site-public surface MUST register: {$name}");
    }
});

it('returns 404 for a representative agent path on a fresh app with SURFACE=site-public', function () {
    // Smoke check via curl into the running container — runs only when
    // SURFACE_ISOLATION_HOST_URL is set (per-stack cutover gate, not CI).
    if (! ($url = getenv('SURFACE_ISOLATION_HOST_URL'))) {
        $this->markTestSkipped('SURFACE_ISOLATION_HOST_URL not set');
    }

    $resp = \Illuminate\Support\Facades\Http::withHeaders(['Host' => parse_url($url, PHP_URL_HOST)])
        ->get(rtrim($url, '/').'/dashboard');

    expect($resp->status())->toBe(404);
});

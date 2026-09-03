<?php

use Illuminate\Support\Facades\Route;

it('registers all agent-domain route names from routes/agents.php', function () {
    $expectedNames = [
        'agent.login',
        'agent.sso.redirect',
        'agent.sso.callback',
        'dashboard',
        'sites.index',
        'sites.start',
        'site.admin.preview',
        'site.admin.field-update',
        'site.admin.publish',
        'site.admin.media-upload',
        'site.admin.open-live-editor',
        'site.version.preview',
        'admin.index',
        'profile.edit',
    ];

    foreach ($expectedNames as $name) {
        expect(Route::has($name))->toBeTrue("missing route: {$name}");
    }
});

it('binds dashboard to the agent_domain only', function () {
    $route = Route::getRoutes()->getByName('dashboard');

    expect($route)->not->toBeNull();
    expect($route->getDomain())->toBe(config('domains.agent_domain'));
});

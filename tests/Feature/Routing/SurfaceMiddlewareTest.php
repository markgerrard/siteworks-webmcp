<?php

use Symfony\Component\Process\Process;

it('site-public surface boots without referencing agent.login route', function () {
    // route:list resolves redirect closures lazily — the bug only fires
    // on actual unauthenticated request. Use config:show as a proxy: if
    // app boot dies trying to resolve agent.login, this fails.
    $process = new Process(
        ['php', 'artisan', 'config:show', 'app.name'],
        base_path(),
        ['SURFACE' => 'site-public'] + $_ENV,
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

it('agents surface still has redirectGuestsTo wired for agent.login', function () {
    config()->set('surfaces.current', 'agents');

    expect(\Illuminate\Support\Facades\Route::has('agent.login'))->toBeTrue();
});

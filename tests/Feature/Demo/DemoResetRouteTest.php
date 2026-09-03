<?php

use App\Services\Demo\DemoSnapshot;

function demoEnv(?string $mode, ?string $token): void
{
    config(['demo.enabled' => $mode === 'true', 'demo.reset_token' => (string) $token]);
}

it('is a 404 unless demo mode and a reset token are configured', function () {
    demoEnv('false', '');
    $this->get('/demo/reset?token=x')->assertNotFound();
});

it('refuses a wrong token and serves state for the right one', function () {
    demoEnv('true', 'secret-token');
    $this->get('/demo/reset?token=wrong')->assertForbidden();
    $this->get('/demo/reset')->assertForbidden();

    $fake = Mockery::mock(DemoSnapshot::class);
    $fake->shouldReceive('state')->once()->andReturn(['site' => 'Camino Bakehouse', 'products' => 10]);
    $this->app->instance(DemoSnapshot::class, $fake);

    $this->get('/demo/reset?token=secret-token&assert=1')
        ->assertOk()
        ->assertJson(['ok' => true, 'action' => 'assert', 'site' => 'Camino Bakehouse', 'products' => 10]);
});

it('resets from the snapshot and reports the restored state', function () {
    demoEnv('true', 'secret-token');
    $fake = Mockery::mock(DemoSnapshot::class);
    $fake->shouldReceive('hasSnapshot')->once()->andReturn(true);
    $fake->shouldReceive('reset')->once()->andReturn(['ms' => 812, 'site' => 'Camino Bakehouse', 'products' => 10, 'published' => 10]);
    $this->app->instance(DemoSnapshot::class, $fake);

    $this->get('/demo/reset?token=secret-token')
        ->assertOk()
        ->assertJson(['ok' => true, 'action' => 'reset', 'ms' => 812, 'products' => 10]);
});



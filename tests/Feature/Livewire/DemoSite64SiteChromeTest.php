<?php

use Livewire\Livewire;

it('site-status mounts for the demo user on site 64 and refreshes', function () {
    [$site, $user] = demoSite64();

    Livewire::actingAs($user)
        ->test('site-status', ['siteId' => $site->id])
        ->call('refresh')
        ->assertOk()
        ->assertSet('status', $site->status->value);
});

it('unpublished-changes-banner mounts for the demo user on site 64 and refreshes', function () {
    [$site, $user] = demoSite64();

    Livewire::actingAs($user)
        ->test('site.unpublished-changes-banner', ['siteId' => $site->id])
        ->call('refresh')
        ->assertOk();
});

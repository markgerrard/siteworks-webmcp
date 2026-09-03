<?php

use Livewire\Livewire;

it('home-hero-scene-studio mounts for the demo user on site 64 and toggles scene mode', function () {
    [$site, $user] = demoSite64();

    Livewire::actingAs($user)
        ->test('home-hero-scene-studio', ['siteId' => $site->id])
        ->call('toggleSceneMode')
        ->assertOk()
        ->assertSet('sceneEnabled', true);

    expect($site->fresh()->home_hero_scene_draft['enabled'] ?? false)->toBeTrue();
});

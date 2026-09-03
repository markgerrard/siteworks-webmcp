<?php

use App\Enums\AgentRole;
use App\Models\User;
use Livewire\Livewire;

test('demoted admin cannot keep saving LLM rates from a stale snapshot', function () {
    $admin = User::factory()->admin()->create();

    $component = Livewire::actingAs($admin)->test('admin-llm-cost');

    $admin->role = AgentRole::Agent;
    $admin->save();

    $component->call('save', 1)->assertForbidden();
});

test('demoted admin cannot keep reading LLM usage from a stale snapshot', function () {
    $admin = User::factory()->admin()->create();

    $component = Livewire::actingAs($admin)->test('admin-llm-usage');

    $admin->role = AgentRole::Agent;
    $admin->save();

    $component->call('setRange', '7d')->assertForbidden();
});

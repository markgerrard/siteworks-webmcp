<?php

use App\Enums\AgentRole;

it('has four cases', function () {
    expect(AgentRole::cases())->toHaveCount(4);
});

it('round-trips values', function () {
    expect(AgentRole::from('agent'))->toBe(AgentRole::Agent)
        ->and(AgentRole::from('manager'))->toBe(AgentRole::Manager)
        ->and(AgentRole::from('senior_manager'))->toBe(AgentRole::SeniorManager)
        ->and(AgentRole::from('admin'))->toBe(AgentRole::Admin);
});

it('rejects unknown values', function () {
    expect(AgentRole::tryFrom('nonsense'))->toBeNull();
});

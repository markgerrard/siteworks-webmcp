<?php

use App\Enums\PageStatus;

test('canTransitionTo enforces the allowed state machine', function () {
    // Published → Draft, Published → Archived are allowed
    expect(PageStatus::Published->canTransitionTo(PageStatus::Draft))->toBeTrue();
    expect(PageStatus::Published->canTransitionTo(PageStatus::Archived))->toBeTrue();

    // Draft → Published, Draft → Archived are allowed
    expect(PageStatus::Draft->canTransitionTo(PageStatus::Published))->toBeTrue();
    expect(PageStatus::Draft->canTransitionTo(PageStatus::Archived))->toBeTrue();

    // Archived → Draft, Archived → Published are allowed
    expect(PageStatus::Archived->canTransitionTo(PageStatus::Draft))->toBeTrue();
    expect(PageStatus::Archived->canTransitionTo(PageStatus::Published))->toBeTrue();
});

test('self-transitions are disallowed (no-op guard)', function () {
    foreach (PageStatus::cases() as $s) {
        expect($s->canTransitionTo($s))->toBeFalse();
    }
});

test('requiresConfirmationFor only flags Published → Archived', function () {
    expect(PageStatus::Published->requiresConfirmationFor(PageStatus::Archived))->toBeTrue();

    // Other transitions do not require confirmation
    expect(PageStatus::Published->requiresConfirmationFor(PageStatus::Draft))->toBeFalse();
    expect(PageStatus::Draft->requiresConfirmationFor(PageStatus::Archived))->toBeFalse();
    expect(PageStatus::Draft->requiresConfirmationFor(PageStatus::Published))->toBeFalse();
    expect(PageStatus::Archived->requiresConfirmationFor(PageStatus::Published))->toBeFalse();
    expect(PageStatus::Archived->requiresConfirmationFor(PageStatus::Draft))->toBeFalse();
});

test('isEligibleForPublish + isPublic are true only for Published', function () {
    expect(PageStatus::Published->isEligibleForPublish())->toBeTrue();
    expect(PageStatus::Published->isPublic())->toBeTrue();

    expect(PageStatus::Draft->isEligibleForPublish())->toBeFalse();
    expect(PageStatus::Draft->isPublic())->toBeFalse();

    expect(PageStatus::Archived->isEligibleForPublish())->toBeFalse();
    expect(PageStatus::Archived->isPublic())->toBeFalse();
});

test('labels are human-readable', function () {
    expect(PageStatus::Published->label())->toBe('Published');
    expect(PageStatus::Draft->label())->toBe('Draft');
    expect(PageStatus::Archived->label())->toBe('Archived');
});

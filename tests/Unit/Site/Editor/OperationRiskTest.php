<?php

use App\Services\Site\Editor\OperationRegistry;
use Tests\Support\EditorOperationRisk;

it('declares the complete approval risk classification', function () {
    $registry = OperationRegistry::discover();
    $actual = collect($registry->all())
        ->map(fn ($operation, string $name): bool => $registry->effectiveRequiresApproval($name))
        ->all();

    expect($actual)->toBe(EditorOperationRisk::expectedRequiresApproval());
});

it('gates select logo only through its declared delegation', function () {
    $registry = OperationRegistry::discover();
    $operation = $registry->get('select_logo');

    expect($operation->requiresApproval())->toBeFalse()
        ->and($operation->delegatesTo())->toBe(['restore_image_version'])
        ->and($registry->effectiveRequiresApproval('select_logo'))->toBeTrue();
});

it('gates set logo media only through its declared delegation', function () {
    $registry = OperationRegistry::discover();
    $operation = $registry->get('set_logo_media');

    expect($operation->requiresApproval())->toBeFalse()
        ->and($operation->delegatesTo())->toBe(['select_logo'])
        ->and($registry->effectiveRequiresApproval('set_logo_media'))->toBeTrue();
});

it('does not gate read only operations', function () {
    $registry = OperationRegistry::discover();

    foreach ($registry->all() as $name => $operation) {
        expect($operation->readOnly() && $registry->effectiveRequiresApproval($name))->toBeFalse();
    }
});

it('leaves every positional operation on the agent tools gate only', function (string $name) {
    expect(OperationRegistry::discover()->effectiveRequiresApproval($name))->toBeFalse();
})->with([
    'add_section',
    'edit_field',
    'move_section',
    'set_variant',
    'set_title_emphasis',
    'update_form',
    'remove_section',
    'restore_media_version',
]);

it('addresses every approval gated declaration at site scope except the page-addressed undo', function () {
    $registry = OperationRegistry::discover();

    foreach ($registry->all() as $name => $operation) {
        if ($registry->effectiveRequiresApproval($name)) {
            // undo_revision is the one page-addressed gated write: it destroys recoverable draft
            // state, so it gates like the asset writes even though its currency is revision_base.
            expect($operation->address())->toBe($name === 'undo_revision' ? 'page' : 'site');
        }
    }
});

it('labels the remaining repeatable assignment target gap on assigning image operations', function () {
    config(['editor.agent_approval.enabled' => true]);
    $registry = OperationRegistry::discover();

    foreach (['generate_image', 'upload_image'] as $operation) {
        expect($registry->get($operation)->sideEffects())
            ->toContain('repeatable image entries can be re-pointed without an epoch bump')
            ->toContain('assignment target is not fully bound');
    }
});

it('derives operation abilities from mutability', function () {
    foreach (OperationRegistry::discover()->all() as $operation) {
        expect($operation->ability())->toBe($operation->readOnly() ? 'view' : 'update');
    }
});

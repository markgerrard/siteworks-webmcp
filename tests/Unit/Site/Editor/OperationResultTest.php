<?php

use App\Services\Site\Editor\EditorState;
use App\Services\Site\Editor\OperationResult;
use App\Services\Site\Editor\ResultReceipt;

it('serialises ok and error envelopes exactly', function () {
    $state = new EditorState(siteId: 1, pageId: 2, draftRevisionId: 345, compositionRevision: 7, pendingPublish: true, structureEpoch: 3);
    expect(OperationResult::ok(['x' => 1], $state)->toArray())->toBe([
        'ok' => true, 'data' => ['x' => 1],
        'state' => ['site_id' => 1, 'page_id' => 2, 'draft_revision_id' => 345, 'composition_revision' => 7, 'pending_publish' => true, 'structure_epoch' => 3],
        'receipt' => ['new_revision' => null, 'effective' => null, 'changed' => [], 'warnings' => [], 'publishable' => false, 'preview' => 'not_applicable'],
    ]);
    expect(OperationResult::fail('stale_revision', 'stale', $state, ['current_revision_id' => 346])->toArray()['error'])
        ->toBe(['code' => 'stale_revision', 'message' => 'stale', 'current_revision_id' => 346]);
});

it('rejects unknown error codes', function () {
    $state = new EditorState(1, null, null, 0, false);
    OperationResult::fail('nope', 'x', $state);
})->throws(InvalidArgumentException::class);

it('replaces state via withState while preserving ok, data, error, deferred, and receipt', function () {
    $state = new EditorState(1, 2, 3, 4, false, 1);
    $next = new EditorState(1, 2, 9, 5, true, 2);

    $ok = OperationResult::ok(['x' => 1], $state);
    $deferred = fn (): OperationResult => $ok;
    $ok->deferred = $deferred;
    $receipt = ResultReceipt::forWrite(
        $state,
        'page',
        [['scope' => 'page', 'path' => 'sections.0.title', 'kind' => 'set']],
        ['stored_index' => 0, 'title' => 'Changed'],
        [['code' => 'meta_title_long', 'message' => 'Long', 'severity' => 'warn']],
    );
    $ok->receipt = $receipt;
    $okCopy = $ok->withState($next);

    expect($okCopy)->not->toBe($ok)
        ->and($okCopy->ok)->toBeTrue()
        ->and($okCopy->data)->toBe(['x' => 1])
        ->and($okCopy->error)->toBeNull()
        ->and($okCopy->state)->toBe($next)
        ->and($okCopy->deferred)->toBe($deferred)
        ->and($okCopy->receipt)->toBe($receipt)
        ->and($ok->state)->toBe($state)
        ->and($okCopy->toArray())->toBe([
            'ok' => true,
            'data' => ['x' => 1],
            'state' => $next->toArray(),
            'receipt' => $receipt->toArray(),
        ]);

    $fail = OperationResult::fail('validation', 'nope', $state, ['field' => 'x']);
    $fail->deferred = $deferred;
    $fail->receipt = $receipt;
    $failCopy = $fail->withState($next);

    expect($failCopy)->not->toBe($fail)
        ->and($failCopy->ok)->toBeFalse()
        ->and($failCopy->data)->toBe($fail->data)
        ->and($failCopy->error)->toBe($fail->error)
        ->and($failCopy->state)->toBe($next)
        ->and($failCopy->deferred)->toBe($deferred)
        ->and($failCopy->receipt)->toBe($receipt)
        ->and($fail->state)->toBe($state)
        ->and($failCopy->toArray())->toBe([
            'ok' => false,
            'error' => ['code' => 'validation', 'message' => 'nope', 'field' => 'x'],
            'state' => $next->toArray(),
            'receipt' => $receipt->toArray(),
        ]);
});

it('never lets extra override the canonical code and message keys', function () {
    $state = new EditorState(1, null, null, 0, false);
    $error = OperationResult::fail('validation', 'bad', $state, ['code' => 'internal', 'message' => 'x', 'fields' => ['a' => ['b']]])->toArray()['error'];
    expect($error)->toBe(['code' => 'validation', 'message' => 'bad', 'fields' => ['a' => ['b']]]);
});

it('classifies channels as agent or not', function () {
    expect(\App\Services\Site\Editor\ActorChannel::Ui->isAgent())->toBeFalse()
        ->and(\App\Services\Site\Editor\ActorChannel::Webmcp->isAgent())->toBeTrue()
        ->and(\App\Services\Site\Editor\ActorChannel::Mcp->isAgent())->toBeTrue();
});

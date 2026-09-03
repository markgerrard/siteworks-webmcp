<?php

use App\Support\Media\MediaOrigin;

test('fromSource treats an llm_call_id as generated regardless of source', function () {
    expect(MediaOrigin::fromSource('upload', 42))->toBe(MediaOrigin::Generated)
        ->and(MediaOrigin::fromSource('zion', 1))->toBe(MediaOrigin::Generated)
        ->and(MediaOrigin::fromSource('facebook', '99'))->toBe(MediaOrigin::Generated);
});

test('fromSource maps zion and facebook sources to imported', function (string $source) {
    expect(MediaOrigin::fromSource($source))->toBe(MediaOrigin::Imported);
})->with([
    'zion' => 'zion',
    'facebook' => 'facebook',
    'Zion mixed case' => 'Zion',
]);

test('fromSource maps ai_generated source to generated even without an llm call', function () {
    expect(MediaOrigin::fromSource('ai_generated'))->toBe(MediaOrigin::Generated)
        ->and(MediaOrigin::fromSource('generated'))->toBe(MediaOrigin::Generated);
});

test('fromSource maps remaining sources to uploaded', function (string $source) {
    expect(MediaOrigin::fromSource($source))->toBe(MediaOrigin::Uploaded);
})->with([
    'upload' => 'upload',
    'agent_uploaded' => 'agent_uploaded',
    'portrait_upload' => 'portrait_upload',
    'empty' => '',
]);

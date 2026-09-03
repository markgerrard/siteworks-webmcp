<?php

/**
 * Regression guard for the silent-upload failure mode: when
 * FILESYSTEM_DISK flipped to s3, Livewire's temporary uploads (disk unset →
 * "default") followed it. The browser then PUTs the file straight to the
 * Spaces origin, which the agents/portal CSP (`connect-src 'self'`) blocks —
 * no request reaches the app, no validation error renders, the picker just
 * does nothing. Temporary uploads must stay on the app host so they travel
 * through Livewire's same-origin upload-file endpoint regardless of where
 * final media lives.
 */
it('keeps livewire temporary uploads on the app host regardless of the media disk', function () {
    expect(config('livewire.temporary_file_upload.disk'))->toBe('local');
});

it('restates every temporary upload default the shallow config merge would otherwise null', function () {
    // mergeConfigFrom is shallow at the top level: overriding the block at all
    // means the package's own sub-keys vanish unless restated here.
    $block = config('livewire.temporary_file_upload');

    expect($block)->toBeArray()
        ->and($block['middleware'] ?? null)->toBeString()
        ->and($block['preview_mimes'] ?? null)->toBeArray()->not->toBeEmpty()
        ->and($block['max_upload_time'] ?? null)->toBeInt()
        ->and($block['cleanup'] ?? null)->toBeTrue();
});

<?php

use App\Support\MediaStorage;
use Illuminate\Support\Facades\Storage;

test('diskName is s3 when the media config key is unset', function () {
    $filesystems = config('filesystems');
    unset($filesystems['media']);
    config(['filesystems' => $filesystems]);

    expect(MediaStorage::diskName())->toBe('s3');
});

test('diskName honors a filesystems.media override', function () {
    config()->set('filesystems.media', 'public');

    expect(MediaStorage::diskName())->toBe('public');
});

test('disk returns the storage disk named by diskName', function () {
    Storage::fake('media-disk');
    config()->set('filesystems.media', 'media-disk');

    expect(MediaStorage::disk())->toBe(Storage::disk('media-disk'));
});

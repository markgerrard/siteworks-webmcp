<?php

use App\Support\Media\MediaKind;

test('fromMime maps image types to image', function (string $mime) {
    expect(MediaKind::fromMime($mime))->toBe(MediaKind::Image);
})->with([
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'uppercase' => 'IMAGE/PNG',
]);

test('fromMime maps video types to video', function (string $mime) {
    expect(MediaKind::fromMime($mime))->toBe(MediaKind::Video);
})->with([
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'uppercase' => 'VIDEO/QUICKTIME',
]);

test('fromMime maps everything else to document', function (string $mime) {
    expect(MediaKind::fromMime($mime))->toBe(MediaKind::Document);
})->with([
    'pdf' => 'application/pdf',
    'plain' => 'text/plain',
    'octet' => 'application/octet-stream',
]);

test('fromMime defaults null and empty mime to image', function (?string $mime) {
    expect(MediaKind::fromMime($mime))->toBe(MediaKind::Image);
})->with([
    'null' => null,
    'empty' => '',
]);

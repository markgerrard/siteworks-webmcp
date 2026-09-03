<?php

use App\Models\Site;
use App\Services\PreviewRenderer;
use Illuminate\Support\Facades\Log;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('no deprecation log when versioned-renderer flag is off', function () {
    config(['site.use_versioned_renderer' => false]);

    Log::spy();

    $site = Site::factory()->create();
    app(PreviewRenderer::class)->build($site);

    Log::shouldNotHaveReceived('warning', [
        fn ($message) => str_contains($message, 'PreviewRenderer hit'),
    ]);
});

test('logs deprecation warning when versioned-renderer flag is on', function () {
    config(['site.use_versioned_renderer' => true]);

    Log::spy();

    $site = Site::factory()->create();
    app(PreviewRenderer::class)->build($site);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($message) => str_contains($message, 'PreviewRenderer hit while versioned-renderer flag is on'));
});

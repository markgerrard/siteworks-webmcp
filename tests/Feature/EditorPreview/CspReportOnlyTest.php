<?php

it('uses Content-Security-Policy-Report-Only when EDITOR_PREVIEW_CSP_MODE=report-only', function () {
    config()->set('editor_preview.csp_mode', 'report-only');

    $response = $this->actingAs(\App\Models\User::factory()->create())
        ->get(route('editor-preview.show', [
            'site' => \App\Models\Site::factory()->create()->id,
            'page' => \App\Models\GeneratedPage::factory()->create()->id,
        ]));

    expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeTrue();
    expect($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('uses Content-Security-Policy when EDITOR_PREVIEW_CSP_MODE=enforce', function () {
    config()->set('editor_preview.csp_mode', 'enforce');

    $response = $this->actingAs(\App\Models\User::factory()->create())
        ->get(route('editor-preview.show', [
            'site' => \App\Models\Site::factory()->create()->id,
            'page' => \App\Models\GeneratedPage::factory()->create()->id,
        ]));

    expect($response->headers->has('Content-Security-Policy'))->toBeTrue();
    expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

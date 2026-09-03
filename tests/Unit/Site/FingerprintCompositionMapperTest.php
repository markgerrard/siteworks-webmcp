<?php

use App\Services\Site\FingerprintCompositionMapper;

beforeEach(fn () => $this->m = new FingerprintCompositionMapper);

test('maps hero/services/cta in order, preserving count hints', function () {
    $out = $this->m->map([
        'sections' => [
            ['type' => 'hero', 'variant' => 'big', 'items_count' => 0, 'has_image' => true, 'headline_preview' => 'Welcome'],
            ['type' => 'services', 'variant' => '', 'items_count' => 6, 'has_image' => true, 'headline_preview' => ''],
            ['type' => 'cta', 'variant' => '', 'items_count' => 0, 'has_image' => false, 'headline_preview' => 'Call us'],
        ],
    ]);

    expect($out[0]['type'])->toBe('hero');
    expect($out[1]['type'])->toBe('services');
    expect($out[1]['items_hint'])->toBe(6);
    expect($out[2]['type'])->toBe('cta');
});

test('maps extended types via deterministic table', function () {
    $out = $this->m->map([
        'sections' => [
            ['type' => 'stats-row', 'items_count' => 4, 'variant' => '', 'has_image' => false, 'headline_preview' => ''],
            ['type' => 'portfolio', 'items_count' => 6, 'variant' => '', 'has_image' => true, 'headline_preview' => ''],
            ['type' => 'logo-strip', 'items_count' => 8, 'variant' => '', 'has_image' => false, 'headline_preview' => ''],
            ['type' => 'other', 'items_count' => 0, 'variant' => '', 'has_image' => false, 'headline_preview' => ''],
        ],
    ]);

    expect(array_column($out, 'type'))->toBe(['trust', 'services', 'trust', 'about-text']);
});

test('returns empty array when no sections', function () {
    expect($this->m->map(['sections' => []]))->toBe([]);
    expect($this->m->map([]))->toBe([]);
});

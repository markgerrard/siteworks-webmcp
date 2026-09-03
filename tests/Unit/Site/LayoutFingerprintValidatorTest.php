<?php

use App\Services\Site\LayoutFingerprintValidator;

beforeEach(fn () => $this->v = new LayoutFingerprintValidator);

test('accepts a valid fingerprint unchanged', function () {
    $data = [
        'sections' => [['type' => 'hero', 'variant' => '', 'items_count' => 0, 'has_image' => true, 'headline_preview' => 'Hi']],
        'palette' => ['primary_hex_guess' => '#ff0000', 'dark_sections_used' => false],
        'typography' => ['heading_weight' => 'bold', 'uses_cursive_or_display_font' => false],
        'overall_density' => 'balanced',
        'meta' => ['sections_count_raw' => 1, 'confidence' => 0.9],
    ];
    expect($this->v->validate($data)['sections'][0]['type'])->toBe('hero');
});

test('nulls invalid hex in palette', function () {
    $out = $this->v->validate(['palette' => ['primary_hex_guess' => 'nope']]);
    expect($out['palette']['primary_hex_guess'])->toBeNull();
});

test('caps sections at 12 and records raw count', function () {
    $sections = array_fill(0, 20, ['type' => 'hero', 'variant' => 'v', 'items_count' => 0, 'has_image' => false, 'headline_preview' => '']);
    $out = $this->v->validate(['sections' => $sections]);
    expect(count($out['sections']))->toBe(12);
    expect($out['meta']['sections_count_raw'])->toBe(20);
});

test('downgrades unknown section type to other', function () {
    $out = $this->v->validate(['sections' => [['type' => 'banana', 'variant' => '', 'items_count' => 0, 'has_image' => false, 'headline_preview' => '']]]);
    expect($out['sections'][0]['type'])->toBe('other');
});

test('clamps items_count to [0,50]', function () {
    $out = $this->v->validate(['sections' => [['type' => 'services', 'variant' => '', 'items_count' => 9999, 'has_image' => false, 'headline_preview' => '']]]);
    expect($out['sections'][0]['items_count'])->toBe(50);
});

test('truncates variant to 40 chars and headline_preview to 80', function () {
    $out = $this->v->validate([
        'sections' => [[
            'type' => 'hero',
            'variant' => str_repeat('x', 100),
            'items_count' => 0,
            'has_image' => false,
            'headline_preview' => str_repeat('y', 200),
        ]],
    ]);
    expect(strlen($out['sections'][0]['variant']))->toBe(40);
    expect(strlen($out['sections'][0]['headline_preview']))->toBe(80);
});

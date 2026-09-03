<?php

use App\Services\Site\SectionSchema;

test('cost_disclaimer and cost_table are registered in site_sections config', function () {
    $sections = config('site_sections');

    expect($sections)->toHaveKey('cost_disclaimer');
    expect($sections['cost_disclaimer']['fields'])->toHaveKey('body');
    expect($sections['cost_disclaimer']['fields'])->not->toHaveKey('valid_until');

    expect($sections)->toHaveKey('cost_table');
    expect($sections['cost_table']['fields'])->toHaveKey('title');
    expect($sections['cost_table']['fields'])->toHaveKey('rows.*.job');
    expect($sections['cost_table']['fields'])->not->toHaveKey('rows.*.low');
    expect($sections['cost_table']['fields'])->not->toHaveKey('rows.*.high');
    expect($sections['cost_table']['fields'])->not->toHaveKey('rows.*.basis');
    expect($sections['cost_table']['fields'])->not->toHaveKey('rows.*.vat_note');
});

test('cost_disclaimer and cost_table are known section types', function () {
    $schema = app(SectionSchema::class);

    expect($schema->isKnownSectionType('cost_disclaimer'))->toBeTrue();
    expect($schema->isKnownSectionType('cost_table'))->toBeTrue();
});

test('cost_disclaimer blade renders typical-ranges framing and the valid_until date', function () {
    $html = view('site.sections.cost_disclaimer', [
        'section' => [
            'type' => 'cost_disclaimer',
            'body' => 'These are typical ranges, not a quote.',
            'valid_until' => '2026-12-31',
            'generated_at' => '2026-08-13',
        ],
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
    ])->render();

    expect($html)->toContain('typical ranges, not a quote');
    expect($html)->toContain('2026-12-31');
});

test('cost_table blade renders structured job rows', function () {
    $html = view('site.sections.cost_table', [
        'section' => [
            'type' => 'cost_table',
            'title' => 'Typical costs',
            'rows' => [[
                'job' => 'Single Storey Extension',
                'low' => 15000,
                'high' => 25000,
                'basis' => 'Per job',
                'vat_note' => 'Ex VAT',
            ]],
        ],
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
    ])->render();

    expect($html)->toContain('Typical costs');
    expect($html)->toContain('Single Storey Extension');
    expect($html)->toContain('Per job');
    expect($html)->toContain('Ex VAT');
});

test('cost_table blade keeps pence and omits them for whole pounds', function () {
    $html = view('site.sections.cost_table', [
        'section' => [
            'type' => 'cost_table',
            'title' => 'Typical costs',
            'rows' => [[
                'job' => 'Day rate',
                'low' => 45.50,
                'high' => 15000,
                'basis' => 'Per day',
                'vat_note' => 'Ex VAT',
            ]],
        ],
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
    ])->render();

    expect($html)->toContain('£45.50');
    expect($html)->toContain('£15,000');
    expect($html)->not->toContain('£46');
    expect($html)->not->toContain('£15,000.00');
});

test('cost_table blade does not repeat the title as a default eyebrow', function () {
    $html = view('site.sections.cost_table', [
        'section' => [
            'type' => 'cost_table',
            'title' => 'Typical costs',
            'rows' => [[
                'job' => 'Single Storey Extension',
                'low' => 15000,
                'high' => 25000,
                'basis' => 'Per job',
                'vat_note' => 'Ex VAT',
            ]],
        ],
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
    ])->render();

    expect(substr_count($html, 'Typical costs'))->toBe(1);
});

test('cost table money cells and disclaimer dates have no data-editable markers', function () {
    $table = view('site.sections.cost_table', [
        'section' => [
            'type' => 'cost_table',
            'title' => 'Typical costs',
            'eyebrow' => 'Guide',
            'rows' => [[
                'job' => 'Extension',
                'low' => 2450.50,
                'high' => 8000.25,
                'basis' => 'Per job',
                'vat_note' => 'Ex VAT',
            ]],
        ],
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => true,
    ])->render();

    expect($table)->toContain('data-editable="page.1.section.0.title"')
        ->and($table)->not->toContain('rows.0.low')
        ->and($table)->not->toContain('rows.0.high')
        ->and($table)->not->toContain('rows.0.basis')
        ->and($table)->not->toContain('rows.0.vat_note');

    $disclaimer = view('site.sections.cost_disclaimer', [
        'section' => [
            'type' => 'cost_disclaimer',
            'body' => 'These are typical ranges, not a quote.',
            'valid_until' => '2026-12-31',
        ],
        'sectionIndex' => 1,
        'pageId' => 1,
        'emitMarkers' => true,
    ])->render();

    expect($disclaimer)->not->toContain('valid_until');
});

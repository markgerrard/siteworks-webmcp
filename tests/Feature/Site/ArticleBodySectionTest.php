<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('article-body section renders sanitized rich body with inline img', function () {
    $html = view('site.sections.article-body', [
        'section' => [
            'type' => 'article-body',
            'body' => '<p>Hello <strong>world</strong></p><img src="https://cdn/x.jpg" alt="x">',
        ],
        'sectionIndex' => 0,
        'pageId' => 1,
        'emitMarkers' => false,
    ])->render();

    expect($html)->toContain('<strong>world</strong>');
    expect($html)->toContain('src="https://cdn/x.jpg"');
    expect($html)->toContain('alt="x"');
});

test('article-body section is registered in site_sections config', function () {
    $sections = config('site_sections');
    expect($sections)->toHaveKey('article-body');
    expect($sections['article-body']['fields'])->toHaveKey('body');
    expect($sections['article-body']['fields']['body']['type'])->toBe('rich');
});

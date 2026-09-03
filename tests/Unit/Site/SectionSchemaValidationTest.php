<?php

use App\Services\Site\SectionSchema;

beforeEach(function () {
    $this->schema = new SectionSchema([
        'hero' => ['fields' => [
            'title' => ['type' => 'plain', 'max' => 120, 'required' => true],
            'cta_url' => ['type' => 'url'],
            'background_image' => ['type' => 'image'],
            'count' => ['type' => 'integer', 'min' => 3, 'max' => 8],
            'source' => ['type' => 'enum', 'values' => ['featured', 'newest']],
        ]],
        'services' => ['fields' => [
            'items.*.title' => ['type' => 'plain', 'max' => 80],
            'items.*.body' => ['type' => 'rich'],
        ]],
    ]);
});

test('plain field accepts string within max', function () {
    $errors = $this->schema->validateField('hero', 'title', 'OK');
    expect($errors)->toBe([]);
});

test('plain field rejects when over max', function () {
    $errors = $this->schema->validateField('hero', 'title', str_repeat('a', 121));
    expect($errors)->not->toBe([]);
});

test('plain field rejects non-string', function () {
    $errors = $this->schema->validateField('hero', 'title', ['array']);
    expect($errors)->not->toBe([]);
});

test('url field rejects malformed URL', function () {
    $errors = $this->schema->validateField('hero', 'cta_url', 'not a url');
    expect($errors)->not->toBe([]);
});

test('url field accepts http and https', function () {
    expect($this->schema->validateField('hero', 'cta_url', 'https://example.com'))->toBe([]);
    expect($this->schema->validateField('hero', 'cta_url', 'http://example.com'))->toBe([]);
});

test('image field expects integer media id', function () {
    expect($this->schema->validateField('hero', 'background_image', 42))->toBe([]);
    expect($this->schema->validateField('hero', 'background_image', 'not-int'))->not->toBe([]);
});

test('rich field accepts a valid TipTap doc structure', function () {
    $doc = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]],
        ],
    ];
    expect($this->schema->validateField('services', 'items.0.body', $doc))->toBe([]);
});

test('rich field rejects non-doc structure', function () {
    expect($this->schema->validateField('services', 'items.0.body', 'plain string'))->not->toBe([]);
    expect($this->schema->validateField('services', 'items.0.body', ['type' => 'wrong']))->not->toBe([]);
});

test('integer field accepts ints and digit strings inside min/max', function () {
    expect($this->schema->validateField('hero', 'count', 4))->toBe([])
        ->and($this->schema->validateField('hero', 'count', '8'))->toBe([])
        ->and($this->schema->validateField('hero', 'count', 2))->not->toBe([])
        ->and($this->schema->validateField('hero', 'count', 9))->not->toBe([])
        ->and($this->schema->validateField('hero', 'count', 'nope'))->not->toBe([]);
});

test('enum field accepts the closed list', function () {
    expect($this->schema->validateField('hero', 'source', 'featured'))->toBe([])
        ->and($this->schema->validateField('hero', 'source', 'newest'))->toBe([])
        ->and($this->schema->validateField('hero', 'source', 'popular'))->not->toBe([]);
});

test('unknown field path returns error', function () {
    $errors = $this->schema->validateField('hero', 'nonexistent', 'value');
    expect($errors)->not->toBe([]);
});

test('rich field rejects disallowed node type (e.g. image)', function () {
    $doc = [
        'type' => 'doc',
        'content' => [
            ['type' => 'image', 'attrs' => ['src' => 'https://evil.example/img.png']],
        ],
    ];
    expect($this->schema->validateField('services', 'items.0.body', $doc))->not->toBe([]);
});

test('rich field rejects disallowed mark type (e.g. code)', function () {
    $doc = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'code']]],
            ]],
        ],
    ];
    expect($this->schema->validateField('services', 'items.0.body', $doc))->not->toBe([]);
});

test('rich field rejects link mark with array href (finding #4)', function () {
    $doc = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'click', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => ['array', 'value']]],
                ]],
            ]],
        ],
    ];
    expect($this->schema->validateField('services', 'items.0.body', $doc))->not->toBe([]);
});

test('rich field rejects link mark with non-http href', function () {
    $doc = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'click', 'marks' => [
                    ['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']],
                ]],
            ]],
        ],
    ];
    expect($this->schema->validateField('services', 'items.0.body', $doc))->not->toBe([]);
});

test('rich field accepts nested allowed nodes and marks', function () {
    $doc = [
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'content' => [['type' => 'text', 'text' => 'H']]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' and '],
                ['type' => 'text', 'text' => 'link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://x']]]],
            ]],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'item']]],
                ]],
            ]],
        ],
    ];
    expect($this->schema->validateField('services', 'items.0.body', $doc))->toBe([]);
});

// ── B1: DoS size/depth/text-length guards ────────────────────────────────────

test('rich field rejects a doc exceeding 64 KB encoded JSON (B1 size cap)', function () {
    // Build a valid TipTap doc whose JSON is > 64 KB via many text nodes.
    $paras = [];
    for ($i = 0; $i < 200; $i++) {
        $paras[] = ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => str_repeat('x', 400)],
        ]];
    }
    $doc = ['type' => 'doc', 'content' => $paras];
    expect(strlen(json_encode($doc)))->toBeGreaterThan(65536);

    $errors = $this->schema->validateField('services', 'items.0.body', $doc);
    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain('64 KB');
});

test('rich field rejects a document with nesting depth > 32 (B1 depth cap)', function () {
    // Construct a 40-level deep nested blockquote tree.
    $node = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'leaf']]];
    for ($i = 0; $i < 40; $i++) {
        $node = ['type' => 'blockquote', 'content' => [$node]];
    }
    $doc = ['type' => 'doc', 'content' => [$node]];

    $errors = $this->schema->validateField('services', 'items.0.body', $doc);
    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain('depth');
});

test('rich field rejects a text node with more than 20 000 characters (B1 text cap)', function () {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => str_repeat('a', 25000)],
        ]],
    ]];

    $errors = $this->schema->validateField('services', 'items.0.body', $doc);
    expect($errors)->not->toBe([]);
    expect(implode(' ', $errors))->toContain('20 000');
});

test('rich field accepts a realistic 3-paragraph doc without triggering any DoS guard (B1 pass-through)', function () {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Paragraph one content.']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Paragraph two content.']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Paragraph three content.']]],
    ]];

    expect($this->schema->validateField('services', 'items.0.body', $doc))->toBe([]);
});

test('hero_compact title validates like hero against the registered schema', function () {
    $schema = app(SectionSchema::class);

    expect($schema->isKnownSectionType('hero_compact'))->toBeTrue();
    expect($schema->validateField('hero_compact', 'title', 'Planning permission'))->toBe([]);
    expect($schema->validateField('hero_compact', 'eyebrow', 'Planning guide'))->toBe([]);
    expect($schema->validateField('hero_compact', 'subtitle', 'A plain-English walkthrough.'))->toBe([]);
    expect($schema->validateField('hero_compact', 'accent_word', 'planning'))->toBe([]);
    expect($schema->validateField('hero_compact', 'title', str_repeat('a', 121)))->not->toBe([]);
});

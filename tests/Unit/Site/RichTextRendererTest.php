<?php

use App\Services\Site\RichTextRenderer;

beforeEach(fn () => $this->renderer = app(RichTextRenderer::class));

test('renders paragraph with text', function () {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello world']]],
    ]];
    expect($this->renderer->render($doc))->toBe('<p>Hello world</p>');
});

test('renders bold + italic marks', function () {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Bold ', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => 'and italic', 'marks' => [['type' => 'italic']]],
        ]],
    ]];
    $html = $this->renderer->render($doc);
    expect($html)->toContain('<strong>Bold </strong>');
    expect($html)->toContain('<em>and italic</em>');
});

test('renders H2 and H3 only (other heading levels degrade to paragraph)', function () {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'H2']]],
        ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [['type' => 'text', 'text' => 'H3']]],
        ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'H1?']]],
    ]];
    $html = $this->renderer->render($doc);
    expect($html)->toContain('<h2>H2</h2>');
    expect($html)->toContain('<h3>H3</h3>');
    expect($html)->not->toContain('<h1>');
    expect($html)->toContain('<p>H1?</p>');
});

test('renders bullet + ordered lists', function () {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'one']]],
            ]],
        ]],
        ['type' => 'orderedList', 'content' => [
            ['type' => 'listItem', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'first']]],
            ]],
        ]],
    ]];
    $html = $this->renderer->render($doc);
    expect($html)->toContain('<ul>');
    expect($html)->toContain('<ol>');
    expect($html)->toContain('<li>');
});

test('renders link with sanitised href', function () {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'click', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]]],
        ]],
    ]];
    $html = $this->renderer->render($doc);
    expect($html)->toContain('<a href="https://example.com">click</a>');
});

test('drops javascript: URLs', function () {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'evil', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]],
        ]],
    ]];
    $html = $this->renderer->render($doc);
    expect($html)->not->toContain('javascript:');
});

test('escapes user text content', function () {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '<script>alert(1)</script>']]],
    ]];
    $html = $this->renderer->render($doc);
    expect($html)->not->toContain('<script>');
    expect($html)->toContain('&lt;script&gt;');
});

test('blockquote rendered', function () {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'blockquote', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'quoted']]],
        ]],
    ]];
    expect($this->renderer->render($doc))->toContain('<blockquote>');
});

test('empty doc renders empty string', function () {
    expect($this->renderer->render(['type' => 'doc', 'content' => []]))->toBe('');
});

test('array href in link mark renders text without anchor — no TypeError (finding #4)', function () {
    // SectionSchema rejects this at validation time, but the renderer must also
    // degrade gracefully as belt-and-braces (mixed type hint on wrapLink).
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'click', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => ['array', 'value']]],
            ]],
        ]],
    ]];
    // Must not throw a TypeError — should degrade to plain text.
    $html = $this->renderer->render($doc);
    expect($html)->toContain('click');
    expect($html)->not->toContain('<a ');
});

/*
 * renderValue(): shape-agnostic entry point. The section editors flatten
 * TipTap docs to plain strings joined with "\n\n" (see page-manager's
 * $flattenDoc — "so paragraph breaks survive the round-trip"), but the
 * templates' string path used bare e(), collapsing those breaks into one
 * blob paragraph. renderValue honours the convention: docs render as
 * before, strings render as <p> blocks split on blank lines.
 */

test('renderValue renders a doc array exactly like render', function () {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello world']]],
    ]];
    expect($this->renderer->renderValue($doc))->toBe($this->renderer->render($doc));
});

test('renderValue splits a plain string into paragraphs on blank lines', function () {
    expect($this->renderer->renderValue("First paragraph.\n\nSecond paragraph."))
        ->toBe('<p>First paragraph.</p><p>Second paragraph.</p>');
});

test('renderValue turns single newlines into line breaks within a paragraph', function () {
    expect($this->renderer->renderValue("Line one\nLine two"))
        ->toBe('<p>Line one<br>Line two</p>');
});

test('renderValue escapes html in strings', function () {
    expect($this->renderer->renderValue('<script>alert(1)</script> & "quotes"'))
        ->toBe('<p>&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;quotes&quot;</p>');
});

test('renderValue tolerates windows newlines and surplus blank lines', function () {
    expect($this->renderer->renderValue("One.\r\n\r\nTwo.\n\n\n\nThree."))
        ->toBe('<p>One.</p><p>Two.</p><p>Three.</p>');
});

test('renderValue returns empty string for empty, null, and non-doc values', function () {
    expect($this->renderer->renderValue(''))->toBe('')
        ->and($this->renderer->renderValue('   '))->toBe('')
        ->and($this->renderer->renderValue(null))->toBe('')
        ->and($this->renderer->renderValue(42))->toBe('');
});

/*
 * docFromPlainText(): inverse of the paragraph convention — used by the
 * page-manager flyout to lift legacy plain-string bodies into TipTap docs
 * so the WYSIWYG can edit them.
 */

test('docFromPlainText builds paragraph nodes from blank-line-separated text', function () {
    expect($this->renderer->docFromPlainText("First.\n\nSecond."))->toBe([
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First.']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second.']]],
        ],
    ]);
});

test('docFromPlainText keeps single newlines as hard breaks', function () {
    expect($this->renderer->docFromPlainText("Line one\nLine two"))->toBe([
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Line one'],
                ['type' => 'hardBreak'],
                ['type' => 'text', 'text' => 'Line two'],
            ]],
        ],
    ]);
});

test('docFromPlainText round-trips through renderValue', function () {
    $text = "One.\n\nTwo\nthree.";
    $doc = $this->renderer->docFromPlainText($text);
    expect($this->renderer->render($doc))->toBe($this->renderer->renderValue($text));
});

test('docFromPlainText returns an empty doc for blank input', function () {
    expect($this->renderer->docFromPlainText('  '))->toBe(['type' => 'doc', 'content' => []]);
});

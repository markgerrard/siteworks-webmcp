<?php

use App\Services\Site\AccentWordRenderer;

beforeEach(fn () => $this->renderer = new AccentWordRenderer);

test('returns escaped title unchanged when accent_word is null', function () {
    expect($this->renderer->wrap('Your Trusted Plumber', null))
        ->toBe('Your Trusted Plumber');
});

test('returns escaped title unchanged when accent_word is empty or whitespace', function () {
    expect($this->renderer->wrap('Your Trusted Plumber', ''))->toBe('Your Trusted Plumber');
    expect($this->renderer->wrap('Your Trusted Plumber', '   '))->toBe('Your Trusted Plumber');
});

test('wraps first occurrence of accent_word in title', function () {
    $html = $this->renderer->wrap('Your Trusted Plumbing Partner', 'Plumbing');

    expect($html)->toBe(
        'Your Trusted <span class="accent-word" style="color: var(--color-accent);">Plumbing</span> Partner'
    );
});

test('wraps only the first occurrence when accent_word appears multiple times', function () {
    $html = $this->renderer->wrap('Plumbing Partners — Plumbing Experts', 'Plumbing');

    expect(substr_count($html, '<span class="accent-word"'))->toBe(1);
    expect($html)->toStartWith('<span class="accent-word"');
});

test('returns escaped title unchanged when accent_word is not a substring', function () {
    $html = $this->renderer->wrap('Your Trusted Plumber', 'Electrician');

    expect($html)->toBe('Your Trusted Plumber');
    expect($html)->not->toContain('<span');
});

test('case-insensitive match picks up any casing of accent_word in the title', function () {
    $html = $this->renderer->wrap('PLUMBING Services', 'plumbing');

    expect($html)->toContain('<span class="accent-word"');
    // Preserves the title's original casing inside the span
    expect($html)->toContain('>PLUMBING<');
});

test('escapes a title that contains HTML/script tags', function () {
    $malicious = '<script>alert("xss")</script> Plumbing';
    $html = $this->renderer->wrap($malicious, 'Plumbing');

    expect($html)->not->toContain('<script>');
    expect($html)->toContain('&lt;script&gt;');
    expect($html)->toContain('<span class="accent-word"');
});

test('accent_word that contains HTML is escaped before matching (no injection)', function () {
    // Both title and accent_word contain raw HTML — e() escapes both, so the
    // escaped forms match and the wrapped fragment stays entity-encoded.
    $html = $this->renderer->wrap('Welcome to <b>bold</b>', '<b>');

    expect($html)->not->toContain('<b>');
    expect($html)->toContain('<span class="accent-word"');
    expect($html)->toContain('&lt;b&gt;');
});

test('italic style adds font-style on the accent span and nothing else', function () {
    $r = app(\App\Services\Site\AccentWordRenderer::class);
    expect($r->wrap('Roofing you can trust', 'trust', 'italic'))
        ->toBe('Roofing you can <span class="accent-word" style="color: var(--color-accent); font-style: italic;">trust</span>');
    expect($r->wrap('Roofing you can trust', 'trust', null))->toBe($r->wrap('Roofing you can trust', 'trust'));
});

test('accent_ranges slice the raw title and escape each slice', function () {
    $title = '<script>alert(1)</script>';
    $html = $this->renderer->wrap($title, null, null, [
        ['start' => 0, 'length' => mb_strlen('<script>')],
    ]);

    expect($html)->not->toContain('<script>');
    expect($html)->toStartWith('<span class="accent-word" style="color: var(--color-accent);">&lt;script&gt;</span>');
    expect($html)->toContain('alert(1)&lt;/script&gt;');
});

test('accent_ranges wrapping an ampersand does not slice the escaped entity', function () {
    $html = $this->renderer->wrap('A & B', null, null, [
        ['start' => 2, 'length' => 1],
    ]);

    expect($html)->toBe('A <span class="accent-word" style="color: var(--color-accent);">&amp;</span> B');
    expect($html)->not->toContain('> & <');
});

test('absent accent_ranges keep accent_word wrapping byte-identical', function () {
    $withNull = $this->renderer->wrap('Your Trusted Plumbing Partner', 'Plumbing', null, null);
    $without = $this->renderer->wrap('Your Trusted Plumbing Partner', 'Plumbing');

    expect($withNull)->toBe($without)
        ->and($without)->toBe(
            'Your Trusted <span class="accent-word" style="color: var(--color-accent);">Plumbing</span> Partner'
        );
});

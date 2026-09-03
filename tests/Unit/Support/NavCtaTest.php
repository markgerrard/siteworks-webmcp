<?php

use App\Support\NavCta;

test('accepts single-slash relative, https host, and tel', function (?string $in, ?string $out) {
    expect(NavCta::safeUrl($in))->toBe($out);
})->with([
    ['/contact', '/contact'],
    ['/quote?x=1', '/quote?x=1'],
    ['https://example.co.uk/book', 'https://example.co.uk/book'],
    ['https://calendly.com/foo/bar?x=1#y', 'https://calendly.com/foo/bar?x=1#y'],
    ['HTTPS://Example.com', 'HTTPS://Example.com'],
    ['https:example.com', null],
    ['tel:+44 1234 567890', 'tel:+44 1234 567890'],
    ['//evil.example/x', null],
    ['/\\evil.example', null],
    ["/contact\x00", null],
    ['javascript:alert(1)', null],
    ['data:text/html,x', null],
    ['mailto:a@b.c', null],
    ['https://user:pw@example.com/', null],
    ['http://example.com', null],
    ['', null],
    [null, null],
    // Binding: https host charset + widened tail.
    ['https://exa"mple.com/', null],
    ["https://exa'mple.com/", null],
    ['https://exa<mple.com/', null],
    ['https://example.com"onmouseover=alert(1)', null],
    ['https://ex ample.com/', null],
    ['https://[::1]/x', null],
    ['https://[::1]:8080/x', null],
    ['https://ex'."\u{0430}".'mple.com/', null],
    ['https://example.com%40evil.example/', null],
    ['https://exa_mple.com/', null],
    ['https://éxample.com/', null],
    ["/contact\u{202E}", null],
    ["/contact\u{200B}", null],
    ['https://example.com:8080/', 'https://example.com:8080/'],
    ['https://example.com?x=1', 'https://example.com?x=1'],
    ['https://example.com#x', 'https://example.com#x'],
    ['https://example.com', 'https://example.com'],
    ['https://book.example.com?ref=nav', 'https://book.example.com?ref=nav'],
    ['https://example.com:8443/x', 'https://example.com:8443/x'],
    ['https://xn--e1awd7f.com/', 'https://xn--e1awd7f.com/'],
    // Unicode format/bidi in https and relative hrefs.
    ["https://example.com/\u{202E}moc.live", null],
    ["https://example.com/\u{200B}x", null],
    ["/contact\u{200E}", null],
    // Arm-specific pins: backslash, userinfo, empty host, length, trim, tel.
    ['https://example.com\\@evil.example/', null],
    ['https://example.com\\evil/', null],
    ['https://@evil.example/', null],
    ['https://user@example.com/', null],
    ['https:/\\evil.example', null],
    ['https:\\\\evil.example', null],
    ['https:///path', null],
    ['https://', null],
    ['/'.str_repeat('a', 255), null],
    ['/'.str_repeat('a', 254), '/'.str_repeat('a', 254)],
    ["\n//evil.example", null],
    ['  /contact  ', '/contact'],
    ['tel:*611', null],
    ['tel:+441234567890;ext=9', null],
    ['tel:12345', null],
]);

test('invalid UTF-8 fails closed', function () {
    expect(NavCta::safeUrl("/contact\xC3\x28"))->toBeNull();
});

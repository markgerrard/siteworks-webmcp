<?php

use Illuminate\Support\Facades\Blade;

function renderShopComponent(string $blade): string
{
    return Blade::render($blade);
}

test('price uses tabular numerals and a small-caps inc. VAT suffix on one line', function () {
    $html = renderShopComponent('<x-shop.price amount="£45.00" />');

    expect($html)->toContain('£45.00')
        ->and($html)->toContain('inc. VAT')
        ->and($html)->toContain('tabular-nums')
        ->and($html)->toContain('small-caps')
        ->and($html)->not->toMatch('/inc\.\s*VAT.*<(br|div|p)\b/is')
        ->and($html)->not->toMatch('/<(br|div|p)\b[^>]*>.*inc\.\s*VAT/is');
});

test('stock pill states produce distinct text and never a traffic light', function () {
    $in = renderShopComponent('<x-shop.stock-pill state="in" />');
    $low = renderShopComponent('<x-shop.stock-pill state="low" :remaining="3" />');
    $out = renderShopComponent('<x-shop.stock-pill state="out" />');

    $inText = trim(html_entity_decode(strip_tags($in), ENT_QUOTES | ENT_HTML5));
    $lowText = trim(html_entity_decode(strip_tags($low), ENT_QUOTES | ENT_HTML5));
    $outText = trim(html_entity_decode(strip_tags($out), ENT_QUOTES | ENT_HTML5));

    expect($inText)->toBe('In stock')
        ->and($lowText)->toBe('Only 3 left')
        ->and($outText)->toBe('Out of stock')
        ->and([$inText, $lowText, $outText])->toHaveCount(3)
        ->and($inText)->not->toBe($lowText)
        ->and($lowText)->not->toBe($outText)
        ->and($in)->toContain('--color-text-muted')
        ->and($low)->toContain('--color-accent-text')
        ->and($out)->toContain('--color-accent-text')
        ->and($in.$low.$out)->not->toMatch('/\b(?:text|bg)-(?:red|green)-\d{2,3}\b/');
});

test('qty stepper exposes a real name=qty input that works without JS', function () {
    $html = renderShopComponent('<x-shop.qty-stepper />');

    expect($html)->toMatch('/<input\b[^>]*\bname="qty"/i')
        ->and($html)->toMatch('/<input\b[^>]*\binputmode="numeric"/i')
        ->and($html)->not->toMatch('/<input\b[^>]*\bname="qty"[^>]*\btype="hidden"/i');

    preg_match('/<input\b[^>]*\bname="qty"[^>]*>/i', $html, $input);
    expect($input)->not->toBeEmpty();
    expect($input[0])->not->toContain('disabled');

    expect($html)->toContain('min-width: 44px')
        ->and($html)->toContain('min-height: 44px');
});

test('checkout steps number Cart, Details and Payment with the current step marked', function () {
    $html = renderShopComponent('<x-shop.steps current="details" />');

    preg_match('/<ol\b[^>]*>(.*?)<\/ol>/is', $html, $ol);
    expect($ol)->not->toBeEmpty();

    preg_match_all('/<li\b([^>]*)>(.*?)<\/li>/is', $ol[1], $items, PREG_SET_ORDER);
    $labels = array_map(
        fn (array $item): string => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($item[2]), ENT_QUOTES | ENT_HTML5))),
        $items,
    );

    expect($labels)->toHaveCount(3);
    expect($labels[0])->toMatch('/1\s*Cart/')
        ->and($labels[1])->toMatch('/2\s*Details/')
        ->and($labels[2])->toMatch('/3\s*Payment/');

    expect($items[1][1].$items[1][2])->toContain('aria-current="step"');
    expect($items[0][2])->toMatch('/<a\b[^>]*href="[^"]*\/shop\/cart"/i');
    expect($items[1][2])->not->toMatch('/<a\b/i');
    expect($items[2][2])->not->toMatch('/<a\b/i');
});

test('status pill uses surface-alt tokens for order lifecycle states', function () {
    $html = renderShopComponent('<x-shop.status-pill status="paid" />');

    expect(trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)))->toBe('Paid')
        ->and($html)->toContain('--color-surface-alt')
        ->and($html)->toContain('--color-text-on-alt')
        ->and($html)->not->toMatch('/\b(?:text|bg)-(?:red|green|blue)-\d{2,3}\b/');
});

test('empty state is one sentence and one action with no illustration', function () {
    $html = renderShopComponent('<x-shop.empty-state />');

    expect($html)->toContain('Your cart is empty.')
        ->and($html)->toContain('Browse the shop')
        ->and($html)->toMatch('/<(?:a|button)\b[^>]*>/i')
        ->and($html)->not->toMatch('/<(?:img|svg)\b/i')
        ->and(strtolower($html))->not->toContain('sorry')
        ->and(strtolower($html))->not->toContain('apolog');

    preg_match_all('/<(?:a|button)\b/i', $html, $actions);
    expect($actions[0])->toHaveCount(1);
});
